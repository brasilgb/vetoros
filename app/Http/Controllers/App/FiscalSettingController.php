<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyCompanyService;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Cadastro fiscal do tenant e habilitação da emissão nativa. */
class FiscalSettingController extends Controller
{
    /** Valores de ServiceInvoiceTaxationType da API da Spedy. */
    private const NFSE_TAXATION_TYPES = [
        'taxationInMunicipality',
        'taxationOutsideMunicipality',
        'exemption',
        'immune',
        'suspendedByCourt',
        'suspendedByAdministrativeProcedure',
        'exportation',
        'nonIncidence',
    ];

    /** Campos cuja alteração invalida a confirmação tributária. */
    private const TAX_FIELDS = [
        'company_tax_regime',
        'service_list_item',
        'default_iss_rate',
        'nfse_taxation_type',
        'default_commercial_unit',
        'default_icms_origin',
        'default_icms_situation',
        'default_pis_situation',
        'default_cofins_situation',
    ];

    public function __construct(
        private readonly SpedyCompanyService $companies,
        private readonly NativeFiscalService $emission,
    ) {}

    public function show(): Response
    {
        Gate::authorize('other-settings.access');

        $setting = $this->setting();
        $tenant = Tenant::query()->findOrFail($setting->tenant_id);

        return Inertia::render('app/fiscal-settings/index', [
            'platformAvailable' => SpedyClient::isConfigured(),
            'tenantAllowed' => (bool) $tenant->automatic_fiscal_emission_enabled,
            'setting' => [
                'enabled' => (bool) $setting->enabled,
                'registration_status' => $setting->registration_status,
                'registration_error' => $setting->registration_error,
                'registered_at' => $setting->registered_at?->toIso8601String(),
                'emission_environment' => $setting->emission_environment,
                'certificate_subject' => $setting->certificate_subject,
                'certificate_expires_at' => $setting->certificate_expires_at?->toIso8601String(),
                'company_tax_regime' => $setting->company_tax_regime,
                'state_registration' => $setting->state_registration,
                'municipal_registration' => $setting->municipal_registration,
                'service_city_code' => $setting->service_city_code,
                'service_list_item' => $setting->service_list_item,
                'default_iss_rate' => $setting->default_iss_rate !== null ? (float) $setting->default_iss_rate : null,
                'nfe_enabled' => (bool) $setting->nfe_enabled,
                'nfce_enabled' => (bool) $setting->nfce_enabled,
                'nfse_enabled' => (bool) $setting->nfse_enabled,
                'nfse_mode' => $setting->nfse_mode ?? 'national',
                'default_nfe_series' => $setting->default_nfe_series,
                'default_nfce_series' => $setting->default_nfce_series,
                'default_nfse_series' => $setting->default_nfse_series,
                'nfce_csc_id' => $setting->nfce_csc_id,
                'nfce_csc_set' => filled($setting->nfce_csc),
                'default_commercial_unit' => $setting->default_commercial_unit,
                'default_icms_origin' => $setting->default_icms_origin,
                'default_icms_situation' => $setting->default_icms_situation,
                'default_pis_situation' => $setting->default_pis_situation,
                'default_cofins_situation' => $setting->default_cofins_situation,
                'nfse_taxation_type' => $setting->nfse_taxation_type,
                'tax_settings_confirmed_at' => $setting->tax_settings_confirmed_at?->toIso8601String(),
                // Liberações do RootAdmin (somente leitura para o tenant).
                'nfe_allowed' => (bool) $setting->nfe_allowed,
                'nfce_allowed' => (bool) $setting->nfce_allowed,
                'nfse_allowed' => (bool) $setting->nfse_allowed,
                'production_released_at' => $setting->production_released_at?->toIso8601String(),
            ],
            'blockers' => [
                SpedyClient::MODEL_NFE => $this->emission->blocker($setting->tenant_id, SpedyClient::MODEL_NFE),
                SpedyClient::MODEL_NFCE => $this->emission->blocker($setting->tenant_id, SpedyClient::MODEL_NFCE),
                SpedyClient::MODEL_NFSE => $this->emission->blocker($setting->tenant_id, SpedyClient::MODEL_NFSE),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('other-settings.access');

        $data = $request->validate([
            'company_tax_regime' => ['nullable', Rule::in(['1', '2', '3', '4'])],
            'state_registration' => 'nullable|string|max:50',
            'municipal_registration' => 'nullable|string|max:50',
            'service_city_code' => ['nullable', 'regex:/^\d{7}$/'],
            'service_list_item' => 'nullable|string|max:30',
            'default_iss_rate' => 'nullable|numeric|min:0|max:10',
            'emission_environment' => ['required', Rule::in([FiscalSetting::ENVIRONMENT_HOMOLOGATION, FiscalSetting::ENVIRONMENT_PRODUCTION])],
            'nfe_enabled' => 'boolean',
            'nfce_enabled' => 'boolean',
            'nfse_enabled' => 'boolean',
            'nfse_mode' => ['nullable', Rule::in(['national', 'municipal'])],
            'default_nfe_series' => ['nullable', 'regex:/^\d{1,3}$/'],
            'default_nfce_series' => ['nullable', 'regex:/^\d{1,3}$/'],
            'default_nfse_series' => 'nullable|string|max:20',
            'nfce_csc_id' => ['nullable', 'regex:/^\d{1,6}$/'],
            'nfce_csc' => 'nullable|string|max:64',
            'default_commercial_unit' => 'nullable|string|max:6',
            'default_icms_origin' => ['nullable', 'regex:/^[0-8]$/'],
            'default_icms_situation' => ['nullable', 'regex:/^\d{2,3}$/'],
            'default_pis_situation' => ['nullable', 'regex:/^\d{2}$/'],
            'default_cofins_situation' => ['nullable', 'regex:/^\d{2}$/'],
            'nfse_taxation_type' => ['nullable', Rule::in(self::NFSE_TAXATION_TYPES)],
            'tax_settings_confirmed' => 'boolean',
        ], [
            'service_city_code.regex' => 'O código IBGE do município deve ter 7 dígitos.',
        ]);

        // CSC é somente escrita: em branco mantém o atual.
        if (blank($data['nfce_csc'] ?? null)) {
            unset($data['nfce_csc']);
        }

        $setting = $this->setting();

        if ($data['emission_environment'] === FiscalSetting::ENVIRONMENT_PRODUCTION && $setting->production_released_at === null) {
            return back()->withErrors(['emission_environment' => 'A emissão em produção depende da aprovação da administração após a homologação.']);
        }

        $confirmed = (bool) ($data['tax_settings_confirmed'] ?? false);
        unset($data['tax_settings_confirmed']);

        $setting->fill($data);

        // Qualquer mudança tributária exige nova confirmação explícita do tenant.
        if ($confirmed) {
            $setting->tax_settings_confirmed_at = now();
            $setting->tax_settings_confirmed_by = auth()->id();
        } elseif ($setting->isDirty(self::TAX_FIELDS)) {
            $setting->tax_settings_confirmed_at = null;
            $setting->tax_settings_confirmed_by = null;
        }

        // Ativar um modelo de nota ativa também o módulo fiscal.
        if ($setting->nfe_enabled || $setting->nfce_enabled || $setting->nfse_enabled) {
            $setting->enabled = true;
        }

        $setting->save();

        if (! $setting->isRegisteredOnSpedy()) {
            return back()->with('success', 'Configurações fiscais salvas.');
        }

        try {
            // Só as configurações de emissão; o cadastro da empresa é comandado pelo RootAdmin.
            $this->companies->syncSettings($setting);
        } catch (SpedyException $exception) {
            return back()->with('error', 'Configurações salvas, mas não sincronizadas com o emissor: '.$exception->getMessage());
        }

        return back()->with('success', 'Configurações fiscais salvas e sincronizadas com o emissor.');
    }

    public function certificate(Request $request): RedirectResponse
    {
        Gate::authorize('other-settings.access');

        $validated = $request->validate([
            'certificate' => 'required|file|max:2048|extensions:pfx,p12',
            'password' => 'required|string|max:255',
        ], [
            'certificate.extensions' => 'Envie o certificado A1 no formato .pfx ou .p12.',
        ]);

        try {
            $setting = $this->companies->uploadCertificate($this->setting(), $validated['certificate'], $validated['password']);
        } catch (SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $setting->certificate_expires_at
            ? 'Certificado enviado. Válido até '.$setting->certificate_expires_at->format('d/m/Y').'.'
            : 'Certificado enviado.');
    }

    private function setting(): FiscalSetting
    {
        $tenantId = resolveCurrentTenantId();
        abort_if($tenantId === null, 403);

        return FiscalSetting::query()->firstOrCreate(['tenant_id' => $tenantId], [
            'enabled' => false,
            'provider' => FiscalSetting::PROVIDER_MANUAL,
            'environment' => 'production',
            'nfse_mode' => 'national',
        ]);
    }
}
