<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\BudgetsRequest;
use App\Models\App\Budget;
use App\Models\App\Company;
use App\Models\App\Equipment;
use App\Support\TenantSequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class BudgetController extends Controller
{
    private function currentTenantId(): ?int
    {
        return Auth::user()?->tenant_id ? (int) Auth::user()->tenant_id : null;
    }

    public function getOrcamentos(Request $request)
    {
        $data = $request->validate([
            'equipment_id' => ['required', 'integer'],
            'model' => ['required', 'string', 'max:255'],
            'service' => ['required', 'string', 'max:150'],
        ]);

        Equipment::query()->whereKey($data['equipment_id'])->firstOrFail();

        $budgets = Budget::query()
            ->with('equipment:id,equipment_number,equipment')
            ->where('equipment_id', $data['equipment_id'])
            ->where('model', $data['model'])
            ->where('service', $data['service'])
            ->orderBy('id')
            ->get()
            ->map(fn (Budget $budget) => $this->budgetPayload($budget))
            ->values();

        return response()->json([
            'success' => true,
            'status' => true,
            'result' => [
                'filters' => $data,
                'budgets' => $budgets,
            ],
            'data' => $budgets,
        ], 200);
    }

    public function budgetFilters()
    {
        $equipments = Equipment::query()
            ->orderBy('equipment')
            ->get(['id', 'equipment_number', 'equipment']);

        return response()->json([
            'success' => true,
            'result' => [
                'equipments' => $equipments,
            ],
        ]);
    }

    public function budgetModels(Request $request)
    {
        $data = $request->validate([
            'equipment_id' => ['required', 'integer'],
        ]);

        Equipment::query()->whereKey($data['equipment_id'])->firstOrFail();

        $models = Budget::query()
            ->where('equipment_id', $data['equipment_id'])
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->distinct()
            ->orderBy('model')
            ->pluck('model')
            ->values();

        return response()->json([
            'success' => true,
            'result' => [
                'equipment_id' => (int) $data['equipment_id'],
                'models' => $models,
            ],
        ]);
    }

    public function budgetServices(Request $request)
    {
        $data = $request->validate([
            'equipment_id' => ['required', 'integer'],
            'model' => ['required', 'string', 'max:255'],
        ]);

        Equipment::query()->whereKey($data['equipment_id'])->firstOrFail();

        $services = Budget::query()
            ->where('equipment_id', $data['equipment_id'])
            ->where('model', $data['model'])
            ->distinct()
            ->orderBy('service')
            ->pluck('service')
            ->values();

        return response()->json([
            'success' => true,
            'result' => [
                'equipment_id' => (int) $data['equipment_id'],
                'model' => $data['model'],
                'services' => $services,
            ],
        ]);
    }

    private function budgetPayload(Budget $budget): array
    {
        return [
            'id' => $budget->id,
            'tenant_id' => $budget->tenant_id,
            'budget_number' => $budget->budget_number,
            'equipment_id' => $budget->equipment_id,
            'equipment' => $budget->equipment ? [
                'id' => $budget->equipment->id,
                'equipment_number' => $budget->equipment->equipment_number,
                'equipment' => $budget->equipment->equipment,
            ] : null,
            'model' => $budget->model,
            'service' => $budget->service,
            'description' => $budget->description,
            'estimated_time' => $budget->estimated_time,
            'part_value' => $budget->part_value,
            'labor_value' => $budget->labor_value,
            'total_value' => $budget->total_value,
            'warranty' => $budget->warranty,
            'validity' => $budget->validity,
            'obs' => $budget->obs,
            'created_at' => $budget->created_at,
            'updated_at' => $budget->updated_at,
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('budgets.access');

        $search = $request->search;
        $query = Budget::orderBy('id', 'DESC');
        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('budget_number', $search)
                    ->orWhere('service', 'like', '%'.$search.'%')
                    ->orWhere('model', 'like', '%'.$search.'%')
                    ->orWhereHas('equipment', fn ($equipmentQuery) => $equipmentQuery->where('equipment', 'like', '%'.$search.'%'));
            });
        }
        $budgets = $query->with('equipment')->paginate(\App\Support\Pagination::perPage())->withQueryString();
        $company = Company::query()
            ->where('tenant_id', $this->currentTenantId())
            ->first();

        return Inertia::render('app/budgets/index', ['budgets' => $budgets, 'company' => $company, 'search' => $search]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        Gate::authorize('budgets.access');

        $budgets = Budget::distinct()->pluck('model');
        $equipments = Equipment::get();

        return Inertia::render('app/budgets/create-budget', ['equipments' => $equipments, 'budgets' => $budgets]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BudgetsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['budget_number'] = TenantSequence::next(Budget::class, 'budget_number');
        Budget::create($data);

        return redirect()->route('app.budgets.index')->with('success', 'Orçamento cadastrado com sucesso');
    }

    /**
     * Display the specified resource.
     */
    public function show(Budget $budget, Request $request)
    {
        Gate::authorize('budgets.access');

        $budgets = Budget::distinct()->pluck('model');
        $equipments = Equipment::get();

        return Inertia::render('app/budgets/edit-budget', [
            'budget' => $budget,
            'equipments' => $equipments,
            'budgets' => $budgets,
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Budget $budget, Request $request)
    {
        Gate::authorize('budgets.access');

        return Redirect::route('app.budgets.show', [
            'budget' => $budget->id,
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BudgetsRequest $request, Budget $budget): RedirectResponse
    {
        $budget->update($request->validated());

        return redirect()->route('app.budgets.index')->with('success', 'Orçamento editado com sucesso');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Budget $budget)
    {
        Gate::authorize('budgets.access');

        $budget->delete();

        return redirect()->route('app.budgets.index')->with('success', 'Orçamento excluido com sucesso!');
    }
}
