<?php

namespace App\Http\Controllers\Admin\Fiscal;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\SpedyPlatformSetting;
use App\Models\App\FiscalSetting;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\Fiscal\Spedy\SpedyPlatformConfig;
use App\Support\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** RootAdmin → Fiscal → Integração Spedy. A tela só lê o banco; a API só é chamada por ação explícita. */
class FiscalIntegrationController extends Controller
{
    private const WEBHOOK_EVENT = 'invoice.status_changed';

    public function show(): Response
    {
        $platform = SpedyPlatformSetting::current();

        return Inertia::render('admin/fiscal/integration', [
            'integration' => [
                'environment' => SpedyPlatformConfig::environment(),
                'environment_source' => SpedyPlatformConfig::source('environment'),
                'owner_key_configured' => SpedyClient::isConfigured(),
                'owner_key_source' => SpedyPlatformConfig::source('owner_api_key'),
                'owner_key_last4' => $platform->owner_api_key_last4,
                'owner_key_rotated_at' => $platform->owner_api_key_rotated_at?->toIso8601String(),
                'webhook_secret_configured' => filled(SpedyPlatformConfig::webhookSecret()),
                'webhook_secret_source' => SpedyPlatformConfig::source('webhook_secret'),
                'webhook_url' => $platform->webhook_url,
                'webhook_expected_url' => route('webhook.spedy'),
                'webhook_configured_at' => $platform->webhook_configured_at?->toIso8601String(),
                'last_diagnostic_at' => $platform->last_diagnostic_at?->toIso8601String(),
                'last_diagnostic_ok' => $platform->last_diagnostic_ok,
                'last_diagnostic_message' => $platform->last_diagnostic_message,
                'registered_companies' => FiscalSetting::query()->withoutGlobalScopes()->whereNotNull('spedy_company_id')->count(),
            ],
            'audits' => FiscalAdminAudit::query()
                ->with('user:id,name')
                ->whereIn('action', ['spedy.credentials_updated', 'spedy.environment_changed', 'spedy.diagnostic', 'spedy.webhook_configured'])
                ->latest('id')
                ->limit(20)
                ->get(['id', 'user_id', 'action', 'data', 'created_at']),
        ]);
    }

    /**
     * Chave titular (somente escrita) e ambiente. Exige a senha do RootAdmin.
     * Trocar de sandbox para produção descarta os cadastros de sandbox, que não
     * valem em outra conta; de produção para sandbox é bloqueado se houver
     * empresas cadastradas (as chaves de produção só são exibidas uma vez).
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'owner_api_key' => 'nullable|string|min:16|max:200',
            'password' => 'required|current_password',
            'confirm_environment_change' => 'boolean',
        ], [
            'password.current_password' => 'Senha incorreta.',
        ]);

        $platform = SpedyPlatformSetting::current();
        $previousEnvironment = SpedyPlatformConfig::environment();
        $environmentChanged = $data['environment'] !== $previousEnvironment;
        $registered = FiscalSetting::query()->withoutGlobalScopes()->whereNotNull('spedy_company_id')->count()
            + AdminFiscalSetting::query()->whereNotNull('spedy_company_id')->count();

        if ($environmentChanged && $registered > 0) {
            if ($previousEnvironment === 'production') {
                return back()->withErrors(['environment' => "Há {$registered} empresa(s) cadastrada(s) na conta de produção. Voltar para sandbox descartaria chaves que não podem ser recuperadas."]);
            }

            if (! ($data['confirm_environment_change'] ?? false)) {
                return back()->withErrors(['confirm_environment_change' => "Confirme: os {$registered} cadastro(s) de sandbox serão descartados e precisarão ser refeitos em produção."]);
            }
        }

        DB::transaction(function () use ($platform, $data, $environmentChanged, $previousEnvironment) {
            $changes = [];

            if (filled($data['owner_api_key'] ?? null)) {
                $key = trim((string) $data['owner_api_key']);
                $platform->forceFill([
                    'owner_api_key' => $key,
                    'owner_api_key_last4' => substr($key, -4),
                    'owner_api_key_rotated_at' => now(),
                ]);
                $changes['owner_api_key'] = 'final '.substr($key, -4);
            }

            if ($environmentChanged) {
                $platform->environment = $data['environment'];

                $reset = FiscalSetting::query()->withoutGlobalScopes()->whereNotNull('spedy_company_id')->update([
                    'spedy_company_id' => null,
                    'api_token' => null,
                    'registration_status' => FiscalSetting::REGISTRATION_PENDING,
                    'registration_error' => 'Cadastro descartado na troca de ambiente da plataforma.',
                    'certificate_subject' => null,
                    'certificate_expires_at' => null,
                    'production_released_at' => null,
                    'production_released_by' => null,
                ]);

                $reset += AdminFiscalSetting::query()->whereNotNull('spedy_company_id')->update([
                    'spedy_company_id' => null,
                    'api_token' => null,
                    'registration_status' => 'pending',
                    'registration_error' => 'Cadastro descartado na troca de ambiente da plataforma.',
                    'certificate_subject' => null,
                    'certificate_expires_at' => null,
                    'production_released_at' => null,
                ]);

                FiscalAdminAudit::record('spedy.environment_changed', data: ['from' => $previousEnvironment, 'to' => $data['environment'], 'registrations_reset' => $reset]);
            } elseif ($platform->environment === null) {
                $platform->environment = $data['environment'];
            }

            if (isset($changes['owner_api_key']) || $environmentChanged) {
                // Outra conta/ambiente: o webhook e o segredo precisam ser configurados de novo.
                $platform->forceFill(['webhook_secret' => null, 'webhook_id' => null, 'webhook_url' => null, 'webhook_configured_at' => null]);
            }

            $platform->updated_by = auth()->id();
            $platform->save();

            if ($changes !== []) {
                FiscalAdminAudit::record('spedy.credentials_updated', data: $changes);
            }
        });

        return back()->with('success', 'Integração Spedy atualizada.');
    }

    public function diagnose(): RedirectResponse
    {
        $platform = SpedyPlatformSetting::current();

        try {
            SpedyClient::forOwner()->listCompanies(1);
            [$ok, $message] = [true, 'Conexão com a Spedy ('.SpedyPlatformConfig::environment().') bem-sucedida.'];
        } catch (SpedyException $exception) {
            [$ok, $message] = [false, $exception->getMessage()];
        }

        $platform->forceFill(['last_diagnostic_at' => now(), 'last_diagnostic_ok' => $ok, 'last_diagnostic_message' => mb_substr($message, 0, 500)])->save();
        FiscalAdminAudit::record('spedy.diagnostic', data: ['ok' => $ok]);

        return back()->with($ok ? 'success' : 'error', $message);
    }

    /** Cria (ou reaproveita) o webhook invoice.status_changed e guarda o segredo de assinatura. */
    public function configureWebhook(Request $request): RedirectResponse
    {
        $request->validate(['password' => 'required|current_password'], ['password.current_password' => 'Senha incorreta.']);

        $url = route('webhook.spedy');

        // Pelo host, não por APP_ENV: produção rodando como "local" também exige HTTPS.
        if (! PublicUrl::isSecureOrLocal($url)) {
            return back()->with('error', 'A URL do webhook precisa ser HTTPS. Confira o APP_URL e o acesso pelo domínio com certificado.');
        }

        try {
            $client = SpedyClient::forOwner();
            $existing = collect($client->listWebhooks()['items'] ?? [])
                ->first(fn ($webhook) => ($webhook['url'] ?? null) === $url && ($webhook['event'] ?? null) === self::WEBHOOK_EVENT);
            $webhook = $existing ?? $client->createWebhook(self::WEBHOOK_EVENT, $url);
            $secret = $client->webhookSecret();
        } catch (SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($secret === null) {
            return back()->with('error', 'A Spedy não retornou o segredo de assinatura do webhook.');
        }

        SpedyPlatformSetting::current()->forceFill([
            'webhook_id' => $webhook['id'] ?? null,
            'webhook_url' => $url,
            'webhook_secret' => $secret,
            'webhook_configured_at' => now(),
            'updated_by' => auth()->id(),
        ])->save();

        FiscalAdminAudit::record('spedy.webhook_configured', data: ['url' => $url, 'reused' => $existing !== null]);

        return back()->with('success', $existing ? 'Webhook existente verificado e segredo atualizado.' : 'Webhook criado e segredo de assinatura guardado.');
    }
}
