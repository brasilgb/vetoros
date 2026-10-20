<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\AccountReceivable;
use App\Models\App\Company;
use App\Models\App\FiscalDocument;
use App\Models\App\MaintenanceContract;
use App\Models\App\Receipt;
use App\Models\User;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\MaintenanceContractService;
use App\Support\Pagination;
use App\Support\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceContractController extends Controller
{
    public function __construct(
        private readonly MaintenanceContractService $maintenanceContractService,
        private readonly NativeFiscalService $nativeFiscal,
    ) {}

    private function authorizeAccess(?MaintenanceContract $contract = null, string $ability = 'viewAny'): ?Response
    {
        $allowed = $contract
            ? Gate::allows($ability, $contract)
            : Gate::allows($ability, MaintenanceContract::class);

        if ($allowed) {
            return null;
        }

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Módulo financeiro desabilitado ou acesso não permitido.',
            ], 403);
        }

        return redirect()->route('app.dashboard')->with('error', 'Módulo financeiro desabilitado ou acesso não permitido.');
    }

    private function rules(): array
    {
        return [
            // Cliente do próprio tenant (antes aceitava o id de cliente de outra empresa).
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', Auth::user()?->tenant_id)],
            'description' => 'required|string|max:255',
            'monthly_amount' => 'required|numeric|min:0.01',
            'billing_day' => 'required|integer|min:1|max:28',
            'start_date' => 'required|date',
            'duration_months' => 'nullable|integer|min:1|max:120',
            'visit_frequency_days' => 'nullable|integer|min:1|max:365',
            'preferred_technician_id' => ['nullable', TenantRule::exists('users')],
            'notes' => 'nullable|string|max:500',
            'auto_issue_invoice' => 'sometimes|boolean',
            'invoice_competence' => ['sometimes', Rule::in(MaintenanceContract::COMPETENCES)],
            'auto_send_invoice' => 'sometimes|boolean',
        ];
    }

    /**
     * NFS-e automática só pode ser ligada com a emissão fiscal do tenant pronta; o envio
     * automático depende da emissão automática.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withInvoiceOptions(array $validated): array
    {
        $issue = (bool) ($validated['auto_issue_invoice'] ?? false);

        if ($issue && ($blocker = $this->nativeFiscal->contractInvoiceBlocker((int) Auth::user()->tenant_id))) {
            throw ValidationException::withMessages(['auto_issue_invoice' => 'Não é possível ativar a emissão automática: '.$blocker]);
        }

        return [
            ...$validated,
            'auto_issue_invoice' => $issue,
            // Envio vale para toda NFS-e autorizada do contrato, emitida automática ou manualmente.
            'auto_send_invoice' => (bool) ($validated['auto_send_invoice'] ?? false),
        ];
    }

    public function index(Request $request)
    {
        if ($response = $this->authorizeAccess()) {
            return $response;
        }

        $search = trim((string) $request->get('search', ''));
        $status = trim((string) $request->get('status', ''));

        $query = MaintenanceContract::query()
            ->with(['customer:id,name,email', 'preferredTechnician:id,name'])
            // CASE em vez de FIELD(): funciona no MySQL e no SQLite dos testes.
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'suspended' THEN 1 WHEN 'expired' THEN 2 WHEN 'cancelled' THEN 3 ELSE 4 END")
            ->orderBy('next_billing_date');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', $search)
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$search.'%'));
            });
        }

        if (in_array($status, [
            MaintenanceContract::STATUS_ACTIVE,
            MaintenanceContract::STATUS_SUSPENDED,
            MaintenanceContract::STATUS_CANCELLED,
            MaintenanceContract::STATUS_EXPIRED,
        ], true)) {
            $query->where('status', $status);
        }

        $contracts = $query->paginate(Pagination::perPage())->withQueryString();

        // Alvo do botão de NFS-e de cada contrato: a cobrança mais antiga sem nota ou o ciclo vencido a gerar.
        $awaiting = $this->maintenanceContractService
            ->chargesAwaitingInvoice($contracts->getCollection()->pluck('id')->all())
            ->get(['id', 'source_id', 'due_date', 'total_amount'])
            ->groupBy('source_id')
            ->map->first();
        // Coluna "NF": situação da nota do ciclo mais recente do contrato.
        $latestDocuments = FiscalDocument::query()
            ->join('accounts_receivable', 'accounts_receivable.id', '=', 'fiscal_documents.documentable_id')
            ->where('fiscal_documents.documentable_type', AccountReceivable::class)
            ->where('accounts_receivable.source_type', AccountReceivable::SOURCE_MAINTENANCE_CONTRACT)
            ->whereIn('accounts_receivable.source_id', $contracts->getCollection()->pluck('id'))
            ->orderByDesc('accounts_receivable.due_date')
            ->orderByDesc('fiscal_documents.id')
            ->get(['fiscal_documents.id', 'fiscal_documents.status', 'fiscal_documents.number', 'accounts_receivable.source_id', 'accounts_receivable.due_date'])
            ->groupBy('source_id')
            ->map->first();
        $canFiscal = Gate::allows('fiscal-documents.access');
        $contracts->getCollection()->each(function (MaintenanceContract $contract) use ($awaiting, $latestDocuments, $canFiscal) {
            $charge = $awaiting->get($contract->id);
            $target = match (true) {
                $charge !== null => ['kind' => 'charge', 'due_date' => $charge->due_date?->toDateString(), 'amount' => (float) $charge->total_amount],
                $this->maintenanceContractService->canBillNow($contract) => ['kind' => 'cycle', 'due_date' => $contract->next_billing_date->toDateString(), 'amount' => (float) $contract->monthly_amount],
                default => null,
            };
            $document = $latestDocuments->get($contract->id);
            $contract->setAttribute('invoice_target', $target);
            $contract->setAttribute('invoice_pdf_url', $canFiscal && $document?->status === FiscalDocument::STATUS_AUTHORIZED
                ? route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'pdf'])
                : null);
            $contract->setAttribute('invoice_status', match (true) {
                $target !== null => ['state' => 'pending', 'due_date' => $target['due_date'], 'number' => null],
                $document === null => null,
                in_array($document->status, FiscalDocument::PENDING_STATUSES, true) => ['state' => 'processing', 'due_date' => substr((string) $document->due_date, 0, 10), 'number' => null],
                $document->status === FiscalDocument::STATUS_AUTHORIZED => ['state' => 'authorized', 'due_date' => substr((string) $document->due_date, 0, 10), 'number' => $document->number],
                default => null,
            });
        });

        $totals = [
            'active_count' => MaintenanceContract::query()->where('status', MaintenanceContract::STATUS_ACTIVE)->count(),
            'monthly_revenue' => (float) MaintenanceContract::query()
                ->where('status', MaintenanceContract::STATUS_ACTIVE)
                ->sum('monthly_amount'),
        ];

        $technicians = User::query()
            ->where('roles', User::ROLE_TECHNICIAN)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('app/maintenance-contracts/index', [
            'contracts' => $contracts,
            'search' => $search,
            'status' => $status,
            'totals' => $totals,
            'technicians' => $technicians,
            'invoiceBlocker' => $this->nativeFiscal->contractInvoiceBlocker((int) Auth::user()->tenant_id),
            'canFiscal' => $canFiscal,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($response = $this->authorizeAccess(null, 'create')) {
            return $response;
        }

        $validated = $this->withInvoiceOptions($request->validate($this->rules()));

        $contract = $this->maintenanceContractService->create($validated, (int) Auth::id());

        return back()
            ->with('success', 'Contrato de manutenção cadastrado com sucesso.')
            ->with('contract_print', [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'print_url' => route('app.maintenance-contracts.printing', $contract->id),
            ]);
    }

    public function update(Request $request, MaintenanceContract $maintenance_contract): RedirectResponse
    {
        if ($response = $this->authorizeAccess($maintenance_contract, 'update')) {
            return $response;
        }

        $validated = $this->withInvoiceOptions($request->validate($this->rules()));

        $this->maintenanceContractService->update($maintenance_contract, $validated, (int) Auth::id());

        return back()->with('success', 'Contrato de manutenção atualizado com sucesso.');
    }

    public function renew(Request $request, MaintenanceContract $maintenance_contract): RedirectResponse
    {
        if ($response = $this->authorizeAccess($maintenance_contract, 'update')) {
            return $response;
        }

        $validated = $request->validate([
            'duration_months' => 'nullable|integer|min:1|max:120',
        ]);

        $this->maintenanceContractService->renew($maintenance_contract, $validated, (int) Auth::id());

        return back()->with('success', 'Contrato renovado com sucesso.');
    }

    public function suspend(MaintenanceContract $maintenance_contract): RedirectResponse
    {
        if ($response = $this->authorizeAccess($maintenance_contract, 'update')) {
            return $response;
        }

        $this->maintenanceContractService->suspend($maintenance_contract, (int) Auth::id());

        return back()->with('success', 'Contrato suspenso.');
    }

    public function reactivate(MaintenanceContract $maintenance_contract): RedirectResponse
    {
        if ($response = $this->authorizeAccess($maintenance_contract, 'update')) {
            return $response;
        }

        $this->maintenanceContractService->reactivate($maintenance_contract, (int) Auth::id());

        return back()->with('success', 'Contrato reativado.');
    }

    public function cancel(MaintenanceContract $maintenance_contract): RedirectResponse
    {
        if ($response = $this->authorizeAccess($maintenance_contract, 'update')) {
            return $response;
        }

        $this->maintenanceContractService->cancel($maintenance_contract, (int) Auth::id());

        return back()->with('success', 'Contrato cancelado.');
    }

    public function printing(MaintenanceContract $maintenance_contract)
    {
        if ($response = $this->authorizeAccess($maintenance_contract, 'update')) {
            return $response;
        }

        $maintenance_contract->load(['customer', 'preferredTechnician']);

        $company = Company::query()->where('tenant_id', $maintenance_contract->tenant_id)->first();

        if ($company) {
            $company->setAttribute('logo_url', $this->resolveCompanyLogoUrl($company));
        }

        return Inertia::render('app/maintenance-contracts/print-contract', [
            'contract' => $maintenance_contract,
            'company' => $company,
            'template' => Receipt::query()->latest('id')->value('maintenance_contract_template'),
        ]);
    }

    /**
     * Garante que a URL do logo só é usada se o arquivo realmente existir, evitando
     * quebra no cabeçalho do contrato caso o logo tenha sido apagado do storage.
     */
    private function resolveCompanyLogoUrl(Company $company): string
    {
        if ($company->logo) {
            $logoPath = public_path('storage/logos/'.$company->logo);

            if (file_exists($logoPath)) {
                return asset('storage/logos/'.$company->logo);
            }
        }

        return asset('images/default.png');
    }

    public function destroy(MaintenanceContract $maintenance_contract): RedirectResponse
    {
        if ($response = $this->authorizeAccess($maintenance_contract, 'delete')) {
            return $response;
        }

        $this->maintenanceContractService->delete($maintenance_contract);

        return back()->with('success', 'Contrato de manutenção excluído com sucesso.');
    }
}
