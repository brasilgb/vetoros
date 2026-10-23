<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\EquipmentRequest;
use App\Models\App\Equipment;
use App\Support\TenantSequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class EquipmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('equipments.access');

        $search = $request->search;
        $query = Equipment::orderBy('id', 'DESC');
        if ($search) {
            $query->where('equipment', 'like', '%'.$search.'%');
        }
        $equipments = $query->paginate(\App\Support\Pagination::perPage())->withQueryString();

        return Inertia::render('app/equipments/index', ['equipments' => $equipments]);
    }

    /**
     * Busca leve usada por selects com autocomplete (ordens, orçamentos).
     * Retorna só os campos necessários pra exibir/selecionar, sem carregar a tabela inteira.
     */
    public function search(Request $request)
    {
        Gate::authorize('equipments.access');

        $search = trim((string) $request->get('q', ''));

        $equipments = Equipment::query()
            ->select(['id', 'equipment'])
            ->when($search !== '', fn ($query) => $query->where('equipment', 'like', '%'.$search.'%'))
            ->orderBy('equipment')
            ->limit(20)
            ->get();

        return response()->json($equipments);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(EquipmentRequest $request): RedirectResponse
    {
        Gate::authorize('equipments.access');

        $request->validated();
        $data = $request->only(['equipment', 'chart']);
        $data['chart'] = $request->boolean('chart');
        $data['equipment_number'] = TenantSequence::next(Equipment::class, 'equipment_number');
        $equipment = Equipment::create($data);

        if ($request->boolean('_inline')) {
            return back()->with('equipment_saved', [
                'id' => $equipment->id,
                'equipment' => $equipment->equipment,
            ]);
        }

        return redirect()->route('app.register-equipments.index')->with('success', 'Tipo de equipamento cadastrado com sucesso');
    }

    /**
     * Display the specified resource.
     */
    public function show(Equipment $equipment)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Equipment $equipment)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EquipmentRequest $request, Equipment $equipment)
    {
        Gate::authorize('equipments.access');

        $request->validated();
        $data = $request->only(['equipment', 'chart']);
        $data['chart'] = $request->boolean('chart');
        $equipment->update($data);

        if ($request->boolean('_inline')) {
            return back()->with('equipment_updated', [
                'id' => $equipment->id,
                'equipment' => $equipment->equipment,
            ]);
        }

        return redirect()->route('app.register-equipments.index')->with('success', 'Tipo de equipamento atualizado com sucesso');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Equipment $equipment)
    {
        Gate::authorize('equipments.access');

        if ($equipment->orders()->exists() || $equipment->budgets()->exists() || $equipment->checklists()->exists()) {
            return redirect()->route('app.register-equipments.index')->with(
                'error',
                'Não é possível excluir este tipo de equipamento porque existem ordens, orçamentos ou checklists vinculados.'
            );
        }

        $equipmentId = $equipment->id;
        $equipment->delete();

        if ($request->boolean('_inline')) {
            return back()->with('equipment_deleted', [
                'id' => $equipmentId,
            ]);
        }

        return redirect()->route('app.register-equipments.index')->with('success', 'Tipo de equipamento excluído com sucesso');
    }
}
