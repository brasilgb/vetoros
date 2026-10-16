<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Admin\Plan;
use App\Models\App\Payment;
use App\Services\Commercial\SaasContractService;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\Exceptions\MPApiException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mercadoPagoService,
        private readonly SaasContractService $saasContracts,
    ) {}

    public function generatePix(Request $request)
    {
        try {
            $tenant = auth()->user()->tenant;
            if (! $tenant) {
                return response()->json(['error' => 'Tenant não encontrado para o usuário autenticado.'], 422);
            }

            // Contrato personalizado: o PIX quita a cobrança em aberto do ciclo, nunca o preço de um plano.
            $charge = null;
            $plan = null;
            if ($tenant->hasCustomContract()) {
                $charge = $this->saasContracts->openChargeFor($tenant);
                if (! $charge) {
                    return response()->json(['error' => 'Não há cobrança em aberto para pagamento.'], 422);
                }
                $amount = (string) $charge->amount;
                $description = 'Contrato '.$charge->contract->contract_number.' - vencimento '.$charge->due_date->format('d/m/Y').' - '.$tenant->name;
                $reference = ['tenant_id' => $tenant->id, 'charge_id' => $charge->id];
            } else {
                $validated = $request->validate([
                    'plan_id' => ['required', 'integer', 'exists:plans,id'],
                ]);

                $plan = Plan::findOrFail($validated['plan_id']);
                if ($plan->isTrial() || (float) $plan->value <= 0) {
                    return response()->json(['error' => 'Plano inválido para cobrança PIX.'], 422);
                }
                $amount = $plan->value;
                $description = 'Assinatura '.$plan->name.' - '.$tenant->name;
                $reference = ['tenant_id' => $tenant->id, 'plan_id' => $plan->id];
            }

            $webhookToken = config('services.mercadopago.webhook_token');
            if (! $webhookToken) {
                return response()->json(['error' => 'Webhook token não configurado.'], 500);
            }
            $idempotencyKey = Str::uuid()->toString();

            $paymentRequest = [
                'transaction_amount' => (float) $amount,
                'description' => $description,
                'payment_method_id' => 'pix',
                'payer' => [
                    'email' => "pix_{$tenant->id}@vetoros.com.br",
                    'first_name' => explode(' ', trim($tenant->name))[0], // Apenas o primeiro nome evita erros de validação
                    'identification' => [
                        'type' => strlen(preg_replace('/\D/', '', $tenant->cnpj)) > 11 ? 'CNPJ' : 'CPF',
                        'number' => preg_replace('/\D/', '', $tenant->cnpj),
                    ],
                ],
                'external_reference' => json_encode($reference),
                'notification_url' => str_replace('http://', 'https://', route('webhook.mercadopago', ['token' => $webhookToken])),
            ];

            $payment = $this->mercadoPagoService->createPixPayment($paymentRequest, $idempotencyKey);

            Payment::updateOrCreate(
                ['payment_id' => (string) $payment->id],
                [
                    'tenant_id' => $tenant->id,
                    'gateway' => 'mercadopago',
                    'amount' => (float) ($payment->transaction_amount ?? $amount),
                    'status' => (string) ($payment->status ?? 'pending'),
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => $payment->date_of_expiration ?? null,
                    'raw_response' => json_decode(json_encode($payment), true),
                ]
            );

            return response()->json([
                'qr_code' => $payment->point_of_interaction->transaction_data->qr_code_base64,
                'qr_code_copy_paste' => $payment->point_of_interaction->transaction_data->qr_code,
                'payment_id' => $payment->id,
            ]);
        } catch (MPApiException $e) {
            $content = $e->getApiResponse()->getContent();
            Log::error('Detalhes da API Mercado Pago', [
                'error' => $content,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Falha na comunicação com o provedor',
                'details' => $content['message'] ?? 'Erro desconhecido',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Erro Geral no Pagamento', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Erro interno ao processar pagamento'], 500);
        }
    }
}
