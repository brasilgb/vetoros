<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Admin\EfiIntegrationTest;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\SaasPaymentEvent;
use App\Models\Admin\SaasPaymentSetting;
use App\Models\App\Payment;
use App\Services\Payments\Efi\EfiChargesClient;
use App\Services\Payments\Efi\EfiException;
use App\Services\Payments\Efi\EfiPixClient;
use App\Services\Payments\EfiIntegrationTestService;
use App\Services\Payments\LegacyPaymentsReport;
use App\Services\Payments\SaasPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RootAdmin → Financeiro → Efí Bank (VETOR-SAAS-EFI-02). Grupo `root.admin`: só o RootAdmin.
 * Credenciais e certificado são somente escrita (nunca voltam ao navegador) e exigem a senha.
 * A tela só lê o banco; a API da Efí só é chamada por ação explícita (teste, webhook).
 */
class EfiSettingsController extends Controller
{
    public function __construct(private readonly SaasPaymentService $payments) {}

    public function show(): Response
    {
        $settings = SaasPaymentSetting::current();

        return Inertia::render('admin/finance/efi', [
            'settings' => [
                'blocked_reason' => $settings->blockedReason(),
                'environment' => $settings->efi_environment,
                'charges_configured' => $settings->chargesConfigured(),
                'pix_credentials_configured' => filled($settings->efi_pix_client_id) && filled($settings->efi_pix_client_secret),
                'pix_certificate_configured' => filled($settings->efi_pix_certificate),
                'pix_certificate_subject' => $settings->efi_pix_certificate_subject,
                'pix_certificate_expires_at' => $settings->efi_pix_certificate_expires_at?->toIso8601String(),
                'pix_key' => $settings->efi_pix_key,
                'payee_code' => $settings->efi_payee_code,
                'account_reference' => $settings->efi_account_reference,
                'methods' => $settings->efi_methods ?? [],
                'homologated_at' => $settings->efi_homologated_at?->toIso8601String(),
                'last_connection_test_at' => $settings->last_connection_test_at?->toIso8601String(),
                'last_connection_ok' => $settings->last_connection_ok,
                'last_connection_message' => $settings->last_connection_message,
                'checks' => $this->configurationChecks($settings),
                'pix_webhook_registered_at' => $settings->efi_pix_webhook_registered_at?->toIso8601String(),
                'last_pix_notification_at' => $settings->last_pix_notification_at?->toIso8601String(),
                'last_charges_notification_at' => $settings->last_charges_notification_at?->toIso8601String(),
            ],
            'method_options' => collect(SaasPaymentSetting::METHODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'errors_log' => $this->recentErrors(),
            // Modal "Testar pagamento" (VETOROS-EFI-TEST-01): só leitura do banco ao abrir a tela.
            'integration_test' => [
                'methods' => app(EfiIntegrationTestService::class)->availableMethods(),
                'minimum_cents' => EfiIntegrationTestService::MINIMUM_CENTS,
                'payee_code' => $settings->efi_payee_code,
                'recent' => EfiIntegrationTest::query()->with('creator:id,name')->latest('id')->limit(10)->get()->map->toPanel(),
            ],
            // Histórico de provedores anteriores (somente leitura) para o procedimento de transição.
            'legacy' => app(LegacyPaymentsReport::class)->summary(),
            'payments' => Payment::query()
                ->where('gateway', Payment::GATEWAY_EFI)
                ->with('tenant:id,name,company')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'tenant' => $payment->tenant?->company ?: $payment->tenant?->name,
                    'method' => $payment->method,
                    'status' => $payment->status,
                    'amount' => (string) $payment->amount,
                    'reference' => $payment->reference,
                    'created_at' => $payment->created_at?->toIso8601String(),
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'error' => $payment->error,
                    'can_refund' => $payment->method === SaasPaymentSetting::METHOD_PIX && $payment->status === Payment::STATUS_PAID && str_starts_with((string) $payment->error, 'Divergência'),
                ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', Rule::in(['homologation', 'production'])],
            'charges_client_id' => 'nullable|string|max:255',
            'charges_client_secret' => 'nullable|string|max:255',
            'pix_client_id' => 'nullable|string|max:255',
            'pix_client_secret' => 'nullable|string|max:255',
            'pix_key' => 'nullable|string|max:120',
            'payee_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
            'account_reference' => 'nullable|string|max:120',
            'methods' => 'array',
            'methods.*' => Rule::in(array_keys(SaasPaymentSetting::METHODS)),
            'password' => 'required|current_password',
        ], ['password.current_password' => 'Senha incorreta.', 'payee_code.regex' => 'O identificador de conta tem só letras e números.']);

        $settings = SaasPaymentSetting::current();
        $changes = [];

        DB::transaction(function () use ($settings, $data, &$changes) {
            foreach (['charges_client_id', 'charges_client_secret', 'pix_client_id', 'pix_client_secret'] as $field) {
                if (filled($data[$field] ?? null)) {
                    $settings->{'efi_'.$field} = trim((string) $data[$field]);
                    $changes[$field] = 'alterado';
                }
            }

            foreach (['pix_key', 'payee_code', 'account_reference'] as $field) {
                $value = filled($data[$field] ?? null) ? trim((string) $data[$field]) : null;
                if ($value !== $settings->{'efi_'.$field}) {
                    $settings->{'efi_'.$field} = $value;
                    $changes[$field] = $field === 'account_reference' ? $value : 'alterado';
                }
            }

            $methods = array_values(array_unique($data['methods'] ?? []));
            if ($methods !== ($settings->efi_methods ?? [])) {
                $changes['methods'] = $methods;
                $settings->efi_methods = $methods;
            }

            // Troca de ambiente ou de credencial exige nova homologação antes de cobrar pela Efí.
            if ($data['environment'] !== $settings->efi_environment) {
                $changes['environment'] = $data['environment'];
                $settings->efi_environment = $data['environment'];
                $settings->efi_pix_webhook_registered_at = null;
            }
            if (array_intersect(array_keys($changes), ['environment', 'charges_client_id', 'charges_client_secret', 'pix_client_id', 'pix_client_secret', 'pix_key']) !== []) {
                $settings->efi_homologated_at = null;
                $settings->efi_homologated_by = null;
            }
            if (array_intersect(array_keys($changes), ['environment', 'charges_client_id', 'charges_client_secret', 'pix_client_id', 'pix_client_secret']) !== []) {
                $settings->last_connection_test_at = null;
                $settings->last_connection_ok = null;
                $settings->last_connection_message = null;
            }

            $settings->save();
            app(EfiPixClient::class)->forgetToken();
            app(EfiChargesClient::class)->forgetToken();

            if ($changes !== []) {
                FiscalAdminAudit::record('efi.settings_updated', null, $settings, ['changes' => $changes]);
            }
        });

        return back()->with('success', $changes === [] ? 'Nada foi alterado.' : 'Configuração da Efí salva.');
    }

    /** Certificado da API Pix (.p12 da Efí ou PEM com chave). Guardado só em PEM criptografado. */
    public function certificate(Request $request): RedirectResponse
    {
        $request->validate([
            'certificate' => 'required|file|max:64',
            'certificate_password' => 'nullable|string|max:255',
            'password' => 'required|current_password',
        ], ['password.current_password' => 'Senha incorreta.']);

        $content = (string) file_get_contents($request->file('certificate')->getRealPath());
        $pem = $this->toPem($content, (string) $request->input('certificate_password', ''));

        $certificate = openssl_x509_read($pem);
        $info = $certificate ? openssl_x509_parse($certificate) : false;
        if (! $info) {
            throw ValidationException::withMessages(['certificate' => 'Certificado inválido.']);
        }

        $expiresAt = Carbon::createFromTimestamp($info['validTo_time_t']);
        if ($expiresAt->isPast()) {
            throw ValidationException::withMessages(['certificate' => 'Certificado vencido em '.$expiresAt->format('d/m/Y').'.']);
        }

        $settings = SaasPaymentSetting::current();
        $settings->forceFill([
            'efi_pix_certificate' => $pem,
            'efi_pix_certificate_subject' => mb_substr((string) ($info['subject']['CN'] ?? $info['name'] ?? ''), 0, 190),
            'efi_pix_certificate_expires_at' => $expiresAt,
            'efi_homologated_at' => null,
            'efi_homologated_by' => null,
        ])->save();
        app(EfiPixClient::class)->forgetToken();

        FiscalAdminAudit::record('efi.certificate_updated', null, $settings, ['expires_at' => $expiresAt->toDateString()]);

        return back()->with('success', 'Certificado da API Pix salvo. Teste a conexão e homologue de novo.');
    }

    /** Teste de conexão: obtém o token das duas APIs (nenhuma cobrança é criada). */
    public function test(): RedirectResponse
    {
        $settings = SaasPaymentSetting::current();
        $results = [];

        foreach (['Cobranças' => app(EfiChargesClient::class), 'Pix' => app(EfiPixClient::class)] as $name => $client) {
            try {
                $client->testConnection();
                $results[] = "{$name}: ok";
            } catch (EfiException $exception) {
                $results[] = "{$name}: ".$exception->getMessage();
            }
        }

        $ok = collect($results)->every(fn ($line) => str_ends_with($line, ': ok'));
        $settings->forceFill(['last_connection_test_at' => now(), 'last_connection_ok' => $ok, 'last_connection_message' => mb_substr(implode(' | ', $results), 0, 500)])->save();
        FiscalAdminAudit::record('efi.connection_test', null, $settings, ['ok' => $ok, 'results' => $results]);

        return back()->with($ok ? 'success' : 'error', $ok ? 'Conexão com a Efí confirmada nas APIs Cobranças e Pix.' : 'Falha na conexão: '.implode(' | ', $results));
    }

    /** Cadastra na Efí o webhook da chave Pix desta aplicação (URL com segredo próprio). */
    public function webhook(): RedirectResponse
    {
        $settings = SaasPaymentSetting::current();
        $url = $this->payments->pixWebhookUrl();

        try {
            app(EfiPixClient::class)->configureWebhook($url);
        } catch (EfiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $settings->forceFill(['efi_pix_webhook_url' => preg_replace('#/[^/]+$#', '/***', $url), 'efi_pix_webhook_registered_at' => now()])->save();
        FiscalAdminAudit::record('efi.pix_webhook_registered', null, $settings);

        return back()->with('success', 'Webhook Pix cadastrado na Efí.');
    }

    /**
     * Homologar: libera as novas cobranças pela Efí. Exige teste de conexão ok, meio habilitado e,
     * com Pix, webhook cadastrado. Suspender: bloqueia novas cobranças externas na hora (as transações
     * já criadas continuam sendo conciliadas). Não existe outro gateway para onde voltar.
     */
    public function homologation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['homologate', 'suspend'])],
            'confirm' => 'accepted',
            'password' => 'required|current_password',
        ], ['password.current_password' => 'Senha incorreta.', 'confirm.accepted' => 'Confirme a operação.']);

        $settings = SaasPaymentSetting::current();

        if ($data['action'] === 'homologate') {
            $problems = array_filter([
                ! $settings->chargesConfigured() && ! $settings->pixConfigured() ? 'Cadastre as credenciais da Efí.' : null,
                $settings->enabledMethods() === [] ? 'Habilite ao menos um meio de pagamento.' : null,
                ! $settings->last_connection_ok ? 'Faça o teste de conexão com sucesso.' : null,
                $settings->methodEnabled(SaasPaymentSetting::METHOD_PIX) && ! $settings->efi_pix_webhook_registered_at ? 'Cadastre o webhook Pix.' : null,
                array_intersect($settings->enabledMethods(), [SaasPaymentSetting::METHOD_CARD, SaasPaymentSetting::METHOD_CARD_SUBSCRIPTION]) !== [] && ! filled($settings->efi_payee_code) ? 'Informe o identificador de conta (cartão).' : null,
            ]);
            if ($problems !== []) {
                throw ValidationException::withMessages(['action' => array_values($problems)]);
            }

            $settings->forceFill(['efi_homologated_at' => now(), 'efi_homologated_by' => auth()->id()])->save();
        } else {
            $settings->forceFill(['efi_homologated_at' => null, 'efi_homologated_by' => null])->save();
        }

        FiscalAdminAudit::record($data['action'] === 'homologate' ? 'efi.homologated' : 'efi.suspended', null, $settings, ['environment' => $settings->efi_environment]);

        return back()->with('success', $data['action'] === 'homologate'
            ? 'Efí homologada ('.($settings->isProduction() ? 'produção' : 'homologação').'): novas cobranças liberadas.'
            : 'Novas cobranças externas suspensas até nova homologação. Transações já criadas continuam sendo conciliadas.');
    }

    public function reconcile(Payment $payment): RedirectResponse
    {
        abort_unless($payment->isEfi(), 404);

        try {
            $this->payments->reconcile($payment);
        } catch (EfiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Transação conciliada com a Efí.');
    }

    public function refund(Request $request, Payment $payment): RedirectResponse
    {
        $request->validate(['password' => 'required|current_password'], ['password.current_password' => 'Senha incorreta.']);
        abort_unless($payment->isEfi(), 404);

        try {
            $this->payments->refundPix($payment, (int) auth()->id());
        } catch (EfiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Devolução do Pix solicitada à Efí.');
    }

    private function toPem(string $content, string $password): string
    {
        if (str_contains($content, '-----BEGIN CERTIFICATE-----')) {
            if (! str_contains($content, 'PRIVATE KEY-----')) {
                throw ValidationException::withMessages(['certificate' => 'O arquivo PEM precisa conter o certificado e a chave privada.']);
            }

            return $content;
        }

        $certs = [];
        if (! openssl_pkcs12_read($content, $certs, $password)) {
            throw ValidationException::withMessages(['certificate' => 'Não foi possível ler o .p12 (senha incorreta ou formato legado). Converta para PEM com: openssl pkcs12 -in certificado.p12 -out certificado.pem -nodes -legacy']);
        }

        return $certs['cert'].$certs['pkey'];
    }

    /** Últimos erros sem dados sensíveis (as mensagens já vêm saneadas da EfiException). */
    private function recentErrors(): array
    {
        $events = SaasPaymentEvent::query()->where('status', SaasPaymentEvent::STATUS_FAILED)->latest('id')->limit(10)->get()
            ->map(fn (SaasPaymentEvent $event) => ['at' => $event->updated_at?->toIso8601String(), 'source' => 'Notificação '.$event->channel, 'message' => $event->error]);

        $payments = Payment::query()->where('gateway', Payment::GATEWAY_EFI)->whereNotNull('error')->latest('updated_at')->limit(10)->get()
            ->map(fn (Payment $payment) => ['at' => $payment->updated_at?->toIso8601String(), 'source' => 'Transação '.$payment->reference, 'message' => $payment->error]);

        $audits = FiscalAdminAudit::query()->whereIn('action', ['saas_payment.collection_failed', 'saas_payment.divergence'])->latest('id')->limit(10)->get()
            ->map(fn (FiscalAdminAudit $audit) => ['at' => $audit->created_at?->toIso8601String(), 'source' => $audit->action, 'message' => $audit->data['error'] ?? $audit->data['message'] ?? null]);

        return $events->concat($payments)->concat($audits)->sortByDesc('at')->take(15)->values()->all();
    }

    /** OAuth is checked only by the explicit connection test; configuration checks make no API calls. */
    private function configurationChecks(SaasPaymentSetting $settings): array
    {
        $message = (string) $settings->last_connection_message;
        $chargesAuth = $settings->last_connection_test_at && str_contains($message, 'Cobranças: ok');
        $pixAuth = $settings->last_connection_test_at && str_contains($message, 'Pix: ok');

        return [
            'authentication' => $settings->last_connection_test_at
                ? ($chargesAuth ? 'API Cobranças: OAuth confirmado.' : 'API Cobranças: autenticação falhou; confira Client ID, Client Secret, ambiente e escopo da API de Emissão.')
                : 'API Cobranças: autenticação ainda não testada neste ambiente.',
            'billet' => ! $settings->chargesConfigured()
                ? 'Boleto: informe Client ID e Client Secret da API Cobranças.'
                : ($chargesAuth ? 'Boleto: credenciais autenticadas; emissão ainda precisa ser testada em homologação.' : 'Boleto: teste a conexão da API Cobranças e confirme a habilitação da API de Emissão.'),
            'card' => ! $settings->chargesConfigured()
                ? 'Cartão: informe Client ID e Client Secret da API Cobranças.'
                : (! filled($settings->efi_payee_code)
                    ? 'Cartão: informe o Identificador de conta em API > Introdução na Efí.'
                    : ($chargesAuth ? 'Cartão: credenciais autenticadas; confirme o Identificador de conta e teste a tokenização em homologação.' : 'Cartão: teste a conexão da API Cobranças e confirme o Identificador de conta da Efí.')),
            'pix' => ! $settings->pixConfigured()
                ? 'Pix: confira Client ID, Client Secret, certificado e chave Pix.'
                : ($pixAuth ? 'Pix: OAuth confirmado; confira o webhook antes de cobrar.' : 'Pix: teste a conexão da API Pix.'),
        ];
    }
}
