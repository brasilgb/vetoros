<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\TenantCommercialContract;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\App\FiscalDocument;
use App\Models\Tenant;
use App\Services\Commercial\SaasContractService;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\SaasInvoiceService;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * RootAdmin → Empresas → Editar → "Condições comerciais e faturamento" (VETOR-SAAS-CONTRATO-01).
 * Rotas no grupo `admin` (só RootAdmin); o tenant não tem rota que altere estas condições.
 */
class TenantCommercialController extends Controller
{
    private const MONEY = ['required', 'regex:/^\d{1,10}([.,]\d{1,2})?$/', 'not_regex:/^0+([.,]0{1,2})?$/'];

    public function __construct(
        private readonly SaasContractService $contracts,
        private readonly SaasInvoiceService $invoices,
    ) {}

    /** Props da seção comercial na edição da empresa. */
    public static function pageData(Tenant $tenant): array
    {
        $contract = $tenant->commercialContract;
        $saas = app(SaasInvoiceService::class);
        $charges = TenantSubscriptionCharge::query()
            ->where('tenant_id', $tenant->id)
            ->with(['fiscalDocuments:id,tenant_subscription_charge_id,status,number,error_message', 'deliveries'])
            ->orderByDesc('cycle_start')
            ->limit(24)
            ->get();

        return [
            'billing_mode' => $tenant->billing_mode ?? Tenant::BILLING_PLAN,
            'periodicities' => collect(TenantCommercialContract::PERIODICITIES)->map(fn ($label, $months) => ['value' => $months, 'label' => $label])->values(),
            'contract' => $contract ? [
                'contract_number' => $contract->contract_number,
                'amount' => (string) $contract->amount,
                'billing_months' => $contract->billing_months,
                'periodicity_label' => $contract->periodicityLabel(),
                'start_date' => $contract->start_date->toDateString(),
                'next_due_date' => $contract->next_due_date->toDateString(),
                'sales_rep' => $contract->sales_rep,
                'notes' => $contract->notes,
                'active' => $contract->active,
                'scheduled_revert' => $contract->active && $contract->revert_scheduled_for ? [
                    'effective_date' => $contract->revert_scheduled_for->toDateString(),
                    'plan_name' => $contract->revertPlan?->name,
                ] : null,
            ] : null,
            // Sugestão para a data efetiva da volta ao plano: fim do período já coberto, nunca antes de hoje.
            'suggested_revert_date' => collect([now()->startOfDay(), $tenant->expires_at?->copy()->startOfDay()])->filter()->max()->toDateString(),
            'saas_invoice_enabled' => (bool) $tenant->saas_invoice_enabled,
            'saas_invoice_blockers' => array_values(array_filter([
                $saas->blocker(AdminFiscalSetting::current()),
                ...$saas->receiverProblems($tenant),
            ])),
            'subscription_label' => $tenant->subscriptionLabel(),
            'expires_at' => $tenant->expires_at?->toDateString(),
            'next_charge' => $contract?->active ? [
                'due_date' => $contract->next_due_date->toDateString(),
                'amount' => (string) $contract->amount,
                'generated_from' => $contract->next_due_date->copy()->subDays(SaasContractService::INVOICE_LEAD_DAYS)->toDateString(),
            ] : null,
            'charges' => $charges->map(function (TenantSubscriptionCharge $charge) {
                $document = $charge->fiscalDocuments->first();
                $lastInvoice = $charge->deliveries->firstWhere('kind', 'invoice');

                return [
                    'id' => $charge->id,
                    'cycle_start' => $charge->cycle_start->toDateString(),
                    'cycle_end' => $charge->cycle_end->toDateString(),
                    'due_date' => $charge->due_date->toDateString(),
                    'amount' => (string) $charge->amount,
                    'status' => $charge->status,
                    'status_label' => $charge->statusLabel(),
                    'paid_at' => $charge->paid_at?->toIso8601String(),
                    'payment_method' => $charge->payment_method,
                    'refund_reason' => $charge->refund_reason,
                    'invoice_sent_at' => $charge->invoice_sent_at?->toIso8601String(),
                    'last_invoice_error' => $lastInvoice?->status === 'failed' ? $lastInvoice->error : null,
                    'fiscal_document' => $document ? [
                        'id' => $document->id,
                        'status' => $document->status,
                        'number' => $document->number,
                        'error_message' => $document->error_message,
                    ] : null,
                ];
            }),
        ];
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'custom_contract' => 'required|boolean',
            'saas_invoice_enabled' => 'required|boolean',
            'amount' => ['exclude_unless:custom_contract,true', ...self::MONEY],
            'billing_months' => ['exclude_unless:custom_contract,true', 'required', 'integer', Rule::in(array_keys(TenantCommercialContract::PERIODICITIES))],
            'start_date' => 'exclude_unless:custom_contract,true|required|date',
            'next_due_date' => 'exclude_unless:custom_contract,true|required|date|after_or_equal:start_date',
            'sales_rep' => 'exclude_unless:custom_contract,true|nullable|string|max:120',
            'notes' => 'exclude_unless:custom_contract,true|nullable|string|max:2000',
        ], [
            'amount.regex' => 'Informe um valor positivo com até duas casas decimais.',
            'amount.not_regex' => 'O valor contratado deve ser maior que zero.',
        ], [
            'amount' => 'valor contratado',
            'billing_months' => 'periodicidade',
            'start_date' => 'data de início',
            'next_due_date' => 'próximo vencimento',
            'sales_rep' => 'responsável comercial',
            'notes' => 'observações comerciais',
        ]);

        if ($data['custom_contract']) {
            $this->contracts->saveContract($tenant, $data);
        } elseif ($tenant->hasCustomContract()) {
            return back()->with('error', 'Para voltar ao plano tradicional use a opção "Voltar ao plano tradicional", com plano e confirmação.');
        }

        $this->contracts->setSaasInvoice($tenant->refresh(), (bool) $data['saas_invoice_enabled']);

        return back()->with('success', 'Condições comerciais atualizadas.');
    }

    public function revert(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
            'period_id' => 'nullable|integer|exists:periods,id',
            'effective_date' => 'required|date|after_or_equal:today',
            'confirm' => 'accepted',
            'password' => 'required|current_password',
        ], [
            'confirm.accepted' => 'Confirme a volta ao plano tradicional e a data em que ela passa a valer.',
            'effective_date.after_or_equal' => 'A data efetiva não pode ser anterior a hoje.',
            'password.current_password' => 'Senha incorreta.',
        ], ['effective_date' => 'data efetiva']);

        $effective = Carbon::parse($data['effective_date'])->startOfDay();
        $this->contracts->revertToPlan($tenant, (int) $data['plan_id'], $data['period_id'] ?? null, $effective, (int) auth()->id());

        return back()->with('success', $effective->isFuture()
            ? 'Volta ao plano tradicional agendada para '.$effective->format('d/m/Y').'. Até lá o contrato segue valendo.'
            : 'Empresa de volta ao plano tradicional. Histórico de cobranças preservado.');
    }

    public function cancelRevert(Tenant $tenant): RedirectResponse
    {
        $this->contracts->cancelScheduledRevert($tenant);

        return back()->with('success', 'Volta ao plano tradicional desagendada. O contrato segue como estava.');
    }

    public function pay(Request $request, Tenant $tenant, TenantSubscriptionCharge $charge): RedirectResponse
    {
        $this->ensureOwned($tenant, $charge);
        $data = $request->validate([
            'amount' => self::MONEY,
            'note' => 'nullable|string|max:255',
        ], ['amount.regex' => 'Informe um valor positivo com até duas casas decimais.']);

        $this->contracts->registerPayment($charge, $data['amount'], 'manual', note: $data['note'] ?? null);

        return back()->with('success', 'Pagamento registrado.');
    }

    public function refund(Request $request, Tenant $tenant, TenantSubscriptionCharge $charge): RedirectResponse
    {
        $this->ensureOwned($tenant, $charge);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255', 'password' => 'required|current_password'], ['password.current_password' => 'Senha incorreta.']);

        $this->contracts->refund($charge, $data['reason']);

        return back()->with('success', 'Estorno registrado. A NFS-e, se houver, não foi cancelada automaticamente.');
    }

    public function adjust(Request $request, Tenant $tenant, TenantSubscriptionCharge $charge): RedirectResponse
    {
        $this->ensureOwned($tenant, $charge);
        $data = $request->validate(['amount' => self::MONEY, 'reason' => 'required|string|min:5|max:255'], ['amount.regex' => 'Informe um valor positivo com até duas casas decimais.']);

        $this->contracts->adjustOpenCharge($charge, $data['amount'], $data['reason']);

        return back()->with('success', 'Valor da cobrança em aberto ajustado.');
    }

    public function resendInvoice(Tenant $tenant, TenantSubscriptionCharge $charge): RedirectResponse
    {
        $this->ensureOwned($tenant, $charge);

        $delivery = $this->contracts->sendInvoice($charge, (int) auth()->id());

        return $delivery->status === 'sent'
            ? back()->with('success', "Fatura enviada para {$delivery->email}.")
            : back()->with('error', 'Falha no envio da fatura (registrada). A cobrança não foi alterada.');
    }

    public function emit(Tenant $tenant, TenantSubscriptionCharge $charge): RedirectResponse
    {
        $this->ensureOwned($tenant, $charge);

        try {
            $document = $this->invoices->emitForCharge($charge, (int) auth()->id());
        } catch (FiscalEmissionException|FiscalValidationException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $document->status === FiscalDocument::STATUS_AUTHORIZED
            ? back()->with('success', 'NFS-e autorizada.')
            : back()->with('success', 'NFS-e enviada para autorização.');
    }

    public function resendFiscal(Tenant $tenant, TenantSubscriptionCharge $charge): RedirectResponse
    {
        $this->ensureOwned($tenant, $charge);
        $document = AdminFiscalDocument::query()
            ->where('tenant_subscription_charge_id', $charge->id)
            ->where('status', FiscalDocument::STATUS_AUTHORIZED)
            ->latest('id')
            ->first();

        if (! $document) {
            return back()->with('error', 'Esta cobrança não tem NFS-e autorizada para enviar.');
        }

        $delivery = $this->contracts->sendFiscalNotice($document, (int) auth()->id());

        return $delivery?->status === 'sent'
            ? back()->with('success', 'Nota fiscal reenviada. Nenhuma nova nota foi emitida.')
            : back()->with('error', 'Falha no envio da nota (registrada). A nota não foi reemitida.');
    }

    /** Isolamento entre tenants: a cobrança precisa ser da empresa da URL. */
    private function ensureOwned(Tenant $tenant, TenantSubscriptionCharge $charge): void
    {
        abort_unless((int) $charge->tenant_id === (int) $tenant->id, 404);
    }
}
