<?php

namespace App\Services\Payments;

use App\Jobs\ProcessEfiChargesNotification;
use App\Jobs\ProcessEfiPayment;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\Plan;
use App\Models\Admin\SaasPaymentEvent;
use App\Models\Admin\SaasPaymentSetting;
use App\Models\Admin\SaasRecurringAuthorization;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Services\Commercial\PlanRenewalService;
use App\Services\Commercial\SaasContractService;
use App\Services\Payments\Efi\EfiChargesClient;
use App\Services\Payments\Efi\EfiException;
use App\Services\Payments\Efi\EfiPixClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recebimento das assinaturas SaaS pela Efí Bank, único gateway (VETOR-SAAS-EFI-02/03).
 *
 * Não é outro motor de assinatura: a competência continua sendo a `TenantSubscriptionCharge` do
 * contrato (SaasContractService) ou a renovação do plano (PlanRenewalService). Este serviço só cria
 * e concilia a transação externa (`payments`, gateway `efi`) e aplica o resultado pelo motor existente.
 *
 * Regras de segurança:
 * - O tenant nunca é liberado pela resposta do navegador: toda confirmação é lida da API da Efí.
 * - Uma transação pendente válida do mesmo alvo é reutilizada (inclusive o Pix do próprio Bolix), o
 *   que impede cobrar a mesma competência duas vezes.
 * - Sem Efí homologada não há cobrança externa: a criação é bloqueada com mensagem administrativa e
 *   nunca recorre a outro gateway.
 * - Transição: com pagamento de provedor anterior ainda pendente e válido para a empresa, nada novo é
 *   criado até ele expirar ou ser conciliado (sem pagamento em dobro entre provedores).
 * - Quitado o alvo, as demais transações pendentes dele são removidas/canceladas na Efí.
 * - Pagamento que chega para alvo já quitado ou cancelado vira divergência auditada, nunca quitação dupla.
 */
class SaasPaymentService
{
    private const PIX_EXPIRATION_SECONDS = 86400;

    private const LATE_BILLET_DAYS = 2;

    public function __construct(
        private readonly SaasContractService $contracts,
        private readonly PlanRenewalService $planRenewals,
        private readonly EfiPixClient $pix,
        private readonly EfiChargesClient $charges,
    ) {}

    public function settings(): SaasPaymentSetting
    {
        return SaasPaymentSetting::current();
    }

    /** Efí homologada, configurada e com o meio habilitado. */
    public function available(string $method): bool
    {
        $settings = $this->settings();

        if (! $settings->usesEfi() || ! $settings->methodEnabled($method)) {
            return false;
        }

        return match ($method) {
            SaasPaymentSetting::METHOD_PIX => $settings->pixConfigured(),
            SaasPaymentSetting::METHOD_CARD, SaasPaymentSetting::METHOD_CARD_SUBSCRIPTION => $settings->chargesConfigured() && filled($settings->efi_payee_code),
            default => $settings->chargesConfigured(),
        };
    }

    /**
     * Alvo do pagamento do tenant: com contrato, a cobrança em aberto mais antiga (o plano é recusado);
     * no plano tradicional, o plano pago escolhido.
     */
    public function targetFor(Tenant $tenant, ?int $planId): SaasPaymentTarget
    {
        if ($tenant->hasCustomContract()) {
            if ($planId) {
                throw ValidationException::withMessages(['plan_id' => 'Sua empresa tem contrato personalizado: a troca de plano é feita somente pela ABrasil Sistemas.']);
            }

            $charge = $this->contracts->openChargeFor($tenant);
            if (! $charge) {
                throw ValidationException::withMessages(['charge' => 'Não há cobrança em aberto para pagamento.']);
            }

            return SaasPaymentTarget::forCharge($charge);
        }

        $plan = $planId ? Plan::query()->with('periods')->find($planId) : null;
        if (! $plan || ! $plan->availableForSelfService()) {
            throw ValidationException::withMessages(['plan_id' => 'Selecione um plano pago válido.']);
        }

        return SaasPaymentTarget::forPlan($tenant, $plan);
    }

    // ---- Agendador e fatura ------------------------------------------------

    /**
     * Cobrança externa da competência recém-gerada pelo agendador: boleto/Bolix na Efí. Nada é criado
     * com recorrência no cartão ativa (a Efí já cobra a competência) nem com pagamento anterior ainda
     * pendente. Sem Efí homologada, o bloqueio fica auditado. Falha da Efí não impede a fatura nem
     * altera a cobrança.
     */
    public function prepareCollection(TenantSubscriptionCharge $charge): ?Payment
    {
        if (SaasRecurringAuthorization::activeFor($charge->tenant_id) || $this->legacyPendingFor($charge->tenant_id)) {
            return null;
        }

        if (! $this->available(SaasPaymentSetting::METHOD_BILLET)) {
            // Efí bloqueada (sem configuração/homologação): nunca outro gateway; o motivo fica auditado para o
            // RootAdmin. Boleto só desligado por escolha não é bloqueio: o cliente usa Pix ou cartão na tela.
            if ($reason = $this->settings()->blockedReason()) {
                FiscalAdminAudit::record('saas_payment.collection_blocked', $charge->tenant_id, $charge, ['reason' => $reason]);
            }

            return null;
        }

        try {
            return $this->billet(SaasPaymentTarget::forCharge($charge));
        } catch (\Throwable $exception) {
            FiscalAdminAudit::record('saas_payment.collection_failed', $charge->tenant_id, $charge, [
                'method' => SaasPaymentSetting::METHOD_BILLET,
                'error' => Str::limit($exception->getMessage(), 300, ''),
            ]);

            return null;
        }
    }

    /** Instruções de pagamento da fatura quando a Efí já tem algo para esta competência. */
    public function instructionsFor(TenantSubscriptionCharge $charge): ?string
    {
        if ($authorization = SaasRecurringAuthorization::activeFor($charge->tenant_id)) {
            return 'Cobrança automática no cartão de crédito'.($authorization->card_mask ? ' '.$authorization->card_mask : '').', autorizada pelo titular. Não é necessário pagar esta fatura por outro meio.';
        }

        $payment = Payment::query()
            ->where('gateway', Payment::GATEWAY_EFI)
            ->where('tenant_subscription_charge_id', $charge->id)
            ->where('status', Payment::STATUS_PENDING)
            ->whereIn('method', [SaasPaymentSetting::METHOD_BILLET, SaasPaymentSetting::METHOD_PIX])
            ->latest('id')
            ->first();

        if (! $payment) {
            return null;
        }

        $details = $payment->details ?? [];
        $lines = array_filter([
            filled($details['billet_link'] ?? null) ? 'Boleto: '.$details['billet_link'] : null,
            filled($details['barcode'] ?? null) ? 'Linha digitável: '.$details['barcode'] : null,
            filled($details['pix_copy_paste'] ?? null) ? 'Pix copia e cola: '.$details['pix_copy_paste'] : null,
        ]);

        return $lines === [] ? null : implode("\n", [...$lines, 'Também é possível pagar por Pix, boleto ou cartão no VetorOS, em '.rtrim((string) config('app.url'), '/').'/subscription.']);
    }

    // ---- Pix ---------------------------------------------------------------

    /** Pix da cobrança: reutiliza Pix/Bolix pendente válido; senão cria a cobrança imediata (cob). */
    public function pix(SaasPaymentTarget $target): Payment
    {
        $this->ensureAvailable(SaasPaymentSetting::METHOD_PIX, $target);

        return $this->locked($target, function () use ($target) {
            if ($existing = $this->reusable($target, [SaasPaymentSetting::METHOD_PIX, SaasPaymentSetting::METHOD_BILLET], requirePix: true)) {
                return $existing;
            }

            $this->assertNoPendingPayment($target);

            $txid = 'VOS'.Str::upper(Str::random(29));
            $cob = $this->pix->createImmediateCharge($txid, $target->amount, $this->pixDebtor($target->tenant), self::PIX_EXPIRATION_SECONDS, $target->description);
            $qr = isset($cob['loc']['id']) ? $this->pix->qrCode($cob['loc']['id']) : [];

            return $this->store($target, SaasPaymentSetting::METHOD_PIX, $txid, $txid, [
                'expires_at' => now()->addSeconds(self::PIX_EXPIRATION_SECONDS),
                'details' => [
                    'pix_copy_paste' => $cob['pixCopiaECola'] ?? $qr['qrcode'] ?? null,
                    'pix_qr_image' => $qr['imagemQrcode'] ?? null,
                ],
                'raw_response' => $cob,
            ]);
        });
    }

    // ---- Boleto/Bolix ------------------------------------------------------

    /** Boleto com o vencimento da competência (ou curto, se já vencida), com Pix do Bolix quando a conta devolve. */
    public function billet(SaasPaymentTarget $target): Payment
    {
        $this->ensureAvailable(SaasPaymentSetting::METHOD_BILLET, $target);

        return $this->locked($target, function () use ($target) {
            if ($existing = $this->reusable($target, [SaasPaymentSetting::METHOD_BILLET])) {
                return $existing;
            }

            $this->assertNoPendingPayment($target);

            $expireAt = $target->dueDate->copy()->startOfDay();
            if ($expireAt->lt(now()->startOfDay())) {
                $expireAt = now()->startOfDay()->addDays(self::LATE_BILLET_DAYS);
            }

            $reference = 'VOSB'.Str::upper(Str::random(20));
            $data = $this->charges->createBillet(
                $this->items($target),
                $this->billetCustomer($target->tenant),
                $expireAt->toDateString(),
                $reference,
                $this->chargesNotificationUrl(),
                Str::limit($target->description, 80, ''),
            );

            return $this->store($target, SaasPaymentSetting::METHOD_BILLET, (string) $data['charge_id'], $reference, [
                'due_date' => $expireAt->toDateString(),
                'expires_at' => $expireAt->copy()->endOfDay(),
                'details' => [
                    'barcode' => $data['barcode'] ?? null,
                    'billet_link' => $data['billet_link'] ?? $data['link'] ?? null,
                    'billet_pdf' => $data['pdf']['charge'] ?? null,
                    'pix_copy_paste' => $data['pix']['qrcode'] ?? null,
                    'pix_qr_image' => $data['pix']['qrcode_image'] ?? null,
                ],
                'raw_response' => $data,
            ]);
        });
    }

    // ---- Cartão ------------------------------------------------------------

    /**
     * Cartão avulso: o navegador só envia o `payment_token` (tokenização da Efí) e a máscara.
     * A quitação depende da consulta à API logo depois, nunca do retorno do navegador.
     *
     * @param  array{payment_token: string, card_mask?: ?string, customer: array, billing_address: array}  $card
     */
    public function card(SaasPaymentTarget $target, array $card): Payment
    {
        $this->ensureAvailable(SaasPaymentSetting::METHOD_CARD, $target);

        $payment = $this->locked($target, function () use ($target, $card) {
            if ($this->paidOrInProgress($target)) {
                throw ValidationException::withMessages(['card' => 'Esta cobrança já tem pagamento em andamento.']);
            }

            $reference = 'VOSC'.Str::upper(Str::random(20));
            $data = $this->charges->createCardCharge($this->items($target), $this->cardCustomer($target->tenant, $card['customer']), $card['billing_address'], $card['payment_token'], $reference, $this->chargesNotificationUrl());

            return $this->store($target, SaasPaymentSetting::METHOD_CARD, (string) $data['charge_id'], $reference, [
                'details' => ['card_mask' => $card['card_mask'] ?? null, 'provider_status' => $data['status'] ?? null],
                'raw_response' => $data,
            ]);
        });

        return $this->reconcile($payment);
    }

    /**
     * Recorrência no cartão autorizada pelo titular (assinatura nativa da Efí). A primeira cobrança
     * quita o alvo atual; as seguintes quitam as próximas competências (applySubscriptionCharge).
     */
    public function subscribe(SaasPaymentTarget $target, array $card, ?string $ip, ?int $userId): SaasRecurringAuthorization
    {
        $this->ensureAvailable(SaasPaymentSetting::METHOD_CARD_SUBSCRIPTION, $target);

        if (SaasRecurringAuthorization::activeFor($target->tenant->id)) {
            throw ValidationException::withMessages(['card' => 'Já existe uma recorrência no cartão ativa para esta empresa.']);
        }

        [$authorization, $payment] = $this->locked($target, function () use ($target, $card, $ip, $userId) {
            $planId = $this->efiPlanId($target->intervalMonths());
            $reference = 'VOSS'.Str::upper(Str::random(20));
            $data = $this->charges->createSubscription($planId, $this->items($target), $this->cardCustomer($target->tenant, $card['customer']), $card['billing_address'], $card['payment_token'], $reference, $this->chargesNotificationUrl());

            $authorization = SaasRecurringAuthorization::query()->create([
                'tenant_id' => $target->tenant->id,
                'provider' => SaasPaymentSetting::PROVIDER_EFI,
                'provider_subscription_id' => (string) $data['subscription_id'],
                'provider_plan_id' => (string) $planId,
                'plan_id' => $target->plan?->id,
                'interval_months' => $target->intervalMonths(),
                'amount' => $target->amount,
                'card_mask' => $card['card_mask'] ?? null,
                'status' => in_array($data['status'] ?? 'active', ['canceled', 'expired'], true) ? SaasRecurringAuthorization::STATUS_FAILED : SaasRecurringAuthorization::STATUS_ACTIVE,
                'authorized_at' => now(),
                'authorized_ip' => $ip,
                'authorized_by' => $userId,
            ]);

            FiscalAdminAudit::record('saas_payment.recurring_authorized', $target->tenant->id, $authorization, [
                'subscription_id' => $authorization->provider_subscription_id,
                'amount' => $target->amount,
                'interval_months' => $authorization->interval_months,
                'card_mask' => $authorization->card_mask,
            ]);

            $payment = null;
            if ($chargeId = $data['charge']['id'] ?? null) {
                $payment = $this->store($target, SaasPaymentSetting::METHOD_CARD_SUBSCRIPTION, (string) $chargeId, $reference, [
                    'provider_subscription_id' => $authorization->provider_subscription_id,
                    'details' => ['card_mask' => $authorization->card_mask, 'provider_status' => $data['charge']['status'] ?? null],
                    'raw_response' => $data,
                ]);
            }

            return [$authorization, $payment];
        });

        if ($payment) {
            $this->reconcile($payment);
        }

        return $authorization->refresh();
    }

    public function cancelSubscription(SaasRecurringAuthorization $authorization, string $reason): SaasRecurringAuthorization
    {
        if ($authorization->status !== SaasRecurringAuthorization::STATUS_ACTIVE) {
            return $authorization;
        }

        $this->charges->cancelSubscription($authorization->provider_subscription_id);
        $this->markSubscriptionCancelled($authorization, $reason);

        return $authorization->refresh();
    }

    // ---- Conciliação -------------------------------------------------------

    /** Lê a situação na Efí e aplica a transição (quitação, estorno, expiração, recusa). Idempotente. */
    public function reconcile(Payment $payment): Payment
    {
        if (! $payment->isEfi()) {
            return $payment;
        }

        $state = $payment->method === SaasPaymentSetting::METHOD_PIX ? $this->pixState($payment) : $this->chargeState($payment);
        $payment->forceFill(['last_checked_at' => now()])->save();

        match ($state['status']) {
            Payment::STATUS_PAID => $this->applyPaid($payment, $state['amount'], $state['paid_at'] ?? now()),
            Payment::STATUS_REFUNDED => $this->applyRefund($payment, $state['amount'] ?? (string) $payment->paid_amount, $state['reason'] ?? 'Estorno informado pela Efí.'),
            Payment::STATUS_CANCELLED, Payment::STATUS_EXPIRED, Payment::STATUS_FAILED => $this->closeUnpaid($payment, $state['status'], $state['reason'] ?? null),
            default => $payment->forceFill(['details' => [...($payment->details ?? []), 'provider_status' => $state['provider_status'] ?? null]])->save(),
        };

        return $payment->refresh();
    }

    /** Conciliação periódica das transações pendentes (pagamento atrasado, webhook perdido, falha temporária). */
    public function reconcilePending(): int
    {
        $checked = 0;

        Payment::query()
            ->where('gateway', Payment::GATEWAY_EFI)
            ->where('status', Payment::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes(2))
            ->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinutes(30)))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (Payment $payment) use (&$checked) {
                try {
                    $this->reconcile($payment);
                    $checked++;
                } catch (EfiException $exception) {
                    $payment->forceFill(['last_checked_at' => now(), 'error' => $exception->getMessage()])->save();
                }
            });

        return $checked;
    }

    // ---- Notificações ------------------------------------------------------

    /**
     * Webhook Pix: cada Pix recebido vira um evento único (e2eId). Só txid gerado por esta aplicação
     * é processado (outra aplicação/conta é ignorada); a confirmação é feita por consulta, em fila.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function receivePixNotification(array $items): int
    {
        $this->settings()->forceFill(['last_pix_notification_at' => now()])->save();
        $queued = 0;

        foreach ($items as $item) {
            $txid = (string) ($item['txid'] ?? '');
            $key = (string) ($item['endToEndId'] ?? '');
            if ($key === '') {
                continue;
            }
            foreach ($item['devolucoes'] ?? [] as $refund) {
                $key .= ':'.($refund['id'] ?? '').':'.($refund['status'] ?? '');
            }

            $event = $this->recordEvent('pix', $key);
            if (! $event) {
                continue;
            }

            $payment = $txid === '' ? null : Payment::query()->where('gateway', Payment::GATEWAY_EFI)->where('payment_id', $txid)->first();
            if (! $payment && ($test = app(EfiIntegrationTestService::class)->findByProviderId($txid))) {
                // Teste de integração (VETOROS-EFI-TEST-01): só atualiza o registro de teste.
                app(EfiIntegrationTestService::class)->webhookReceived($test, $event);
                $queued++;

                continue;
            }
            if (! $payment) {
                $event->forceFill(['status' => SaasPaymentEvent::STATUS_IGNORED, 'error' => 'txid desconhecido nesta aplicação.', 'processed_at' => now()])->save();

                continue;
            }

            ProcessEfiPayment::dispatch($payment->id, $event->id);
            $queued++;
        }

        return $queued;
    }

    /** Webhook da API Cobranças: só traz o token; os eventos são lidos da API, em fila. */
    public function receiveChargesNotification(string $token): bool
    {
        $this->settings()->forceFill(['last_charges_notification_at' => now()])->save();

        $event = $this->recordEvent('charges', $token);
        if (! $event) {
            return false;
        }

        ProcessEfiChargesNotification::dispatch($token, $event->id);

        return true;
    }

    /** Processa uma notificação da API Cobranças (chamado pelo job). */
    public function processChargesNotification(string $token): void
    {
        $events = $this->charges->getNotification($token);
        $chargeIds = [];

        foreach ($events as $event) {
            $identifiers = $event['identifiers'] ?? [];
            $subscriptionId = isset($identifiers['subscription_id']) ? (string) $identifiers['subscription_id'] : null;
            $current = $event['status']['current'] ?? null;

            if (($event['type'] ?? null) === 'subscription' && $subscriptionId && in_array($current, ['canceled', 'expired'], true)) {
                if ($authorization = SaasRecurringAuthorization::query()->where('provider_subscription_id', $subscriptionId)->first()) {
                    $this->markSubscriptionCancelled($authorization, 'Assinatura '.$current.' na Efí.');
                }

                continue;
            }

            if (isset($identifiers['charge_id'])) {
                $chargeIds[(string) $identifiers['charge_id']] = $subscriptionId;
            }
        }

        foreach ($chargeIds as $chargeId => $subscriptionId) {
            $payment = Payment::query()->where('gateway', Payment::GATEWAY_EFI)->where('payment_id', $chargeId)->first()
                ?? ($subscriptionId ? $this->subscriptionChargePayment($chargeId, $subscriptionId) : null);

            if (! $payment && ($test = app(EfiIntegrationTestService::class)->findByProviderId($chargeId))) {
                // Teste de integração (VETOROS-EFI-TEST-01): já estamos no job da notificação; concilia só o teste.
                $test->forceFill(['webhook_received_at' => now(), 'webhook_count' => $test->webhook_count + 1])->save();
                app(EfiIntegrationTestService::class)->reconcile($test);

                continue;
            }
            if (! $payment) {
                Log::info('Notificação Efí de cobrança desconhecida ignorada.', ['charge_id' => $chargeId]);

                continue;
            }

            $this->reconcile($payment);
        }
    }

    /** Recorrência: cobrança nova da assinatura nativa ainda sem registro local. */
    private function subscriptionChargePayment(string $chargeId, string $subscriptionId): ?Payment
    {
        $authorization = SaasRecurringAuthorization::query()->where('provider', SaasPaymentSetting::PROVIDER_EFI)->where('provider_subscription_id', $subscriptionId)->first();
        if (! $authorization) {
            return null;
        }

        try {
            return Payment::query()->create([
                'tenant_id' => $authorization->tenant_id,
                'plan_id' => $authorization->plan_id,
                'gateway' => Payment::GATEWAY_EFI,
                'method' => SaasPaymentSetting::METHOD_CARD_SUBSCRIPTION,
                'payment_id' => $chargeId,
                'reference' => 'VOSS'.$chargeId,
                'provider_subscription_id' => $subscriptionId,
                'amount' => $authorization->amount,
                'status' => Payment::STATUS_PENDING,
                'idempotency_key' => 'efi-'.$chargeId,
                'details' => ['card_mask' => $authorization->card_mask],
            ]);
        } catch (QueryException) {
            // Corrida entre notificações: o registro já existe.
            return Payment::query()->where('gateway', Payment::GATEWAY_EFI)->where('payment_id', $chargeId)->first();
        }
    }

    // ---- Aplicação dos resultados -------------------------------------------

    private function applyPaid(Payment $payment, string $amount, Carbon $paidAt): void
    {
        if ($this->toCents($amount) !== $this->toCents((string) $payment->amount)) {
            if (! str_starts_with((string) $payment->error, 'Divergência:')) {
                $this->divergence($payment, 'Valor confirmado pela Efí difere do valor contratado.');
            }

            return;
        }

        $applied = DB::transaction(function () use ($payment, $amount, $paidAt) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (in_array($payment->status, [Payment::STATUS_PAID, Payment::STATUS_REFUNDED], true)) {
                return false;
            }

            $payment->forceFill(['status' => Payment::STATUS_PAID, 'paid_at' => $paidAt, 'paid_amount' => $amount, 'error' => null])->save();

            return true;
        });

        if (! $applied) {
            return;
        }

        $payment->refresh();
        FiscalAdminAudit::record('saas_payment.paid', $payment->tenant_id, $payment, [
            'gateway' => $payment->gateway, 'method' => $payment->method, 'payment_id' => $payment->payment_id, 'amount' => $amount,
        ]);

        try {
            $this->settle($payment, $amount);
        } catch (ValidationException $exception) {
            $this->divergence($payment, 'Pagamento recebido sem competência aberta para quitar: '.collect($exception->errors())->flatten()->implode(' '));

            return;
        }

        $this->cancelSiblings($payment);
    }

    /** Quita a competência local pelo motor existente. */
    private function settle(Payment $payment, string $amount): void
    {
        // Rótulo curto gravado em tenant_subscription_charges.payment_method (até 20 caracteres).
        $method = match ($payment->method) {
            SaasPaymentSetting::METHOD_BILLET => 'efi_boleto',
            SaasPaymentSetting::METHOD_CARD => 'efi_cartao',
            SaasPaymentSetting::METHOD_CARD_SUBSCRIPTION => 'efi_recorrente',
            default => 'efi_pix',
        };

        if ($payment->method === SaasPaymentSetting::METHOD_CARD_SUBSCRIPTION && ! $payment->tenant_subscription_charge_id && ! $payment->plan_id) {
            $charge = $this->nextCompetenceFor($payment->tenant);
            $payment->forceFill(['tenant_subscription_charge_id' => $charge->id])->save();
        }

        if ($payment->tenant_subscription_charge_id) {
            $charge = $payment->subscriptionCharge;
            if ($charge->status !== TenantSubscriptionCharge::STATUS_PENDING && (int) $charge->payment_id !== (int) $payment->id) {
                throw ValidationException::withMessages(['charge' => 'A cobrança '.$charge->id.' está '.$charge->statusLabel().'.']);
            }

            $this->contracts->registerPayment($charge, $amount, $method, $payment);

            return;
        }

        if ($payment->plan_id) {
            $result = $this->planRenewals->renew($payment->tenant_id, $payment->plan, $payment);
            if ($result === PlanRenewalService::SKIPPED_CUSTOM_CONTRACT || $result === PlanRenewalService::TENANT_NOT_FOUND) {
                throw ValidationException::withMessages(['plan_id' => 'Pagamento de plano para empresa com contrato personalizado.']);
            }

            return;
        }

        throw ValidationException::withMessages(['payment' => 'Pagamento sem cobrança ou plano vinculado.']);
    }

    /**
     * Recorrência nativa: a cobrança da Efí quita a competência mais antiga em aberto do contrato; sem
     * competência aberta (a Efí cobrou antes da geração local), gera a próxima e a quita.
     */
    private function nextCompetenceFor(Tenant $tenant): TenantSubscriptionCharge
    {
        if ($open = $this->contracts->openChargeFor($tenant)) {
            return $open;
        }

        $contract = $tenant->commercialContract;
        if (! $contract?->active) {
            throw ValidationException::withMessages(['charge' => 'Empresa sem contrato ativo para a recorrência.']);
        }

        $charge = $this->contracts->generateCharge($contract, $contract->next_due_date->copy());
        if (! $charge) {
            throw ValidationException::withMessages(['charge' => 'Não foi possível gerar a competência da recorrência.']);
        }

        return $charge;
    }

    private function applyRefund(Payment $payment, string $amount, string $reason): void
    {
        $changed = DB::transaction(function () use ($payment, $amount) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === Payment::STATUS_REFUNDED) {
                return false;
            }

            $payment->forceFill(['status' => Payment::STATUS_REFUNDED, 'refunded_amount' => $amount])->save();

            return true;
        });

        if (! $changed) {
            return;
        }

        $payment->refresh();
        FiscalAdminAudit::record('saas_payment.refunded', $payment->tenant_id, $payment, ['payment_id' => $payment->payment_id, 'amount' => $amount, 'reason' => $reason]);

        $charge = $payment->subscriptionCharge;
        if ($charge && $charge->status === TenantSubscriptionCharge::STATUS_PAID && (int) $charge->payment_id === (int) $payment->id) {
            $this->contracts->refund($charge, $reason);
        }
    }

    private function closeUnpaid(Payment $payment, string $status, ?string $reason): void
    {
        if (! $payment->isPending()) {
            return;
        }

        $payment->forceFill(['status' => $status, 'error' => $reason])->save();

        if ($status === Payment::STATUS_FAILED) {
            FiscalAdminAudit::record('saas_payment.failed', $payment->tenant_id, $payment, ['payment_id' => $payment->payment_id, 'method' => $payment->method, 'reason' => $reason]);

            if ($payment->provider_subscription_id
                && ($authorization = SaasRecurringAuthorization::query()->where('provider_subscription_id', $payment->provider_subscription_id)->first())) {
                $authorization->forceFill(['last_failure_at' => now(), 'last_error' => $reason ?? 'Renovação recusada no cartão.'])->save();
            }
        }
    }

    /** Remove/cancela na Efí as outras transações pendentes do mesmo alvo depois da quitação. */
    private function cancelSiblings(Payment $paid): void
    {
        Payment::query()
            ->where('gateway', Payment::GATEWAY_EFI)
            ->where('status', Payment::STATUS_PENDING)
            ->whereKeyNot($paid->id)
            ->where('tenant_id', $paid->tenant_id)
            ->when($paid->tenant_subscription_charge_id,
                fn ($query) => $query->where('tenant_subscription_charge_id', $paid->tenant_subscription_charge_id),
                fn ($query) => $query->whereNull('tenant_subscription_charge_id')->where('plan_id', $paid->plan_id))
            ->get()
            ->each(function (Payment $sibling) {
                try {
                    $sibling->method === SaasPaymentSetting::METHOD_PIX
                        ? $this->pix->removeCharge($sibling->payment_id)
                        : $this->charges->cancelCharge($sibling->payment_id);
                    $sibling->forceFill(['status' => Payment::STATUS_CANCELLED, 'error' => 'Cancelada: cobrança quitada por outro meio.'])->save();
                } catch (EfiException $exception) {
                    // Se falhar, a conciliação periódica trata um eventual pagamento duplo como divergência.
                    $sibling->forceFill(['error' => $exception->getMessage()])->save();
                }
            });
    }

    private function divergence(Payment $payment, string $message): void
    {
        $payment->forceFill(['error' => Str::limit('Divergência: '.$message, 500, '')])->save();
        FiscalAdminAudit::record('saas_payment.divergence', $payment->tenant_id, $payment, [
            'payment_id' => $payment->payment_id, 'method' => $payment->method, 'amount' => (string) $payment->paid_amount, 'message' => $message,
        ]);
        Log::warning('Divergência de pagamento SaaS na Efí.', ['payment_id' => $payment->id]);
    }

    /** Devolução administrativa de um Pix recebido em divergência (pagamento em dobro). */
    public function refundPix(Payment $payment, ?int $userId): Payment
    {
        if (! $payment->isEfi() || $payment->method !== SaasPaymentSetting::METHOD_PIX || $payment->status !== Payment::STATUS_PAID) {
            throw ValidationException::withMessages(['payment' => 'Só Pix recebido pela Efí pode ser devolvido por aqui.']);
        }

        $endToEndId = $payment->details['end_to_end_id'] ?? null;
        if (! $endToEndId) {
            throw ValidationException::withMessages(['payment' => 'Pix sem identificador end-to-end; consulte a Efí.']);
        }

        $this->pix->refund($endToEndId, 'D'.$payment->id.Str::upper(Str::random(8)), number_format((float) $payment->paid_amount, 2, '.', ''));
        FiscalAdminAudit::record('saas_payment.refund_requested', $payment->tenant_id, $payment, ['payment_id' => $payment->payment_id, 'by' => $userId]);

        return $this->reconcile($payment);
    }

    // ---- Leitura do provedor -------------------------------------------------

    /** @return array{status: string, amount?: string, paid_at?: Carbon, reason?: string, provider_status?: string} */
    private function pixState(Payment $payment): array
    {
        $cob = $this->pix->getCharge($payment->payment_id);
        $status = (string) ($cob['status'] ?? '');
        $received = collect($cob['pix'] ?? []);

        if ($status === 'CONCLUIDA' || $received->isNotEmpty()) {
            $paidCents = $received->sum(fn ($pix) => $this->toCents((string) ($pix['valor'] ?? '0')));
            $refundedCents = $received->flatMap(fn ($pix) => $pix['devolucoes'] ?? [])
                ->where('status', 'DEVOLVIDO')
                ->sum(fn ($refund) => $this->toCents((string) ($refund['valor'] ?? '0')));

            $first = $received->first();
            if ($first && empty($payment->details['end_to_end_id'])) {
                $payment->forceFill(['details' => [...($payment->details ?? []), 'end_to_end_id' => $first['endToEndId'] ?? null]])->save();
            }

            if ($paidCents > 0 && $refundedCents >= $paidCents) {
                return ['status' => Payment::STATUS_REFUNDED, 'amount' => $this->fromCents($refundedCents), 'reason' => 'Pix devolvido.'];
            }

            return [
                'status' => Payment::STATUS_PAID,
                'amount' => $this->fromCents($paidCents),
                'paid_at' => isset($first['horario']) ? Carbon::parse($first['horario']) : now(),
            ];
        }

        if (str_starts_with($status, 'REMOVIDA')) {
            return ['status' => Payment::STATUS_CANCELLED, 'reason' => $status];
        }

        if ($payment->expires_at && $payment->expires_at->isPast()) {
            return ['status' => Payment::STATUS_EXPIRED, 'reason' => 'Pix expirado sem pagamento.'];
        }

        return ['status' => Payment::STATUS_PENDING, 'provider_status' => $status];
    }

    /** @return array{status: string, amount?: string, paid_at?: Carbon, reason?: string, provider_status?: string} */
    private function chargeState(Payment $payment): array
    {
        $data = $this->charges->getCharge($payment->payment_id);
        $status = (string) ($data['status'] ?? '');
        $total = (int) ($data['paid_value'] ?? $data['total'] ?? 0);

        return match ($status) {
            'paid', 'settled' => ['status' => Payment::STATUS_PAID, 'amount' => $this->fromCents($total), 'paid_at' => now()],
            'refunded', 'contested' => ['status' => Payment::STATUS_REFUNDED, 'amount' => $this->fromCents($total), 'reason' => $status === 'contested' ? 'Pagamento contestado no cartão.' : 'Estorno informado pela Efí.'],
            'canceled' => ['status' => Payment::STATUS_CANCELLED, 'reason' => 'Cancelada na Efí.'],
            'expired' => ['status' => Payment::STATUS_EXPIRED, 'reason' => 'Boleto vencido sem pagamento.'],
            'unpaid' => ['status' => Payment::STATUS_FAILED, 'reason' => $payment->method === SaasPaymentSetting::METHOD_BILLET ? 'Boleto não pago.' : 'Pagamento recusado no cartão.'],
            default => ['status' => Payment::STATUS_PENDING, 'provider_status' => $status],
        };
    }

    // ---- Apoio ---------------------------------------------------------------

    /**
     * Bloqueia a cobrança externa sem a Efí homologada (mensagem administrativa, nunca outro gateway) e,
     * na transição, enquanto houver pagamento de provedor anterior pendente e válido para a empresa.
     */
    private function ensureAvailable(string $method, ?SaasPaymentTarget $target = null): void
    {
        if (! $this->available($method)) {
            throw ValidationException::withMessages(['method' => $this->settings()->blockedReason()
                ? 'Pagamento online indisponível no momento. Fale com o atendimento da ABrasil Sistemas.'
                : 'Meio de pagamento indisponível no momento.']);
        }

        if ($target && ($legacy = $this->legacyPendingFor($target->tenant->id))) {
            throw ValidationException::withMessages(['payment' => 'Há um pagamento anterior ainda válido até '
                .$legacy->expires_at->format('d/m/Y H:i').'. Aguarde o vencimento dele ou fale com o atendimento, para não pagar em dobro.']);
        }
    }

    /**
     * Pagamento de provedor anterior ainda pendente e dentro da validade. Ele não é recriado na Efí
     * nem cancelado aqui: o procedimento de transição o encerra ou concilia (vetoros:legacy-payments-report).
     */
    public function legacyPendingFor(int $tenantId): ?Payment
    {
        return Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('gateway', '!=', Payment::GATEWAY_EFI)
            ->whereIn('status', ['pending', 'in_process', 'in_mediation', 'authorized'])
            ->where('expires_at', '>', now())
            ->latest('expires_at')
            ->first();
    }

    private function locked(SaasPaymentTarget $target, callable $callback): mixed
    {
        return Cache::lock($target->lockKey(), 30)->block(10, function () use ($target, $callback) {
            if ($target->plan) {
                $other = Payment::query()->where('tenant_id', $target->tenant->id)
                    ->whereNull('tenant_subscription_charge_id')->where('gateway', Payment::GATEWAY_EFI)
                    ->where('status', Payment::STATUS_PENDING)->where('plan_id', '!=', $target->plan->id)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
                if ($other) {
                    throw ValidationException::withMessages(['plan_id' => 'Há um pagamento de outro plano aguardando confirmação. Consulte ou aguarde o vencimento antes de trocar de plano.']);
                }
            }

            return $callback();
        });
    }

    /** Transação Efí pendente e válida do mesmo alvo e valor. `requirePix`: o boleto só serve se trouxer Pix (Bolix). */
    private function reusable(SaasPaymentTarget $target, array $methods, bool $requirePix = false): ?Payment
    {
        return $this->pendingFor($target)
            ->where('gateway', Payment::GATEWAY_EFI)
            ->whereIn('method', $methods)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinutes(10)))
            ->latest('id')
            ->get()
            ->first(fn (Payment $payment) => $this->toCents((string) $payment->amount) === $target->cents()
                && (! $requirePix || filled($payment->details['pix_copy_paste'] ?? null)));
    }

    private function paidOrInProgress(SaasPaymentTarget $target): bool
    {
        return $target->charge && $target->charge->refresh()->status !== TenantSubscriptionCharge::STATUS_PENDING;
    }

    private function pendingFor(SaasPaymentTarget $target)
    {
        return Payment::query()
            ->where('tenant_id', $target->tenant->id)
            ->where('status', Payment::STATUS_PENDING)
            ->when($target->charge,
                fn ($query) => $query->where('tenant_subscription_charge_id', $target->charge->id),
                fn ($query) => $query->whereNull('tenant_subscription_charge_id')->where('plan_id', $target->plan->id));
    }

    private function assertNoPendingPayment(SaasPaymentTarget $target): void
    {
        if ($this->pendingFor($target)->where('gateway', Payment::GATEWAY_EFI)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            throw ValidationException::withMessages(['payment' => 'Já existe um pagamento aguardando confirmação. Consulte essa cobrança antes de gerar outra.']);
        }
    }

    private function store(SaasPaymentTarget $target, string $method, string $providerId, string $reference, array $extra): Payment
    {
        $payment = Payment::query()->create([
            'tenant_id' => $target->tenant->id,
            'tenant_subscription_charge_id' => $target->charge?->id,
            'plan_id' => $target->plan?->id,
            'gateway' => Payment::GATEWAY_EFI,
            'method' => $method,
            'payment_id' => $providerId,
            'reference' => $reference,
            'amount' => $target->amount,
            'status' => Payment::STATUS_PENDING,
            'idempotency_key' => 'efi-'.$reference,
            'due_date' => $target->dueDate->toDateString(),
            ...$extra,
        ]);

        FiscalAdminAudit::record('saas_payment.created', $target->tenant->id, $payment, [
            'gateway' => Payment::GATEWAY_EFI, 'method' => $method, 'payment_id' => $providerId, 'amount' => $target->amount,
            'charge_id' => $target->charge?->id, 'plan_id' => $target->plan?->id,
        ]);

        return $payment;
    }

    private function recordEvent(string $channel, string $key): ?SaasPaymentEvent
    {
        $event = SaasPaymentEvent::query()->firstOrCreate(
            ['provider' => SaasPaymentSetting::PROVIDER_EFI, 'channel' => $channel, 'event_key' => Str::limit($key, 120, '')],
            ['status' => SaasPaymentEvent::STATUS_RECEIVED],
        );

        // Evento repetido já concluído não é processado de novo; o que falhou é reprocessado no reenvio.
        if ($event->wasRecentlyCreated || in_array($event->status, [SaasPaymentEvent::STATUS_RECEIVED, SaasPaymentEvent::STATUS_FAILED], true)) {
            return $event;
        }

        return null;
    }

    private function markSubscriptionCancelled(SaasRecurringAuthorization $authorization, string $reason): void
    {
        if ($authorization->status === SaasRecurringAuthorization::STATUS_CANCELLED) {
            return;
        }

        $authorization->forceFill(['status' => SaasRecurringAuthorization::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => Str::limit($reason, 255, '')])->save();
        FiscalAdminAudit::record('saas_payment.recurring_cancelled', $authorization->tenant_id, $authorization, ['subscription_id' => $authorization->provider_subscription_id, 'reason' => $reason]);
    }

    /** Plano de recorrência na Efí por intervalo (1, 6 ou 12 meses), criado uma vez e reaproveitado. */
    private function efiPlanId(int $months): string
    {
        $settings = $this->settings();
        $plans = $settings->efi_plans ?? [];
        $key = $settings->efi_environment.':'.$months;

        if (empty($plans[$key])) {
            $data = $this->charges->createPlan('VetorOS - recorrência '.$months.' '.($months === 1 ? 'mês' : 'meses'), $months);
            $plans[$key] = (string) $data['plan_id'];
            $settings->forceFill(['efi_plans' => $plans])->save();
        }

        return (string) $plans[$key];
    }

    private function items(SaasPaymentTarget $target): array
    {
        return [['name' => Str::limit($target->description, 255, ''), 'value' => $target->cents(), 'amount' => 1]];
    }

    private function document(Tenant $tenant): string
    {
        return preg_replace('/\D/', '', (string) $tenant->cnpj);
    }

    private function pixDebtor(Tenant $tenant): array
    {
        $document = $this->document($tenant);
        $name = Str::limit(trim((string) ($tenant->company ?: $tenant->name)), 200, '');

        return strlen($document) === 14 ? ['cnpj' => $document, 'nome' => $name] : ['cpf' => $document, 'nome' => $name];
    }

    private function billetCustomer(Tenant $tenant): array
    {
        $document = $this->document($tenant);
        $customer = array_filter([
            'email' => filter_var(trim((string) $tenant->email), FILTER_VALIDATE_EMAIL) ?: null,
            'phone_number' => $this->phone($tenant->phone),
        ]);

        if (strlen($document) === 14) {
            return [...$customer, 'juridical_person' => ['corporate_name' => Str::limit(trim((string) ($tenant->company ?: $tenant->name)), 255, ''), 'cnpj' => $document]];
        }

        return [...$customer, 'name' => Str::limit(trim((string) $tenant->name), 255, ''), 'cpf' => $document];
    }

    /** Titular do cartão (pessoa física, exigida pela Efí) e, se houver, a empresa contratante. */
    private function cardCustomer(Tenant $tenant, array $holder): array
    {
        $customer = array_filter([
            'name' => $holder['name'] ?? null,
            'cpf' => preg_replace('/\D/', '', (string) ($holder['cpf'] ?? '')),
            'email' => $holder['email'] ?? (filter_var(trim((string) $tenant->email), FILTER_VALIDATE_EMAIL) ?: null),
            'birth' => $holder['birth'] ?? null,
            'phone_number' => $this->phone($holder['phone_number'] ?? $tenant->phone),
        ]);

        $document = $this->document($tenant);
        if (strlen($document) === 14) {
            $customer['juridical_person'] = ['corporate_name' => Str::limit(trim((string) ($tenant->company ?: $tenant->name)), 255, ''), 'cnpj' => $document];
        }

        return $customer;
    }

    private function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return in_array(strlen($digits), [10, 11], true) ? $digits : null;
    }

    public function chargesNotificationUrl(): string
    {
        return $this->https(route('webhook.efi.charges', ['token' => $this->webhookSecret()]));
    }

    public function pixWebhookUrl(): string
    {
        return $this->https(route('webhook.efi.pix.base', ['token' => $this->webhookSecret()]));
    }

    public function webhookSecret(): string
    {
        $settings = $this->settings();

        if (! filled($settings->efi_webhook_secret)) {
            $settings->forceFill(['efi_webhook_secret' => Str::random(48)])->save();
        }

        return (string) $settings->efi_webhook_secret;
    }

    private function https(string $url): string
    {
        return preg_replace('#^http://#', 'https://', $url);
    }

    private function toCents(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return 0;
        }
        [$integer, $fraction] = array_pad(explode('.', $value), 2, '0');

        return (int) $integer * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function fromCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
