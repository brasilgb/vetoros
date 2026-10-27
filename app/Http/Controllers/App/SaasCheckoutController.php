<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Admin\SaasPaymentSetting;
use App\Models\Admin\SaasRecurringAuthorization;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Services\Payments\Efi\EfiException;
use App\Services\Payments\SaasPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Checkout da assinatura SaaS pela Efí (VETOR-SAAS-EFI-02): Pix, boleto/Bolix, cartão avulso e
 * recorrência no cartão. O tenant só escolhe o meio; o valor vem da cobrança do contrato ou do plano.
 * Os dados do cartão são tokenizados no navegador pela biblioteca da Efí: aqui chega só o token.
 * Nenhuma resposta daqui libera o acesso: a liberação vem da confirmação consultada na API.
 */
class SaasCheckoutController extends Controller
{
    public function __construct(private readonly SaasPaymentService $payments) {}

    /** Props do checkout para a tela de assinatura. */
    public static function props(Tenant $tenant): array
    {
        $service = app(SaasPaymentService::class);
        $settings = SaasPaymentSetting::current();
        $authorization = SaasRecurringAuthorization::activeFor($tenant->id);

        // Sem Efí homologada não há pagamento online (nunca outro gateway): a tela mostra o aviso.
        if (! $settings->usesEfi()) {
            return [
                'provider' => SaasPaymentSetting::PROVIDER_EFI,
                'methods' => [],
                'unavailable_message' => 'Pagamento online indisponível no momento. Fale com o atendimento da ABrasil Sistemas para regularizar.',
            ];
        }

        // Transição: pagamento anterior ainda válido bloqueia novas cobranças até expirar (sem pagamento em dobro).
        if ($legacy = $service->legacyPendingFor($tenant->id)) {
            return [
                'provider' => SaasPaymentSetting::PROVIDER_EFI,
                'methods' => [],
                'unavailable_message' => 'Há um pagamento anterior ainda válido até '.$legacy->expires_at->format('d/m/Y H:i').'. Aguarde o vencimento dele ou fale com o atendimento.',
            ];
        }

        return [
            'provider' => SaasPaymentSetting::PROVIDER_EFI,
            'methods' => array_values(array_filter(array_keys(SaasPaymentSetting::METHODS), fn ($method) => $service->available($method))),
            // Identificador público da conta (tokenização no navegador); nunca credenciais.
            'payee_code' => $settings->efi_payee_code,
            'environment' => $settings->isProduction() ? 'production' : 'sandbox',
            'recurring' => $authorization ? [
                'card_mask' => $authorization->card_mask,
                'amount' => (string) $authorization->amount,
                'interval_months' => $authorization->interval_months,
                'authorized_at' => $authorization->authorized_at?->toIso8601String(),
            ] : null,
            'billing' => [
                'street' => $tenant->street, 'number' => $tenant->number, 'neighborhood' => $tenant->district,
                'zipcode' => preg_replace('/\D/', '', (string) $tenant->zip_code), 'city' => $tenant->city, 'state' => $tenant->state,
                'email' => $tenant->email, 'phone' => $tenant->phone,
            ],
        ];
    }

    public function pix(Request $request): JsonResponse
    {
        return $this->respond(fn (Tenant $tenant) => $this->payments->pix($this->payments->targetFor($tenant, $request->integer('plan_id') ?: null)));
    }

    public function billet(Request $request): JsonResponse
    {
        return $this->respond(fn (Tenant $tenant) => $this->payments->billet($this->payments->targetFor($tenant, $request->integer('plan_id') ?: null)));
    }

    public function card(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => 'nullable|integer',
            'payment_token' => 'required|string|max:200',
            'card_mask' => 'nullable|string|max:40',
            'recurring' => 'boolean',
            'authorize_recurring' => 'exclude_unless:recurring,true|accepted',
            'holder.name' => 'required|string|max:255',
            'holder.cpf' => 'required|cpf',
            'holder.birth' => 'required|date|before:-16 years',
            'holder.email' => 'required|email|max:190',
            'holder.phone_number' => 'required|string|max:20',
            'billing.street' => 'required|string|max:200',
            'billing.number' => 'required|string|max:55',
            'billing.neighborhood' => 'required|string|max:255',
            'billing.zipcode' => 'required|string|max:9',
            'billing.city' => 'required|string|max:255',
            'billing.state' => 'required|string|size:2',
        ], [
            'authorize_recurring.accepted' => 'Autorize a cobrança recorrente no cartão para continuar.',
        ], [
            'holder.name' => 'nome do titular', 'holder.cpf' => 'CPF do titular', 'holder.birth' => 'data de nascimento',
            'holder.email' => 'e-mail', 'holder.phone_number' => 'telefone', 'billing.street' => 'logradouro', 'billing.number' => 'número',
            'billing.neighborhood' => 'bairro', 'billing.zipcode' => 'CEP', 'billing.city' => 'cidade', 'billing.state' => 'UF',
        ]);

        $card = [
            'payment_token' => $data['payment_token'],
            'card_mask' => $data['card_mask'] ?? null,
            'customer' => [...$data['holder'], 'birth' => date('Y-m-d', strtotime($data['holder']['birth']))],
            'billing_address' => [...$data['billing'], 'zipcode' => preg_replace('/\D/', '', $data['billing']['zipcode']), 'state' => strtoupper($data['billing']['state'])],
        ];

        return $this->respond(function (Tenant $tenant) use ($data, $card, $request) {
            $target = $this->payments->targetFor($tenant, $data['plan_id'] ?? null);

            if ($request->boolean('recurring')) {
                $authorization = $this->payments->subscribe($target, $card, $request->ip(), (int) auth()->id());

                return Payment::query()->where('provider_subscription_id', $authorization->provider_subscription_id)->latest('id')->first()
                    ?? throw new EfiException('Assinatura criada sem cobrança inicial; aguarde a confirmação da Efí.');
            }

            return $this->payments->card($target, $card);
        });
    }

    /** O titular pode cancelar a recorrência no cartão; as próximas competências voltam a ter boleto/Pix. */
    public function cancelRecurring(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdministrator(), 403);
        $request->validate(['confirm' => 'accepted']);
        $authorization = SaasRecurringAuthorization::activeFor((int) auth()->user()->tenant_id);
        if (! $authorization) {
            return response()->json(['error' => 'Não há recorrência no cartão ativa.'], 422);
        }

        try {
            $this->payments->cancelSubscription($authorization, 'Cancelada pelo cliente no VetorOS.');
        } catch (EfiException $exception) {
            return response()->json(['error' => 'Não foi possível cancelar agora. Tente novamente em instantes.'], 502);
        }

        return response()->json(['status' => 'cancelled']);
    }

    /** Situação de uma transação do próprio tenant (para a tela acompanhar; quem libera é a conciliação). */
    public function status(Payment $payment): JsonResponse
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        abort_unless((int) $payment->tenant_id === (int) auth()->user()->tenant_id, 404);

        return response()->json(self::present($payment));
    }

    /**
     * "Consultar pagamento": o servidor consulta a Efí e aplica o resultado pelo motor de assinatura.
     * Falha da Efí não altera nada e devolve a situação atual.
     */
    public function refresh(Payment $payment): JsonResponse
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        abort_unless((int) $payment->tenant_id === (int) auth()->user()->tenant_id && $payment->isEfi(), 404);

        try {
            $payment = $this->payments->reconcile($payment);
        } catch (EfiException $exception) {
            Log::warning('Falha ao consultar pagamento na Efí.', ['payment_id' => $payment->id, 'message' => $exception->getMessage()]);

            return response()->json([...self::present($payment), 'error' => 'Não foi possível consultar a Efí agora. Tente novamente em instantes.'], 502);
        }

        return response()->json(self::present($payment));
    }

    /** Dados exibíveis da transação Efí. */
    public static function present(Payment $payment): array
    {
        $details = $payment->details ?? [];
        $image = $details['pix_qr_image'] ?? null;

        return [
            'id' => $payment->id,
            'provider' => 'efi',
            'method' => $payment->method,
            'status' => $payment->status,
            'payment_id' => $payment->payment_id,
            'amount' => (string) $payment->amount,
            'due_date' => $payment->due_date?->toDateString(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            // Compatível com a tela antiga (PNG em base64 sem prefixo) e com o Bolix (data URI, às vezes SVG).
            'qr_code' => is_string($image) && str_starts_with($image, 'data:image/png;base64,') ? substr($image, 22) : null,
            'qr_code_image' => is_string($image) && str_starts_with($image, 'data:image/') ? $image : null,
            'qr_code_copy_paste' => $details['pix_copy_paste'] ?? null,
            'barcode' => $details['barcode'] ?? null,
            'billet_link' => $details['billet_link'] ?? null,
            'billet_pdf' => $details['billet_pdf'] ?? null,
            'card_mask' => $details['card_mask'] ?? null,
            'provider_status' => $details['provider_status'] ?? null,
            'error' => $payment->status === Payment::STATUS_FAILED ? ($payment->error ?: 'Pagamento recusado.') : null,
        ];
    }

    private function respond(callable $action): JsonResponse
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $tenant = auth()->user()->tenant;
        if (! $tenant) {
            return response()->json(['error' => 'Tenant não encontrado para o usuário autenticado.'], 422);
        }

        try {
            return response()->json(self::present($action($tenant)));
        } catch (EfiException $exception) {
            Log::warning('Falha no checkout Efí.', ['tenant_id' => $tenant->id, 'message' => $exception->getMessage()]);

            return response()->json(['error' => 'Não foi possível gerar o pagamento agora. Tente novamente em instantes.'], 502);
        }
    }
}
