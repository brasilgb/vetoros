<?php

namespace App\Http\Controllers\App;

use App\Events\SaleCancelled;
use App\Events\SaleCreated;
use App\Events\SaleDeleted;
use App\Http\Controllers\Controller;
use App\Models\App\Part;
use App\Models\App\Sale;
use App\Services\FiscalDocumentService;
use App\Services\SaleService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use App\Support\TenantRule;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $saleService,
        private readonly FiscalDocumentService $fiscalDocumentService,
    ) {}

    private function authorizeSalesAccess(?Sale $sale = null, string $ability = 'viewAny'): ?Response
    {
        $allowed = $sale
            ? Gate::allows($ability, $sale)
            : Gate::allows($ability, Sale::class);

        if ($allowed) {
            return null;
        }

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Módulo de vendas desabilitado ou acesso não permitido.',
            ], 403);
        }

        return redirect()->route('app.dashboard')->with('error', 'Módulo de vendas desabilitado ou acesso não permitido.');
    }

    public function index(Request $request)
    {
        if ($response = $this->authorizeSalesAccess()) {
            return $response;
        }

        $search = $request->search;
        $financialStatus = $request->get('financial_status');
        $baseQuery = Sale::query();

        if ($search) {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('sales_number', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', '%'.$search.'%');
                    });
            });
        }

        $counts = [
            'paid' => (clone $baseQuery)->where('financial_status', 'paid')->count(),
            'partial' => (clone $baseQuery)->where('financial_status', 'partial')->count(),
            'pending' => (clone $baseQuery)->where('financial_status', 'pending')->count(),
            'cancelled' => (clone $baseQuery)->where('financial_status', 'cancelled')->count(),
        ];

        $query = (clone $baseQuery)
            ->with('customer')
            ->with('items.part')
            ->with('cancelledBy:id,name')
            ->with('fiscalRegisteredBy:id,name')
            ->with('logs.user:id,name')
            ->orderBy('id', 'DESC');

        if ($financialStatus) {
            $query->where('financial_status', $financialStatus);
        }
        $sales = $query->paginate(\App\Support\Pagination::perPage())->withQueryString();

        return Inertia::render('app/sales/index', [
            'sales' => $sales,
            'parts' => Part::where('is_sellable', true)->get(),
            'search' => $search,
            'financial_status' => $financialStatus,
            'financial_counts' => $counts,
        ]);
    }

    public function store(Request $request)
    {
        if ($response = $this->authorizeSalesAccess(null, 'create')) {
            return $response;
        }

        $request->validate([
            'customer_id' => ['nullable', TenantRule::exists('customers')],
            'total_amount' => 'required|numeric|min:0',
            // O PDV não tem desconto: o total é o preço das peças, calculado no servidor.
            'discount_amount' => 'prohibited',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:pix,cartao,dinheiro,transferencia,boleto',
            'parts' => 'required|array',
            'parts.*.part_id' => ['required', TenantRule::exists('parts')],
            'parts.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            $sale = $this->saleService->create($request->only([
                'customer_id',
                'total_amount',
                'paid_amount',
                'payment_method',
                'parts',
            ]));

            event(new SaleCreated($sale->id, Auth::id(), [
                'payment_method' => $sale->payment_method,
                'total_amount' => (float) $sale->total_amount,
                'paid_amount' => (float) $sale->paid_amount,
                'financial_status' => $sale->financial_status,
            ]));

            return response()->json([
                'success' => true,
                'sale' => [
                    'id' => $sale->id,
                    'sales_number' => $sale->sales_number,
                    'date' => $sale->created_at,
                    'total_amount' => (float) $sale->total_amount,
                    'payment_method' => $sale->payment_method,
                    'paid_amount' => (float) $sale->paid_amount,
                    'financial_status' => $sale->financial_status,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function cancel(Sale $sale)
    {
        if ($response = $this->authorizeSalesAccess($sale, 'update')) {
            return $response;
        }
        $user = Auth::user();

        $validated = request()->validate([
            'cancel_reason' => 'required|string|min:8|max:500',
        ]);

        try {
            $sale = $this->saleService->cancel($sale, $validated['cancel_reason'], $user instanceof User ? $user : null);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $logData = [
            'reason' => $validated['cancel_reason'],
        ];
        $auditData = [
            'reason' => $validated['cancel_reason'],
            'status' => $sale->status,
            'financial_status' => $sale->financial_status,
            'cash_session_id' => $sale->cash_session_id,
            'total_amount' => (float) $sale->total_amount,
            'paid_amount' => (float) $sale->paid_amount,
        ];
        event(new SaleCancelled($sale->id, Auth::id(), $logData, $auditData));

        return back()->with('success', 'Venda cancelada com sucesso.');
    }

    public function destroy(Sale $sale)
    {
        if ($response = $this->authorizeSalesAccess($sale, 'delete')) {
            return $response;
        }
        $user = Auth::user();

        try {
            $auditData = [
                'status' => $sale->status,
                'financial_status' => $sale->financial_status,
                'cash_session_id' => $sale->cash_session_id,
                'cancelled_by' => $sale->cancelled_by,
                'cancel_reason' => $sale->cancel_reason,
                'total_amount' => (float) $sale->total_amount,
                'paid_amount' => (float) $sale->paid_amount,
            ];
            event(new SaleDeleted($sale->id, Auth::id(), $auditData));

            $this->saleService->delete($sale, $user instanceof User ? $user : null);

            return redirect()->route('app.sales.index')->with('success', 'Venda cancelada excluída com sucesso.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function registerFiscal(Request $request, Sale $sale)
    {
        if ($response = $this->authorizeSalesAccess($sale, 'update')) {
            return $response;
        }

        $validated = $request->validate([
            'fiscal_document_number' => 'required|string|max:120',
            'fiscal_document_url' => 'nullable|url|max:500',
            'fiscal_issued_at' => 'nullable|date',
            'fiscal_notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->fiscalDocumentService->registerManualSale($sale, $validated, (int) Auth::id());
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Comprovante fiscal registrado com sucesso.');
    }
}
