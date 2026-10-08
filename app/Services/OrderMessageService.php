<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderEvent;
use App\Models\App\OrderMessage;
use App\Models\App\WhatsappConnection;
use App\Support\OrderActor;
use Illuminate\Support\Facades\DB;

/**
 * Comunicações com o cliente ligadas à OS. Grava a entidade em order_messages e o fato
 * operacional (message_sent / message_failed) em order_events. O corpo da mensagem não é
 * armazenado: o conteúdo é dado pessoal e já é reproduzível pelo modelo + dados da OS.
 */
class OrderMessageService
{
    /** Modelos cujo envio leva o orçamento ao cliente: a mensagem é ligada à versão vigente. */
    public const BUDGET_TEMPLATES = ['generatedbudget', 'budget_follow_up', 'budget_generated'];

    public const WHATSAPP_TEMPLATES = [
        'generatedbudget',
        'servicecompleted',
        'defaultmessage',
        'feedback',
        'budget_follow_up',
        'pending_payment',
    ];

    public function __construct(private readonly OrderEventRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $providerResponse  resposta do provedor (só o id é guardado)
     */
    public function recordSent(Order $order, string $channel, ?string $recipient, ?string $template, string $provider, array $providerResponse = [], ?int $createdBy = null): OrderMessage
    {
        return $this->record($order, $channel, $recipient, $template, $provider, OrderMessage::STATUS_SENT, $createdBy, [
            'provider_message_id' => self::providerMessageId($providerResponse),
            'sent_at' => now(),
        ]);
    }

    public function recordFailed(Order $order, string $channel, ?string $recipient, ?string $template, string $provider, string $errorCode, string $errorMessage, ?int $createdBy = null): OrderMessage
    {
        return $this->record($order, $channel, $recipient, $template, $provider, OrderMessage::STATUS_FAILED, $createdBy, [
            'failed_at' => now(),
            'error_code' => mb_substr($errorCode, 0, 50),
            'error_message' => mb_substr($errorMessage, 0, 255),
        ]);
    }

    /**
     * ACK do WAHA (webhook message.ack). O tenant é resolvido pela sessão do WhatsApp e a
     * mensagem só é atualizada dentro dele. Status nunca regride.
     */
    public function applyWahaAck(string $sessionName, string $providerMessageId, int $ack): ?OrderMessage
    {
        $tenantId = WhatsappConnection::withoutGlobalScopes()->where('session_name', $sessionName)->value('tenant_id');

        if (! $tenantId || $providerMessageId === '') {
            return null;
        }

        return DB::transaction(function () use ($tenantId, $providerMessageId, $ack): ?OrderMessage {
            $message = OrderMessage::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('provider', 'waha')
                ->where(fn ($query) => $this->matchProviderId($query, $providerMessageId))
                ->lockForUpdate()
                ->first();

            if (! $message) {
                return null;
            }

            $now = now();
            // WAHA: -1 erro, 0 pendente, 1 servidor, 2 aparelho, 3 lida, 4 reproduzida.
            if ($ack < 0) {
                if ($message->status !== OrderMessage::STATUS_READ && $message->status !== OrderMessage::STATUS_DELIVERED) {
                    $message->forceFill(['status' => OrderMessage::STATUS_FAILED, 'failed_at' => $now, 'error_code' => 'waha_ack_error'])->save();
                }

                return $message;
            }

            $next = match (true) {
                $ack >= 3 => OrderMessage::STATUS_READ,
                $ack === 2 => OrderMessage::STATUS_DELIVERED,
                default => null,
            };

            if ($next && (OrderMessage::STATUS_RANK[$next] ?? 0) > (OrderMessage::STATUS_RANK[$message->status] ?? 0)) {
                $message->forceFill([
                    'status' => $next,
                    'delivered_at' => $message->delivered_at ?? $now,
                    'read_at' => $next === OrderMessage::STATUS_READ ? ($message->read_at ?? $now) : $message->read_at,
                ])->save();
            }

            return $message;
        });
    }

    /**
     * O id do ACK nem sempre tem o mesmo formato do id gravado no envio: no engine NOWEB a
     * resposta do envio traz o id curto (key.id) e o ACK o id serializado
     * ("true_<chat>_<id>"). Casa por id exato, pelo id curto do ACK ou por id serializado
     * gravado que termine com o id recebido — sempre dentro do tenant da sessão.
     */
    private function matchProviderId($query, string $providerMessageId): void
    {
        $segments = explode('_', $providerMessageId);
        $shortId = end($segments) ?: $providerMessageId;
        // ESCAPE explícito: o SQLite não tem caractere de escape padrão no LIKE.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $providerMessageId);

        $query->whereIn('provider_message_id', array_values(array_unique([$providerMessageId, $shortId])))
            ->orWhereRaw("provider_message_id LIKE ? ESCAPE '!'", ['%!_'.$escaped]);
    }

    /**
     * Extrai o id do provedor das respostas do WAHA (WEBJS: id._serialized; NOWEB: key.id ou id).
     *
     * @param  array<string, mixed>  $response
     */
    public static function providerMessageId(array $response): ?string
    {
        $id = $response['id'] ?? null;

        if (is_array($id)) {
            $id = $id['_serialized'] ?? $id['id'] ?? null;
        }

        $id ??= $response['key']['id'] ?? null;

        return is_string($id) && $id !== '' ? mb_substr($id, 0, 191) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Order $order, string $channel, ?string $recipient, ?string $template, string $provider, string $status, ?int $createdBy, array $attributes): OrderMessage
    {
        $budget = $this->budgetForTemplate($order, $template);

        $message = OrderMessage::create([
            ...$attributes,
            'tenant_id' => OrderEventRecorder::tenantOf($order),
            'order_id' => $order->id,
            'order_budget_id' => $budget?->id,
            'channel' => $channel,
            'direction' => OrderMessage::DIRECTION_OUTBOUND,
            'recipient' => $recipient ? mb_substr($recipient, 0, 120) : null,
            'template' => $template,
            'provider' => $provider,
            'status' => $status,
            'created_by' => $createdBy,
        ]);

        $this->recorder->record(
            $order,
            $status === OrderMessage::STATUS_FAILED ? OrderEvent::TYPE_MESSAGE_FAILED : OrderEvent::TYPE_MESSAGE_SENT,
            OrderActor::userOrSystem($createdBy),
            [],
            array_filter([
                'message_id' => $message->id,
                'channel' => $channel,
                'template' => $template,
                'provider' => $provider,
                'budget_id' => $budget?->id,
                'budget_version' => $budget?->version,
                'error_code' => $attributes['error_code'] ?? null,
            ], fn ($value) => $value !== null),
        );

        return $message;
    }

    private function budgetForTemplate(Order $order, ?string $template): ?OrderBudget
    {
        if (! in_array($template, self::BUDGET_TEMPLATES, true)) {
            return null;
        }

        // A versão que o cliente tem em mãos: a mais recente já liberada (enviada ou respondida).
        return OrderBudget::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->whereNotNull('sent_at')
            ->orderByDesc('version')
            ->first()
            ?? OrderBudget::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->where('status', OrderBudget::STATUS_SENT)
                ->orderByDesc('version')
                ->first();
    }
}
