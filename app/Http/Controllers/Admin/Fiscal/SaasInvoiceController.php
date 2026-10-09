<?php

namespace App\Http\Controllers\Admin\Fiscal;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\SaasInvoiceService;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** RootAdmin → Fiscal → Notas do SaaS (NFS-e da ABrasil Sistemas para os clientes contratantes). */
class SaasInvoiceController extends Controller
{
    private const NFSE_TAXATION_TYPES = [
        'taxationInMunicipality', 'taxationOutsideMunicipality', 'exemption', 'immune',
        'suspendedByCourt', 'suspendedByAdministrativeProcedure', 'exportation', 'nonIncidence',
    ];

    public function __construct(private readonly SaasInvoiceService $service) {}

    public function index(Request $request): Response
    {
        $issuer = AdminFiscalSetting::current();
        $tenantId = $request->integer('tenant_id') ?: null;
        $tenant = $tenantId ? Tenant::query()->with('plan:id,name', 'period:id,name')->find($tenantId) : null;

        $payments = $tenant
            ? Payment::query()->where('tenant_id', $tenant->id)->where('status', 'approved')->latest('id')->limit(24)->get()
                ->map(function (Payment $payment) {
                    $context = $this->service->paymentContext($payment);
                    $documents = AdminFiscalDocument::query()->where('payment_id', $payment->id)->where('provider', 'spedy')->latest('id')->get(['id', 'status', 'number']);

                    return [
                        'id' => $payment->id,
                        'gateway' => $payment->gateway,
                        'amount' => (float) $payment->amount,
                        'paid_at' => $context['paid_at']->toIso8601String(),
                        'plan_name' => $context['plan_name'],
                        'billing_months' => $context['billing_months'],
                        'suggested_start' => $context['suggested_start']->toDateString(),
                        'suggested_end' => $context['suggested_end']?->toDateString(),
                        'documents' => $documents,
                    ];
                })
            : collect();

        $documents = AdminFiscalDocument::query()
            ->where('provider', 'spedy')
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id))
            ->with(['tenant:id,company,name', 'deliveries.sentBy:id,name'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AdminFiscalDocument $document) => [
                ...$document->only(['id', 'tenant_id', 'payment_id', 'status', 'number', 'environment', 'error_message']),
                'tenant' => $document->tenant?->company ?: $document->tenant?->name,
                'amount' => (float) $document->amount,
                'reference_start' => $document->reference_start?->toDateString(),
                'reference_end' => $document->reference_end?->toDateString(),
                'issued_at' => $document->issued_at?->toIso8601String(),
                'can_cancel' => $document->status === 'authorized',
                'has_file' => in_array($document->status, ['authorized', 'cancelled'], true),
                'deliveries' => $document->deliveries->map(fn ($delivery) => [
                    'email' => $delivery->email,
                    'status' => $delivery->status,
                    'error' => $delivery->error,
                    'sent_by' => $delivery->sentBy?->name,
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ]),
            ]);

        return Inertia::render('admin/fiscal/saas', [
            'issuer' => [
                ...$issuer->only([
                    'enabled', 'legal_name', 'trade_name', 'cnpj', 'municipal_registration', 'tax_regime', 'zip_code', 'state', 'city',
                    'district', 'street', 'number', 'complement', 'email', 'service_city_code', 'service_list_item', 'nfse_taxation_type',
                    'nfse_mode', 'default_nfse_series', 'default_service_description', 'registration_status', 'registration_error',
                    'emission_environment', 'certificate_subject',
                ]),
                'default_iss_rate' => $issuer->default_iss_rate !== null ? (float) $issuer->default_iss_rate : null,
                'certificate_expires_at' => $issuer->certificate_expires_at?->toIso8601String(),
                'tax_settings_confirmed_at' => $issuer->tax_settings_confirmed_at?->toIso8601String(),
                'production_released_at' => $issuer->production_released_at?->toIso8601String(),
                'problems' => $this->service->issuerProblems($issuer),
                'blocker' => $this->service->blocker($issuer),
            ],
            'tenants' => Tenant::query()->orderBy('company')->get(['id', 'company', 'name', 'cnpj']),
            'selectedTenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->company ?: $tenant->name,
                'cnpj' => $tenant->cnpj,
                'email' => $tenant->email,
                'plan' => $tenant->plan?->name,
                'period' => $tenant->period?->name,
                'subscription_status' => $tenant->subscription_status,
                'expires_at' => $tenant->expires_at?->toIso8601String(),
                'receiver_problems' => $this->service->receiverProblems($tenant),
                'receiver' => $this->service->receiverPreview($tenant),
            ] : null,
            'payments' => $payments,
            'documents' => $documents,
        ]);
    }

    public function updateIssuer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => 'boolean',
            'legal_name' => 'nullable|string|max:120',
            'trade_name' => 'nullable|string|max:120',
            'cnpj' => 'nullable|string|max:20',
            'municipal_registration' => 'nullable|string|max:50',
            'tax_regime' => ['nullable', Rule::in(['1', '2', '3', '4'])],
            'zip_code' => 'nullable|string|max:20',
            'state' => 'nullable|string|size:2',
            'city' => 'nullable|string|max:80',
            'district' => 'nullable|string|max:80',
            'street' => 'nullable|string|max:120',
            'number' => 'nullable|string|max:20',
            'complement' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:120',
            'service_city_code' => ['nullable', 'regex:/^\d{7}$/'],
            'service_list_item' => 'nullable|string|max:30',
            'default_iss_rate' => 'nullable|numeric|min:0|max:10',
            'nfse_taxation_type' => ['nullable', Rule::in(self::NFSE_TAXATION_TYPES)],
            'nfse_mode' => ['nullable', Rule::in(['national', 'municipal'])],
            'default_nfse_series' => 'nullable|string|max:20',
            'default_service_description' => 'nullable|string|max:300',
            'emission_environment' => ['required', Rule::in(['homologation', 'production'])],
            'tax_settings_confirmed' => 'boolean',
            'production_released' => 'boolean',
            'password' => 'nullable|string',
        ]);

        $issuer = AdminFiscalSetting::current();
        $sensitive = ($data['production_released'] ?? false) && $issuer->production_released_at === null;

        if ($sensitive) {
            $request->validate(['password' => 'required|current_password'], ['password.required' => 'Informe sua senha para aprovar produção.', 'password.current_password' => 'Senha incorreta.']);
        }

        $taxFields = ['tax_regime', 'service_list_item', 'default_iss_rate', 'nfse_taxation_type'];
        $issuer->fill(collect($data)->except(['tax_settings_confirmed', 'production_released', 'password'])->all());

        if ($data['tax_settings_confirmed'] ?? false) {
            $issuer->tax_settings_confirmed_at = now();
        } elseif ($issuer->isDirty($taxFields)) {
            $issuer->tax_settings_confirmed_at = null;
        }

        $issuer->production_released_at = ($data['production_released'] ?? false) ? ($issuer->production_released_at ?? now()) : null;

        $changed = array_keys($issuer->getDirty());
        $issuer->save();

        FiscalAdminAudit::record('saas.issuer_updated', subject: $issuer, data: ['changed' => $changed]);

        return back()->with('success', 'Emitente da plataforma atualizado.');
    }

    public function registerIssuer(): RedirectResponse
    {
        $issuer = AdminFiscalSetting::current();

        try {
            $this->service->registerIssuer($issuer);
        } catch (FiscalValidationException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        FiscalAdminAudit::record('saas.issuer_registered', subject: $issuer);

        return back()->with('success', 'Emitente da plataforma cadastrado/sincronizado na Spedy.');
    }

    public function issuerCertificate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'certificate' => 'required|file|max:2048|extensions:pfx,p12',
            'password' => 'required|string|max:255',
        ]);

        try {
            $issuer = $this->service->uploadIssuerCertificate(AdminFiscalSetting::current(), $validated['certificate'], $validated['password']);
        } catch (SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        FiscalAdminAudit::record('saas.issuer_certificate_uploaded', subject: $issuer, data: ['expires_at' => $issuer->certificate_expires_at?->toDateString()]);

        return back()->with('success', 'Certificado do emitente enviado.');
    }

    public function emit(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'reference_start' => 'required|date',
            'reference_end' => 'required|date|after_or_equal:reference_start',
            'description' => 'nullable|string|max:500',
        ]);

        try {
            $document = $this->service->emit($payment, Carbon::parse($data['reference_start']), Carbon::parse($data['reference_end']), $data['description'] ?? null, (int) auth()->id());
        } catch (FiscalEmissionException|FiscalValidationException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return match ($document->status) {
            'authorized' => back()->with('success', 'NFS-e autorizada.'),
            'rejected', 'denied' => back()->with('error', 'NFS-e recusada: '.$document->error_message),
            default => back()->with('success', 'NFS-e enviada para autorização.'),
        };
    }

    public function refresh(AdminFiscalDocument $document): RedirectResponse
    {
        try {
            $this->service->refresh($document);
        } catch (SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Situação da NFS-e atualizada.');
    }

    public function cancel(Request $request, AdminFiscalDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:15|max:255',
            'password' => 'required|current_password',
        ], ['password.current_password' => 'Senha incorreta.']);

        try {
            $this->service->cancel($document, $data['reason']);
        } catch (FiscalEmissionException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Cancelamento solicitado.');
    }

    public function file(AdminFiscalDocument $document, string $format): HttpResponse
    {
        abort_unless(in_array($format, ['pdf', 'xml'], true), 404);

        try {
            $content = $this->service->download($document, $format);
        } catch (FiscalEmissionException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return response($content, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/xml',
            'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="nfse-saas-'.($document->number ?: $document->id).".{$format}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function send(Request $request, AdminFiscalDocument $document): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:190']);

        try {
            $delivery = $this->service->sendByEmail($document, $data['email'], (int) auth()->id());
        } catch (FiscalEmissionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $delivery->status === 'sent'
            ? back()->with('success', "NFS-e enviada para {$data['email']}.")
            : back()->with('error', 'Falha no envio do e-mail (registrada). A nota não foi reemitida.');
    }
}
