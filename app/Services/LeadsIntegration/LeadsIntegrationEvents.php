<?php

namespace App\Services\LeadsIntegration;

use App\Jobs\SendLeadsIntegrationEvent;
use App\Models\LeadsIntegrationEvent;
use App\Models\LeadsIntegrationSetting;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Grava os eventos de cadastro, teste e assinatura do VetorOS para o VetorLeads (VETOROS-LEADS-INTEGRATION).
 *
 * Datas e estados são os reais do VetorOS: início do teste = criação da conta, fim = expires_at,
 * prorrogação = novo expires_at. Só grava no modo API configurado; no modo manual nada é gravado.
 * Falhas aqui são registradas em log e nunca interrompem cadastro, cobrança ou pagamento: a gravação
 * acontece dentro da transação do fluxo, mas o envio só ocorre depois do commit (job com afterCommit).
 */
class LeadsIntegrationEvents
{
    /** Produto enviado ao VetorLeads; o token de integração identifica o mesmo produto. */
    public const PRODUCT = 'vetoros';

    /** Formato do código de indicação emitido pelo VetorLeads (32 caracteres alfanuméricos). */
    public const REFERRAL_PATTERN = '/^[A-Za-z0-9]{32}$/';

    public function tenantRegistered(Tenant $tenant): void
    {
        $createdAt = $tenant->created_at ?? now();
        $referral = $tenant->leads_referral_code !== null
            ? ['referral' => ['code' => $tenant->leads_referral_code]]
            : [];

        $this->record(LeadsIntegrationEvent::TYPE_REGISTERED, $tenant, $createdAt, $referral);

        if ($this->onTrial($tenant) && $tenant->expires_at !== null) {
            $this->trialStarted($tenant, $createdAt);
        }
    }

    /** Prorrogação ou alteração do vencimento do teste (mesmo início, novo fim). */
    public function trialChanged(Tenant $tenant): void
    {
        if ($this->onTrial($tenant) && $tenant->expires_at !== null) {
            $this->trialStarted($tenant, now());
        }
    }

    /** Assinatura ativada pelo fluxo financeiro efetivo (pagamento confirmado ou renovação aplicada). */
    public function subscriptionActivated(Tenant $tenant): void
    {
        if (LeadsIntegrationEvent::query()->where('tenant_id', $tenant->id)->where('type', LeadsIntegrationEvent::TYPE_SUBSCRIPTION_ACTIVATED)->exists()) {
            return;
        }

        $this->record(LeadsIntegrationEvent::TYPE_SUBSCRIPTION_ACTIVATED, $tenant, now(), [
            'subscription' => ['active' => true],
        ]);
    }

    /** Teste encerrado sem contratação, datado no fim real do teste. */
    public function trialExpired(Tenant $tenant): void
    {
        $endsAt = $tenant->expires_at ?? now();

        $this->record(LeadsIntegrationEvent::TYPE_TRIAL_EXPIRED, $tenant, $endsAt, [
            'trial' => ['ends_at' => $endsAt->toIso8601String()],
            'subscription' => ['active' => false],
        ]);
    }

    private function trialStarted(Tenant $tenant, CarbonInterface $occurredAt): void
    {
        $startedAt = ($tenant->created_at ?? $occurredAt)->toIso8601String();

        $this->record(LeadsIntegrationEvent::TYPE_TRIAL_STARTED, $tenant, $occurredAt, [
            'trial' => [
                'started_at' => $startedAt,
                'ends_at' => $tenant->expires_at->toIso8601String(),
            ],
        ]);
    }

    /** Teste = plano de teste e nenhuma cobrança ainda quitada nem contrato personalizado. */
    public function onTrial(Tenant $tenant): bool
    {
        return $tenant->plan?->isTrial() === true
            && ! $tenant->hasCustomContract()
            && empty($tenant->last_payment_id);
    }

    /** @param  array<string, mixed>  $extra */
    public function record(string $type, Tenant $tenant, CarbonInterface $occurredAt, array $extra = []): ?LeadsIntegrationEvent
    {
        try {
            if (! LeadsIntegrationSetting::current()->apiReady()) {
                return null;
            }

            $occurredAt = $occurredAt->toImmutable()->utc();
            $email = $tenant->email ?: null;
            $eventId = (string) Str::uuid();

            $event = LeadsIntegrationEvent::query()->create([
                'event_id' => $eventId,
                'tenant_id' => $tenant->id,
                'type' => $type,
                'occurred_at' => $occurredAt,
                'payload' => array_merge([
                    'event_id' => $eventId,
                    'type' => $type,
                    'occurred_at' => $occurredAt->toIso8601String(),
                    'product' => self::PRODUCT,
                    'account_id' => $tenant->id,
                    'contact' => [
                        'email' => $email ? mb_strtolower(trim($email)) : null,
                        'whatsapp' => $tenant->whatsapp,
                    ],
                ], $extra),
            ]);

            SendLeadsIntegrationEvent::dispatch($event->id)->afterCommit();

            return $event;
        } catch (Throwable $exception) {
            Log::warning('Evento de acompanhamento do VetorLeads não gravado; o fluxo principal segue.', [
                'type' => $type,
                'tenant_id' => $tenant->id,
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 255),
            ]);

            return null;
        }
    }
}
