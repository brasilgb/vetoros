<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\Company;
use App\Models\App\FiscalSetting;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Support\TenantMailConfig;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OtherController extends Controller
{
    private function currentTenantId(): ?int
    {
        return Auth::user()?->tenant_id ? (int) Auth::user()->tenant_id : null;
    }

    public function index()
    {
        Gate::authorize('other-settings.access');

        $tenantId = $this->currentTenantId();
        $othersettings = Other::query()->firstOrCreate([
            'tenant_id' => $tenantId,
        ], [
            'enable_finance' => false,
            'enablesales' => false,
            'enable_purchases' => false,
            'show_follow_ups_menu' => false,
            'show_tasks_menu' => false,
            'show_commercial_performance_menu' => false,
            'show_quality_menu' => false,
            'print_label_button_after_order_create' => false,
            'public_order_access_key_required' => false,
            'automatic_follow_ups_enabled' => false,
            'enable_technician_schedule_notifications' => false,
            'records_per_page' => 20,
        ]);
        $company = Company::query()
            ->where('tenant_id', $tenantId)
            ->first();
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;
        $fiscalSetting = $tenantId ? FiscalSetting::query()->firstOrCreate([
            'tenant_id' => $tenantId,
        ], [
            'enabled' => false,
            'provider' => 'manual',
            'environment' => 'production',
            'nfe_enabled' => false,
            'nfse_enabled' => false,
            'nfse_mode' => 'national',
        ]) : null;
        $time_remaining = '';

        if ($tenant?->expires_at) {
            $expiresAt = Carbon::parse($tenant->expires_at);
            $diff = Carbon::now()->diff($expiresAt);
            $time_remaining = ',  '.$diff->days.' dias restantes';
        }

        $mailSettings = [
            'mail_mailer' => $othersettings->mail_mailer ?? 'smtp',
            'mail_host' => $othersettings->mail_host ?? '',
            'mail_port' => $othersettings->mail_port ?? 587,
            'mail_username' => $othersettings->mail_username ?? '',
            'mail_password' => '',
            'mail_encryption' => $othersettings->mail_encryption ?? 'tls',
            'mail_from_address' => $othersettings->mail_from_address ?? '',
            'mail_from_name' => $othersettings->mail_from_name ?? config('app.name'),
            'mail_password_set' => ! empty($othersettings->mail_password),
        ];
        $businessMetrics = [
            'warranty_return_alert_threshold' => $othersettings->warranty_return_alert_threshold
                ?? config('business-metrics.warranty_return_alert_threshold', 10),
            'communication_follow_up_cooldown_days' => $othersettings->communication_follow_up_cooldown_days
                ?? Other::communicationFollowUpCooldownDays($tenantId),
            'automatic_follow_ups_enabled' => $othersettings->automatic_follow_ups_enabled
                ?? Other::automaticFollowUpsEnabled($tenantId),
            'enable_technician_schedule_notifications' => $othersettings->enable_technician_schedule_notifications
                ?? Other::technicianScheduleNotificationsEnabled($tenantId),
            'customer_feedback_request_delay_days' => $othersettings->customer_feedback_request_delay_days
                ?? Other::customerFeedbackRequestDelayDays($tenantId),
            'budget_conversion_target' => $othersettings->budget_conversion_target
                ?? Other::budgetConversionTarget($tenantId),
            'payment_recovery_target' => $othersettings->payment_recovery_target
                ?? Other::paymentRecoveryTarget($tenantId),
            'records_per_page' => $othersettings->records_per_page
                ?? Other::recordsPerPage($tenantId),
            'records_per_page_options' => Other::ALLOWED_RECORDS_PER_PAGE,
        ];

        return Inertia::render('app/others/index', [
            'othersettings' => $othersettings,
            'company' => $company,
            'time_remaining' => $time_remaining,
            // A relação tenant.plan não vem em auth.user para todos os papéis.
            'licensePlanName' => $tenant?->plan?->name,
            'mailSettings' => $mailSettings,
            'businessMetrics' => $businessMetrics,
            'fiscalSetting' => $fiscalSetting ? [
                'enabled' => (bool) $fiscalSetting->enabled,
                'nfe_enabled' => (bool) $fiscalSetting->nfe_enabled,
                'nfse_enabled' => (bool) $fiscalSetting->nfse_enabled,
            ] : null,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Other $other): RedirectResponse
    {
        Gate::authorize('other-settings.access');

        abort_if((int) $other->tenant_id !== (int) $this->currentTenantId(), 403);

        $data = $request->validate([
            'navigation' => 'sometimes|boolean',
            'budget' => 'sometimes|boolean',
            'enableparts' => 'sometimes|boolean',
            'enable_finance' => 'sometimes|boolean',
            'enablesales' => 'sometimes|boolean',
            'enable_purchases' => 'sometimes|boolean',
            'show_follow_ups_menu' => 'sometimes|boolean',
            'show_quality_menu' => 'sometimes|boolean',
            'print_label_button_after_order_create' => 'sometimes|boolean',
            'public_order_access_key_required' => 'sometimes|boolean',
            'mail_mailer' => 'nullable|string|max:30',
            'mail_host' => 'nullable|string|max:255',
            'mail_port' => 'nullable|integer|min:1|max:65535',
            'mail_username' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:255',
            'mail_encryption' => 'nullable|string|max:20',
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:255',
            'warranty_return_alert_threshold' => 'nullable|numeric|min:0|max:100',
            'communication_follow_up_cooldown_days' => 'nullable|integer|min:1|max:30',
            'automatic_follow_ups_enabled' => 'sometimes|boolean',
            'enable_technician_schedule_notifications' => 'sometimes|boolean',
            'customer_feedback_request_delay_days' => 'nullable|integer|min:1|max:30',
            'budget_conversion_target' => 'nullable|numeric|min:0|max:100',
            'payment_recovery_target' => 'nullable|numeric|min:0|max:100',
            'records_per_page' => ['sometimes', 'required', 'integer', Rule::in(Other::ALLOWED_RECORDS_PER_PAGE)],
            'fiscal_enabled' => 'sometimes|boolean',
            'fiscal_nfe_enabled' => 'sometimes|boolean',
            'fiscal_nfse_enabled' => 'sometimes|boolean',
        ]);

        $data['mail_mailer'] = isset($data['mail_mailer']) ? trim((string) $data['mail_mailer']) : null;
        $data['mail_host'] = isset($data['mail_host']) ? trim((string) $data['mail_host']) : null;
        $data['mail_username'] = isset($data['mail_username']) ? trim((string) $data['mail_username']) : null;
        $data['mail_encryption'] = isset($data['mail_encryption']) ? trim((string) $data['mail_encryption']) : null;
        $data['mail_from_address'] = isset($data['mail_from_address']) ? trim((string) $data['mail_from_address']) : null;
        $data['mail_from_name'] = isset($data['mail_from_name']) ? trim((string) $data['mail_from_name']) : null;
        $data['warranty_return_alert_threshold'] = isset($data['warranty_return_alert_threshold'])
            ? round((float) $data['warranty_return_alert_threshold'], 2)
            : null;
        $data['communication_follow_up_cooldown_days'] = isset($data['communication_follow_up_cooldown_days'])
            ? max(1, (int) $data['communication_follow_up_cooldown_days'])
            : null;
        $data['customer_feedback_request_delay_days'] = isset($data['customer_feedback_request_delay_days'])
            ? max(1, (int) $data['customer_feedback_request_delay_days'])
            : null;
        $data['budget_conversion_target'] = isset($data['budget_conversion_target'])
            ? round((float) $data['budget_conversion_target'], 2)
            : null;
        $data['payment_recovery_target'] = isset($data['payment_recovery_target'])
            ? round((float) $data['payment_recovery_target'], 2)
            : null;

        $newPassword = trim((string) ($data['mail_password'] ?? ''));
        if ($newPassword !== '') {
            $data['mail_password'] = Crypt::encryptString($newPassword);
        } else {
            unset($data['mail_password']);
        }

        $fiscalPayload = [
            'enabled' => (bool) ($data['fiscal_enabled'] ?? false),
            'nfe_enabled' => (bool) ($data['fiscal_nfe_enabled'] ?? false),
            'nfse_enabled' => (bool) ($data['fiscal_nfse_enabled'] ?? false),
        ];

        foreach (array_keys($fiscalPayload) as $key) {
            unset($data['fiscal_'.$key]);
        }

        $other->update($data);

        FiscalSetting::query()->updateOrCreate(
            ['tenant_id' => $this->currentTenantId()],
            $fiscalPayload
        );

        return redirect()->route('app.other-settings.index', ['other' => $other->id])->with('success', 'Configurações alteradas com sucesso');
    }

    public function sendTestMail(Other $other): RedirectResponse
    {
        Gate::authorize('other-settings.access');

        abort_if((int) $other->tenant_id !== (int) $this->currentTenantId(), 403);

        $company = Company::query()
            ->where('tenant_id', $this->currentTenantId())
            ->first();
        $targetEmail = trim((string) ($company?->email ?? ''));

        if ($targetEmail === '' || ! filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'Cadastre um e-mail válido nos dados da empresa para enviar o teste SMTP.');
        }

        if (! TenantMailConfig::hasConfiguredForTenantId($this->currentTenantId())) {
            return back()->with('error', 'Configure host, porta, usuário e senha SMTP antes de enviar o teste.');
        }

        try {
            TenantMailConfig::applyForTenantId($this->currentTenantId());

            Mail::raw(
                "Este é um e-mail de teste da configuração SMTP do sistema.\n\nEmpresa: ".($company?->companyname ?? config('app.name'))."\nData/Hora: ".now()->format('d/m/Y H:i:s'),
                function ($message) use ($targetEmail, $company) {
                    $message->to($targetEmail)
                        ->subject('Teste de configuração SMTP - '.($company?->companyname ?? config('app.name')));
                }
            );

            return back()->with('message', "E-mail de teste enviado com sucesso para {$targetEmail}.");
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Falha ao enviar e-mail de teste. Verifique host, porta, usuário, senha e criptografia SMTP.');
        }
    }
}
