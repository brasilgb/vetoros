<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FeatureRequest;
use App\Models\Admin\Feature;
use App\Models\Admin\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeatureController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->search;

        $query = Feature::orderBy('id', 'DESC');

        if ($search) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $periods = Period::get();
        $features = $query->with('period')->paginate(12);

        return Inertia::render('admin/features/index', ['features' => $features, 'periods' => $periods]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('admin/features/create-feature');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(FeatureRequest $request): RedirectResponse
    {
        $request->validated();
        $data = $request->only(['name', 'period_id', 'order']);
        Feature::create($data);

        return redirect()->route('admin.features.index')->with('success', 'Característica cadastrada com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Feature $feature)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Feature $feature)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(FeatureRequest $request, Feature $feature): RedirectResponse
    {
        $request->validated();
        $data = $request->only(['name', 'period_id', 'order']);
        $feature->update($data);

        return redirect()->route('admin.features.index')->with('success', 'Características editadas com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Feature $feature)
    {
        $feature->delete();

        return redirect()->route('admin.features.index')->with('success', 'Características excluidas com sucesso!');
    }
}
