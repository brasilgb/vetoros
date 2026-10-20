<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PeriodRequest;
use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PeriodController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->search;

        $query = Period::orderBy('id', 'DESC');

        if ($search) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $plans = Plan::get();
        $periods = $query->with('plan')->paginate(12);

        return Inertia::render('admin/periods/index', ['periods' => $periods, 'plans' => $plans]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('admin/periods/create-period');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PeriodRequest $request)
    {
        $request->validated();
        $data = $request->only(['plan_id', 'name', 'interval', 'interval_count', 'price']);
        Period::create($data);

        return redirect()->route('admin.periods.index')->with('success', 'Período cadastrado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Period $period)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Period $period)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PeriodRequest $request, Period $period): RedirectResponse
    {
        $request->validated();
        $data = $request->only(['plan_id', 'name', 'interval', 'interval_count', 'price']);
        $period->update($data);

        return redirect()->route('admin.periods.index')->with('success', 'Período editado com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Period $period)
    {
        $period->delete();

        return redirect()->route('admin.periods.index')->with('success', 'Período excluido com sucesso!');
    }
}
