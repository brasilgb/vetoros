<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\EmitSaasPaymentInvoice;
use App\Models\Admin\Plan;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Services\Commercial\SaasContractService;
use App\Services\MercadoPagoService;
use App\Services\SubscriptionInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WebhookController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mercadoPagoService,
        private readonly SubscriptionInvoiceService $subscriptionInvoiceService,
        private readonly SaasContractService $saasContracts,
    ) {}

    public function handle(Request $request, $token)
    {
        // 1ª CAMADA: Validação do Token da URL
        if (! hash_equals((string) config('services.mercadopago.webhook_token'), (string) $token)) {
            Log::warning('Tentativa de acesso não autorizado ao Webhook.', ['token' => $token]);

            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // 2ª CAMADA: Filtragem do evento
        // O MP envia notificações de diversos tipos. Queremos apenas 'payment'
        $type = $request->input('type') ?? $request->input('topic');

        if ($type !== 'payment') {
            return response()->json(['status' => 'ignored']);
        }

        $paymentId = $request->input('data.id') ?? $request->input('id');

        if (! $paymentId) {
            return response()->json(['error' => 'ID missing'], 400);
        }

        try {
            $payment = $this->mercadoPagoService->getPayment($paymentId);
            $this->syncPaymentRecord($payment);

            $metadata = $this->parseExternalReference($payment->external_reference ?? null);
            if (! empty($metadata['charge_id'])) {
                return $this->processContractCharge($payment, $metadata);
            }

            if ($payment->status === 'approved') {
                return $this->processApproval($payment);
            }

            return response()->json(['status' => 'processed_not_approved']);

        } catch (\Exception $e) {
            Log::error('Erro ao processar Webhook MP: '.$e->getMessage());

            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    private function processApproval($payment)
    {
        $metadata = $this->parseExternalReference($payment->external_reference ?? null);
        if (! $metadata || empty($metadata['tenant_id']) || empty($metadata['plan_id'])) {
            Log::error('Pagamento aprovado sem metadados válidos.', ['payment_id' => $payment->id]);

            return response()->json(['error' => 'Invalid metadata'], 422);
        }

        $plan = Plan::query()->with('periods')->find($metadata['plan_id']);
        if (! $plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $months = $plan->billingMonths();
        if ($months <= 0) {
            Log::error('Plano sem período de renovação válido.', [
                'payment_id' => $payment->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
            ]);

            return response()->json(['error' => 'Plan period not configured'], 422);
        }

        $result = DB::transaction(function () use ($metadata, $payment, $plan, $months) {
            $tenant = Tenant::query()->lockForUpdate()->find($metadata['tenant_id']);
            if (! $tenant) {
                return null;
            }

            // PIX de plano gerado antes da conversão para contrato: não renova por plano (evita ciclo sobreposto).
            if ($tenant->hasCustomContract()) {
                Log::warning('PIX de plano aprovado para tenant com contrato personalizado; renovação por plano ignorada.', [
                    'payment_id' => (string) $payment->id,
                    'tenant_id' => $tenant->id,
                ]);

                return ['tenant_id' => $tenant->id, 'payment_id' => (string) $payment->id, 'skipped' => true];
            }

            if ((string) $tenant->last_payment_id === (string) $payment->id) {
                return [
                    'tenant_id' => $tenant->id,
                    'payment_id' => (string) $payment->id,
                ];
            }

            $startBase = ($tenant->expires_at && $tenant->expires_at->isFuture())
                ? $tenant->expires_at->copy()
                : now();

            $preferredPeriod = $plan->preferredPeriod();

            $tenant->update([
                'plan_id' => $plan->id,
                'period_id' => $preferredPeriod?->id,
                'subscription_status' => 'active',
                'expires_at' => $startBase->addMonths($months),
                'last_payment_id' => (string) $payment->id,
                'status' => 1,
            ]);

            Payment::updateOrCreate(
                ['payment_id' => (string) $payment->id],
                $this->buildPaymentPayload($payment, $tenant->id)
            );

            Log::info("Tenant {$tenant->name} renovado via PIX. Plano: {$plan->name}");

            return [
                'tenant_id' => $tenant->id,
                'payment_id' => (string) $payment->id,
            ];
        });

        if (! $result) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! empty($result['skipped'])) {
            return response()->json(['status' => 'ignored_custom_contract']);
        }

        $tenant = Tenant::query()->find($result['tenant_id']);
        $paymentRecord = Payment::query()->where('payment_id', $result['payment_id'])->first();

        if ($tenant && $paymentRecord) {
            // NFS-e automática do plano tradicional ("Emitir NFS-e para este cliente SaaS"): o fato faturado é o pagamento.
            if ($tenant->saas_invoice_enabled) {
                EmitSaasPaymentInvoice::dispatch($paymentRecord->id);
            }

            $this->subscriptionInvoiceService->sendPaidInvoice($paymentRecord, $tenant, $plan);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * PIX de cobrança do contrato personalizado: aprovado quita a cobrança; estornado/contestado
     * estorna. Webhook repetido é no-op (registerPayment e refund são idempotentes).
     */
    private function processContractCharge($payment, array $metadata)
    {
        $charge = TenantSubscriptionCharge::query()->find($metadata['charge_id']);
        $record = Payment::query()->where('payment_id', (string) $payment->id)->first();

        if (! $charge || ! $record || (int) $charge->tenant_id !== (int) ($metadata['tenant_id'] ?? 0)) {
            Log::error('Pagamento de contrato sem cobrança válida.', ['payment_id' => (string) $payment->id]);

            return response()->json(['error' => 'Invalid metadata'], 422);
        }

        try {
            if ($payment->status === 'approved') {
                $this->saasContracts->registerPayment($charge, (string) ($payment->transaction_amount ?? 0), 'pix', $record);

                return response()->json(['status' => 'success']);
            }

            if (in_array($payment->status, ['refunded', 'charged_back'], true) && (int) $charge->payment_id === (int) $record->id) {
                $this->saasContracts->refund($charge, 'Estorno informado pelo Mercado Pago ('.$payment->status.').');

                return response()->json(['status' => 'refunded']);
            }
        } catch (ValidationException $exception) {
            Log::warning('Pagamento de contrato não aplicado.', ['payment_id' => (string) $payment->id, 'charge_id' => $charge->id, 'errors' => $exception->errors()]);

            return response()->json(['status' => 'not_applied']);
        }

        return response()->json(['status' => 'processed_not_approved']);
    }

    private function syncPaymentRecord($payment): void
    {
        $metadata = $this->parseExternalReference($payment->external_reference ?? null);
        $paymentId = (string) $payment->id;

        $existing = Payment::where('payment_id', $paymentId)->first();
        $tenantId = $metadata['tenant_id'] ?? ($existing?->tenant_id);
        if (! $tenantId) {
            Log::warning('Webhook sem tenant_id válido para sincronizar pagamento.', [
                'payment_id' => $paymentId,
            ]);

            return;
        }

        Payment::updateOrCreate(
            ['payment_id' => $paymentId],
            $this->buildPaymentPayload($payment, (int) $tenantId)
        );
    }

    private function buildPaymentPayload($payment, int $tenantId): array
    {
        $paymentId = (string) $payment->id;
        $existing = Payment::query()->where('payment_id', $paymentId)->first();
        $idempotencyKey = $existing?->idempotency_key;

        if (! $idempotencyKey || trim($idempotencyKey) === '') {
            $idempotencyKey = 'webhook-'.$paymentId;
        }

        return [
            'tenant_id' => $tenantId,
            'gateway' => 'mercadopago',
            'amount' => (float) ($payment->transaction_amount ?? 0),
            'status' => (string) ($payment->status ?? 'unknown'),
            'idempotency_key' => $idempotencyKey,
            'expires_at' => $payment->date_of_expiration ?? null,
            'raw_response' => json_decode(json_encode($payment), true),
        ];
    }

    private function parseExternalReference(mixed $externalReference): ?array
    {
        if (! is_string($externalReference) || $externalReference === '') {
            return null;
        }

        $metadata = json_decode($externalReference, true);
        if (! is_array($metadata)) {
            return null;
        }

        return $metadata;
    }
}
