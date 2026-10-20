<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Admin\EfiIntegrationTest;
use App\Models\Admin\SaasPaymentSetting;
use App\Services\Payments\Efi\EfiException;
use App\Services\Payments\EfiIntegrationTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Modal "Testar pagamento" da tela RootAdmin → Financeiro → Efí Bank (VETOROS-EFI-TEST-01). Grupo
 * `root.admin`. Respostas JSON para o modal; nenhum dado de cartão chega aqui além do `payment_token`.
 * Em produção a criação exige confirmação explícita e a senha do RootAdmin.
 */
class EfiIntegrationTestController extends Controller
{
    public function __construct(private readonly EfiIntegrationTestService $tests) {}

    public function store(Request $request): JsonResponse
    {
        $settings = SaasPaymentSetting::current();
        $method = (string) $request->input('method');
        $card = $method === SaasPaymentSetting::METHOD_CARD;
        $billet = $method === SaasPaymentSetting::METHOD_BILLET;

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(EfiIntegrationTestService::MINIMUM_CENTS))],
            'amount' => ['required', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'payer.name' => [Rule::requiredIf($card || $billet), 'nullable', 'string', 'max:120'],
            'payer.cpf' => [Rule::requiredIf($card || $billet), 'nullable', 'string', 'max:20'],
            'payer.email' => [Rule::requiredIf($card), 'nullable', 'email', 'max:120'],
            'payer.birth' => [Rule::requiredIf($card), 'nullable', 'date_format:Y-m-d'],
            'payer.phone_number' => [Rule::requiredIf($card), 'nullable', 'string', 'max:20'],
            'billing.street' => [Rule::requiredIf($card), 'nullable', 'string', 'max:200'],
            'billing.number' => [Rule::requiredIf($card), 'nullable', 'string', 'max:20'],
            'billing.neighborhood' => [Rule::requiredIf($card), 'nullable', 'string', 'max:120'],
            'billing.zipcode' => [Rule::requiredIf($card), 'nullable', 'string', 'max:10'],
            'billing.city' => [Rule::requiredIf($card), 'nullable', 'string', 'max:120'],
            'billing.state' => [Rule::requiredIf($card), 'nullable', 'string', 'size:2'],
            'card.payment_token' => [Rule::requiredIf($card), 'nullable', 'string', 'max:255'],
            'card.card_mask' => ['nullable', 'string', 'max:30'],
            ...($settings->isProduction() ? [
                'confirm_production' => ['required', 'accepted'],
                'password' => ['required', 'current_password'],
            ] : []),
        ], [
            'amount.regex' => 'Informe um valor válido.',
            'confirm_production.required' => 'Confirme a criação de uma cobrança real em produção.',
            'confirm_production.accepted' => 'Confirme a criação de uma cobrança real em produção.',
            'password.required' => 'Informe sua senha para criar cobrança em produção.',
            'password.current_password' => 'Senha incorreta.',
        ]);

        $cents = $this->toCents((string) $data['amount']);
        $minimum = EfiIntegrationTestService::MINIMUM_CENTS[$method];
        if ($cents < $minimum || $cents > EfiIntegrationTestService::MAXIMUM_CENTS) {
            throw ValidationException::withMessages(['amount' => 'Valor entre R$ '.number_format($minimum / 100, 2, ',', '.')
                .' e R$ '.number_format(EfiIntegrationTestService::MAXIMUM_CENTS / 100, 2, ',', '.').' para este método.']);
        }

        $test = $this->tests->create($method, $cents, $data, (int) auth()->id());

        return response()->json($test->toPanel(), $test->status === EfiIntegrationTest::STATUS_ERROR ? 502 : 201);
    }

    public function show(EfiIntegrationTest $test): JsonResponse
    {
        return response()->json($test->load('creator:id,name')->toPanel());
    }

    /** "Consultar novamente": pergunta à Efí agora. */
    public function refresh(EfiIntegrationTest $test): JsonResponse
    {
        try {
            $this->tests->reconcile($test);
        } catch (EfiException $exception) {
            $test->forceFill(['error' => mb_substr($exception->getMessage(), 0, 500), 'last_checked_at' => now()])->save();

            return response()->json($test->load('creator:id,name')->toPanel(), 502);
        }

        return response()->json($test->load('creator:id,name')->toPanel());
    }

    private function toCents(string $value): int
    {
        [$integer, $fraction] = array_pad(explode('.', str_replace(',', '.', $value)), 2, '0');

        return (int) $integer * 100 + (int) str_pad($fraction, 2, '0');
    }
}
