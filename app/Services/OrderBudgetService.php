<?php

namespace App\Services;

use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderBudgetItem;
use App\Models\App\OrderEvent;
use App\Models\App\OrderItem;
use App\Support\OrderActor;
use App\Support\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orçamento versionado da OS.
 *
 * Conteúdo de uma versão = o que o cliente vê e aprova (descrição, valor orçado, link e
 * validade) + snapshot da composição da OS (subtotais, desconto, acréscimo e itens).
 *
 * Regras:
 * - rascunho (draft) é atualizado no lugar até ser enviado;
 * - "enviar" = liberar ao cliente: acontece quando a OS entra em "Orçamento Gerado";
 * - versão enviada é imutável: mudar descrição, valor, link ou validade cria nova versão
 *   e a anterior vira "superseded";
 * - aprovação/recusa sempre apontam para uma versão; só uma versão fica aprovada;
 * - expiração é derivada da validade (OrderBudget::isExpired) e persistida no primeiro
 *   momento em que o domínio a encontra (aprovação, recusa, nova versão).
 */
class OrderBudgetService
{
    public const CHANNEL_PUBLIC = 'public_tracking';

    public const CHANNEL_INTERNAL = 'internal';

    public function __construct(
        private readonly OrderEventRecorder $recorder,
        private readonly OrderTotalsService $totals,
    ) {}

    public function current(Order $order, bool $lock = false): ?OrderBudget
    {
        $query = OrderBudget::withoutGlobalScopes()->where('order_id', $order->id)->orderByDesc('version');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Sincroniza o orçamento com a OS salva pela tela interna. Deve rodar dentro da
     * transação do salvamento, depois dos itens/totais e da transição de status.
     *
     * @param  int|null  $oldStatus  null na criação da OS
     * @param  string|null|false  $validUntil  false = não informado (mantém a validade atual)
     */
    public function syncFromOrder(Order $order, ?int $oldStatus, int $newStatus, OrderActor $actor, string|null|false $validUntil = false, ?string $reason = null): ?OrderBudget
    {
        return DB::transaction(function () use ($order, $oldStatus, $newStatus, $actor, $validUntil, $reason): ?OrderBudget {
            $current = $this->current($order, lock: true);
            $content = $this->contentFromOrder($order, $validUntil === false ? $current?->valid_until?->toDateString() : $validUntil);
            $hasContent = filled($content['description']) || (float) $content['quoted_amount'] > 0;

            if ($hasContent) {
                if (! $current) {
                    $current = $this->createVersion($order, $content, $actor);
                } elseif ($current->status === OrderBudget::STATUS_DRAFT) {
                    $this->updateDraft($order, $current, $content);
                } elseif ($this->contentChanged($current, $content)) {
                    $this->expireIfDue($order, $current);
                    if ($current->status === OrderBudget::STATUS_SENT) {
                        $this->supersede($order, $current, $actor);
                    }
                    $current = $this->createVersion($order, $content, $actor, $current);
                }
            }

            if (! $current) {
                return null;
            }

            if ($newStatus === OrderStatus::BUDGET_GENERATED) {
                $respondedStatuses = [OrderBudget::STATUS_APPROVED, OrderBudget::STATUS_REJECTED, OrderBudget::STATUS_EXPIRED, OrderBudget::STATUS_LEGACY];

                if ($oldStatus !== $newStatus && in_array($current->status, $respondedStatuses, true)) {
                    // Reenvio após resposta: nova versão com o conteúdo atual.
                    $current = $this->createVersion($order, $content, $actor, $current);
                }

                // Com a OS em "Orçamento Gerado" a área pública mostra o orçamento ao vivo:
                // qualquer versão em rascunho já está diante do cliente, logo é enviada.
                if ($current->status === OrderBudget::STATUS_DRAFT) {
                    $this->send($order, $current, $actor);
                }

                return $current;
            }

            if ($oldStatus === $newStatus) {
                return $current;
            }

            if ($newStatus === OrderStatus::BUDGET_APPROVED) {
                $this->approve($order, $current, $actor, self::CHANNEL_INTERNAL, internal: true);
            } elseif ($newStatus === OrderStatus::BUDGET_REJECTED) {
                $this->reject($order, $current, $actor, self::CHANNEL_INTERNAL, $reason, internal: true);
            }

            return $current;
        });
    }

    /**
     * Resposta do cliente pela área pública: precisa apontar a versão corrente e enviada.
     * Retorna null quando a OS não tem orçamento versionado (comportamento anterior).
     */
    public function respondAsCustomer(Order $order, ?int $version, bool $approve, ?string $reason = null): ?OrderBudget
    {
        return DB::transaction(function () use ($order, $version, $approve, $reason): ?OrderBudget {
            Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current = $this->current($order, lock: true);

            if (! $current) {
                return null;
            }

            if ($version === null || $version !== (int) $current->version) {
                throw ValidationException::withMessages([
                    'status' => 'Este orçamento foi atualizado. Recarregue a página para ver a versão atual antes de responder.',
                ]);
            }

            if ($current->status === OrderBudget::STATUS_EXPIRED || $current->isExpired()) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Este orçamento venceu em %s. Peça à assistência uma nova versão.', $current->valid_until?->format('d/m/Y')),
                ]);
            }

            if ($current->status !== OrderBudget::STATUS_SENT) {
                throw ValidationException::withMessages([
                    'status' => 'Este orçamento não está mais disponível para aprovação ou reprovação.',
                ]);
            }

            $actor = OrderActor::customer();

            return $approve
                ? $this->approve($order, $current, $actor, self::CHANNEL_PUBLIC)
                : $this->reject($order, $current, $actor, self::CHANNEL_PUBLIC, $reason);
        });
    }

    /**
     * Consolida a expiração da versão corrente em transação própria. Chamado antes de
     * operações que podem ser recusadas (resposta pública), para que a recusa não desfaça
     * o registro da expiração.
     */
    public function expireCurrentIfDue(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $current = $this->current($order, lock: true);

            if ($current) {
                $this->expireIfDue($order, $current);
            }
        });
    }

    /**
     * Persiste a expiração derivada da validade, se ainda não persistida.
     */
    public function expireIfDue(Order $order, OrderBudget $budget): bool
    {
        if ($budget->status !== OrderBudget::STATUS_SENT || ! $budget->isExpired()) {
            return false;
        }

        $budget->forceFill([
            'status' => OrderBudget::STATUS_EXPIRED,
            'expired_at' => $budget->expiresAt(),
        ])->save();

        $this->event($order, OrderEvent::TYPE_BUDGET_EXPIRED, OrderActor::system(), $budget, [
            'valid_until' => $budget->valid_until?->toDateString(),
        ]);

        return true;
    }

    private function approve(Order $order, OrderBudget $budget, OrderActor $actor, string $channel, bool $internal = false): OrderBudget
    {
        if ($budget->status === OrderBudget::STATUS_APPROVED) {
            return $budget;
        }

        if ($this->expireIfDue($order, $budget) || $budget->status === OrderBudget::STATUS_EXPIRED) {
            throw ValidationException::withMessages([
                $internal ? 'service_status' : 'status' => sprintf(
                    'O orçamento v%d venceu em %s. Altere ou reenvie o orçamento para gerar uma nova versão.',
                    $budget->version,
                    $budget->valid_until?->format('d/m/Y')
                ),
            ]);
        }

        $approvable = $internal
            ? [OrderBudget::STATUS_DRAFT, OrderBudget::STATUS_SENT, OrderBudget::STATUS_LEGACY, OrderBudget::STATUS_REJECTED]
            : [OrderBudget::STATUS_SENT];

        if (! in_array($budget->status, $approvable, true)) {
            throw ValidationException::withMessages([
                $internal ? 'service_status' : 'status' => "O orçamento v{$budget->version} não pode ser aprovado (situação: {$budget->status}).",
            ]);
        }

        // Só uma versão aprovada por OS: uma aprovação anterior vira histórica.
        OrderBudget::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('status', OrderBudget::STATUS_APPROVED)
            ->whereKeyNot($budget->id)
            ->get()
            ->each(fn (OrderBudget $previous) => $this->supersede($order, $previous, $actor));

        $now = now();
        $budget->forceFill([
            'status' => OrderBudget::STATUS_APPROVED,
            'approved_at' => $now,
            'responded_at' => $now,
            'response_channel' => $channel,
            'approved_by_type' => $actor->type,
            'approved_by' => $actor->userId,
        ])->save();

        $order->forceFill(['approved_budget_id' => $budget->id])->save();

        $this->event($order, OrderEvent::TYPE_BUDGET_APPROVED, $actor, $budget, ['channel' => $channel]);

        return $budget;
    }

    private function reject(Order $order, OrderBudget $budget, OrderActor $actor, string $channel, ?string $reason, bool $internal = false): OrderBudget
    {
        if ($budget->status === OrderBudget::STATUS_REJECTED) {
            return $budget;
        }

        $this->expireIfDue($order, $budget);

        $rejectable = $internal
            ? [OrderBudget::STATUS_DRAFT, OrderBudget::STATUS_SENT, OrderBudget::STATUS_LEGACY, OrderBudget::STATUS_APPROVED, OrderBudget::STATUS_EXPIRED]
            : [OrderBudget::STATUS_SENT];

        if (! in_array($budget->status, $rejectable, true)) {
            throw ValidationException::withMessages([
                $internal ? 'service_status' : 'status' => "O orçamento v{$budget->version} não pode ser recusado (situação: {$budget->status}).",
            ]);
        }

        if ((int) $order->approved_budget_id === (int) $budget->id) {
            $order->forceFill(['approved_budget_id' => null])->save();
        }

        $now = now();
        $budget->forceFill([
            'status' => OrderBudget::STATUS_REJECTED,
            'rejected_at' => $now,
            'responded_at' => $budget->responded_at ?? $now,
            'response_channel' => $channel,
            'rejected_by_type' => $actor->type,
            'rejected_by' => $actor->userId,
            'rejection_reason' => filled($reason) ? mb_substr(trim($reason), 0, 500) : null,
        ])->save();

        $this->event($order, OrderEvent::TYPE_BUDGET_REJECTED, $actor, $budget, ['channel' => $channel], $budget->rejection_reason);

        return $budget;
    }

    private function send(Order $order, OrderBudget $budget, OrderActor $actor): void
    {
        $budget->forceFill([
            'status' => OrderBudget::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        // Envio = liberação ao cliente na área pública. Mensagens (WhatsApp/e-mail) que
        // levam o orçamento ficam em order_messages, ligadas a esta versão.
        $this->event($order, OrderEvent::TYPE_BUDGET_SENT, $actor, $budget, [
            'channel' => self::CHANNEL_PUBLIC,
            'valid_until' => $budget->valid_until?->toDateString(),
        ]);
    }

    private function supersede(Order $order, OrderBudget $budget, OrderActor $actor): void
    {
        $budget->forceFill([
            'status' => OrderBudget::STATUS_SUPERSEDED,
            'superseded_at' => now(),
        ])->save();

        if ((int) $order->approved_budget_id === (int) $budget->id) {
            $order->forceFill(['approved_budget_id' => null])->save();
        }

        $this->event($order, OrderEvent::TYPE_BUDGET_SUPERSEDED, $actor, $budget);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function createVersion(Order $order, array $content, OrderActor $actor, ?OrderBudget $previous = null): OrderBudget
    {
        $version = (int) OrderBudget::withoutGlobalScopes()->where('order_id', $order->id)->max('version') + 1;

        $budget = OrderBudget::create([
            ...$content,
            'tenant_id' => OrderEventRecorder::tenantOf($order),
            'order_id' => $order->id,
            'version' => $version,
            'status' => OrderBudget::STATUS_DRAFT,
            'created_by' => $actor->userId,
        ]);

        $this->snapshotItems($order, $budget);

        $this->event($order, OrderEvent::TYPE_BUDGET_CREATED, $actor, $budget, array_filter([
            'previous_version' => $previous?->version,
        ], fn ($value) => $value !== null));

        return $budget;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function updateDraft(Order $order, OrderBudget $budget, array $content): void
    {
        $budget->forceFill($content)->save();
        OrderBudgetItem::withoutGlobalScopes()->where('order_budget_id', $budget->id)->delete();
        $this->snapshotItems($order, $budget);
    }

    /**
     * Copia os itens atuais da OS (preço/custo já congelados em order_items).
     * Nunca consulta parts.sale_price.
     */
    private function snapshotItems(Order $order, OrderBudget $budget): void
    {
        OrderItem::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->each(fn (OrderItem $item) => OrderBudgetItem::create([
                'tenant_id' => $budget->tenant_id,
                'order_budget_id' => $budget->id,
                'item_type' => $item->item_type,
                'source_type' => $item->source_type,
                'source_id' => $item->source_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
                'unit_cost' => $item->unit_cost,
                'total_cost' => $item->total_cost,
                'sort_order' => $item->sort_order,
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function contentFromOrder(Order $order, ?string $validUntil): array
    {
        $totals = $this->totals->breakdown($order);

        return [
            'description' => filled($order->budget_description) ? (string) $order->budget_description : null,
            'quoted_amount' => round((float) ($order->budget_value ?? 0), 2),
            'budget_link' => filled($order->budget_link) ? (string) $order->budget_link : null,
            'valid_until' => filled($validUntil) ? Carbon::parse($validUntil)->toDateString() : null,
            'subtotal_services' => $totals['services'],
            'subtotal_parts' => $totals['parts'],
            'manual_parts_value' => $totals['manual_parts'],
            'discount_amount' => $totals['discount'],
            'surcharge_amount' => $totals['surcharge'],
            'total_amount' => $totals['total'],
        ];
    }

    /**
     * Mudança relevante = o que o cliente vê: descrição, valor orçado, link e validade.
     * Itens e totais da OS mudam durante o reparo sem gerar nova versão.
     *
     * @param  array<string, mixed>  $content
     */
    private function contentChanged(OrderBudget $budget, array $content): bool
    {
        return trim((string) $budget->description) !== trim((string) $content['description'])
            || round((float) $budget->quoted_amount, 2) !== round((float) $content['quoted_amount'], 2)
            || trim((string) $budget->budget_link) !== trim((string) $content['budget_link'])
            || $budget->valid_until?->toDateString() !== $content['valid_until'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function event(Order $order, string $type, OrderActor $actor, OrderBudget $budget, array $metadata = [], ?string $reason = null): void
    {
        $this->recorder->record($order, $type, $actor, ['reason' => $reason], [
            'budget_id' => $budget->id,
            'version' => (int) $budget->version,
            'quoted_amount' => $budget->quoted_amount === null ? null : round((float) $budget->quoted_amount, 2),
            ...$metadata,
        ]);
    }
}
