<?php

namespace App\Http\Controllers\Admin\Fiscal;

use App\Http\Controllers\Controller;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\App\Company;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyCompanyService;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** RootAdmin → Fiscal → Empresas emissoras: habilitação, liberação por modelo e cadastro remoto. */
class FiscalCompanyController extends Controller
{
    private const MODELS = [SpedyClient::MODEL_NFE, SpedyClient::MODEL_NFCE, SpedyClient::MODEL_NFSE];

    public function __construct(private readonly SpedyCompanyService $companies) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $platformConfigured = SpedyClient::isConfigured();

        $tenants = Tenant::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('company', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('cnpj', 'like', '%'.preg_replace('/\D+/', '', $search).'%')))
            ->orderBy('company')
            ->paginate(20)
            ->withQueryString();

        $settings = FiscalSetting::query()->withoutGlobalScopes()->whereIn('tenant_id', $tenants->pluck('id'))->get()->keyBy('tenant_id');
        $companies = Company::query()->withoutGlobalScopes()->whereIn('tenant_id', $tenants->pluck('id'))->get(['tenant_id', 'companyname', 'cnpj'])->keyBy('tenant_id');

        $homologation = FiscalDocument::query()->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->where('provider', FiscalSetting::PROVIDER_SPEDY)
            ->where('status', FiscalDocument::STATUS_AUTHORIZED)
            ->where('environment', '!=', 'production')
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        $tenants->through(function (Tenant $tenant) use ($settings, $companies, $platformConfigured, $homologation) {
            $setting = $settings->get($tenant->id) ?? new FiscalSetting(['tenant_id' => $tenant->id]);
            $company = $companies->get($tenant->id);
            $allowed = (bool) $tenant->automatic_fiscal_emission_enabled;

            return [
                'id' => $tenant->id,
                'name' => $company?->companyname ?: ($tenant->company ?: $tenant->name),
                'cnpj' => preg_replace('/\D+/', '', (string) ($company?->cnpj ?: $tenant->cnpj)),
                'emission_enabled' => $allowed,
                'allowed' => collect(self::MODELS)->mapWithKeys(fn ($model) => [$model => $setting->isModelAllowed($model)]),
                'tenant_enabled' => ['nfe' => (bool) $setting->nfe_enabled, 'nfce' => (bool) $setting->nfce_enabled, 'nfse' => (bool) $setting->nfse_enabled],
                'registration_status' => $setting->registration_status ?? FiscalSetting::REGISTRATION_PENDING,
                'registration_error' => $setting->registration_error,
                'registered_at' => $setting->registered_at?->toIso8601String(),
                'certificate_subject' => $setting->certificate_subject,
                'certificate_expires_at' => $setting->certificate_expires_at?->toIso8601String(),
                'emission_environment' => $setting->emission_environment ?? FiscalSetting::ENVIRONMENT_HOMOLOGATION,
                'production_released_at' => $setting->production_released_at?->toIso8601String(),
                'tax_settings_confirmed_at' => $setting->tax_settings_confirmed_at?->toIso8601String(),
                'blockers' => $platformConfigured
                    ? collect(self::MODELS)->mapWithKeys(fn ($model) => [$model => $setting->nativeEmissionBlocker($model, $allowed)])
                    : null,
                // Indicador simples de homologação: notas autorizadas fora de produção.
                'homologation_authorized' => (int) ($homologation[$tenant->id] ?? 0),
            ];
        });

        return Inertia::render('admin/fiscal/companies', [
            'tenants' => $tenants,
            'search' => $search,
            'platformConfigured' => $platformConfigured,
        ]);
    }

    /** Habilita/bloqueia a emissão, libera modelos e aprova produção. Produção exige a senha do RootAdmin. */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'emission_enabled' => 'required|boolean',
            'nfe_allowed' => 'required|boolean',
            'nfce_allowed' => 'required|boolean',
            'nfse_allowed' => 'required|boolean',
            'production_released' => 'required|boolean',
            'password' => 'nullable|string',
        ]);

        $setting = $this->setting($tenant);
        $releasingProduction = $data['production_released'] && $setting->production_released_at === null;

        if ($releasingProduction) {
            $request->validate(['password' => 'required|current_password'], [
                'password.required' => 'Informe sua senha para aprovar a emissão em produção.',
                'password.current_password' => 'Senha incorreta.',
            ]);
        }

        $before = [
            'emission_enabled' => (bool) $tenant->automatic_fiscal_emission_enabled,
            'nfe_allowed' => (bool) $setting->nfe_allowed,
            'nfce_allowed' => (bool) $setting->nfce_allowed,
            'nfse_allowed' => (bool) $setting->nfse_allowed,
            'production_released' => $setting->production_released_at !== null,
        ];

        $tenant->forceFill(['automatic_fiscal_emission_enabled' => $data['emission_enabled']])->save();
        $setting->forceFill([
            'nfe_allowed' => $data['nfe_allowed'],
            'nfce_allowed' => $data['nfce_allowed'],
            'nfse_allowed' => $data['nfse_allowed'],
            'production_released_at' => $data['production_released'] ? ($setting->production_released_at ?? now()) : null,
            'production_released_by' => $data['production_released'] ? ($setting->production_released_by ?? auth()->id()) : null,
        ])->save();

        $after = collect($data)->except('password')->map(fn ($value) => (bool) $value)->all();
        $changed = array_keys(array_diff_assoc($after, $before));

        if ($changed !== []) {
            FiscalAdminAudit::record(
                ! $after['emission_enabled'] && $before['emission_enabled'] ? 'company.emission_blocked' : 'company.emission_updated',
                $tenant->id,
                $setting,
                ['changed' => $changed, 'after' => $after],
            );
        }

        return back()->with('success', 'Habilitação fiscal atualizada.');
    }

    /** Cadastro remoto do emitente na conta Spedy (chave titular), depois de validados os dados do cliente. */
    public function register(Tenant $tenant): RedirectResponse
    {
        if (! $tenant->automatic_fiscal_emission_enabled) {
            return back()->with('error', 'Habilite a emissão desta empresa antes de cadastrá-la na Spedy.');
        }

        $setting = $this->setting($tenant);
        $firstRegistration = blank($setting->spedy_company_id);

        try {
            $this->companies->sync($setting);
        } catch (FiscalValidationException|SpedyException $exception) {
            FiscalAdminAudit::record('company.registration_failed', $tenant->id, $setting, ['message' => mb_substr($exception->getMessage(), 0, 300)]);

            return back()->with('error', $exception->getMessage());
        }

        FiscalAdminAudit::record($firstRegistration ? 'company.registered' : 'company.registration_synced', $tenant->id, $setting);

        return back()->with('success', $firstRegistration ? 'Empresa cadastrada na Spedy.' : 'Cadastro da empresa sincronizado com a Spedy.');
    }

    /** Consulta explícita do cadastro e do certificado na Spedy (não ocorre ao abrir a tela). */
    public function check(Tenant $tenant): RedirectResponse
    {
        $setting = $this->setting($tenant);

        if (! $setting->isRegisteredOnSpedy()) {
            return back()->with('error', 'Empresa ainda não cadastrada na Spedy.');
        }

        try {
            SpedyClient::forOwner()->getCompany($setting->spedy_company_id);
            $certificates = SpedyClient::forCompany($setting->api_token)->listCertificates($setting->spedy_company_id);
        } catch (SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $items = array_is_list($certificates) ? $certificates : ($certificates['items'] ?? []);
        $active = collect($items)->first(fn ($certificate) => (bool) ($certificate['isActive'] ?? false));

        $setting->forceFill([
            'certificate_subject' => isset($active['subject']) ? mb_substr((string) $active['subject'], 0, 255) : $setting->certificate_subject,
            'certificate_expires_at' => isset($active['expirationAt']) ? Carbon::parse($active['expirationAt']) : $setting->certificate_expires_at,
        ])->save();

        FiscalAdminAudit::record('company.checked', $tenant->id, $setting, ['active_certificate' => $active !== null]);

        return back()->with('success', $active ? 'Cadastro confirmado na Spedy; certificado ativo até '.Carbon::parse($active['expirationAt'])->format('d/m/Y').'.' : 'Cadastro confirmado na Spedy, sem certificado ativo.');
    }

    private function setting(Tenant $tenant): FiscalSetting
    {
        return FiscalSetting::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id], [
            'enabled' => false,
            'provider' => FiscalSetting::PROVIDER_MANUAL,
            'environment' => 'production',
            'nfse_mode' => 'national',
        ]);
    }
}
