<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchRequest;
use App\Models\Admin\Branch;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $branches = Branch::paginate(12);

        return Inertia::render('admin/branches/index', ['branches' => $branches]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $tenants = Tenant::get();

        return Inertia::render('admin/branches/create-branch', ['tenants' => $tenants]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BranchRequest $request): RedirectResponse
    {
        $request->validated();
        $data = $request->only(['tenant_id', 'branch_cnpj', 'branch_name', 'branch_number', 'contact_name', 'contact_email', 'contact_phone', 'contact_whatsapp', 'cep', 'state', 'city', 'district', 'street', 'complement', 'number', 'status', 'observations']);
        Branch::create($data);

        return redirect()->route('admin.branches.index')->with('success', 'Filial cadastrada com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Branch $branch)
    {
        return Inertia::render('admin/branches/edit-branch', ['branch' => $branch]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Branch $branch)
    {
        return redirect()->route('admin.branches.show', ['branch' => $branch->id]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $request->validated();
        $data = $request->only(['tenant_id', 'branch_cnpj', 'branch_name', 'branch_number', 'contact_name', 'contact_email', 'contact_phone', 'contact_whatsapp', 'cep', 'state', 'city', 'district', 'street', 'complement', 'number', 'status', 'observations']);
        $branch->update($data);

        return redirect()->route('admin.branches.show', ['branch' => $branch->id])->with('success', 'Filial atualizada com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Branch $branch)
    {
        $branch->delete();

        return redirect()->route('admin.branches.index')->with('success', 'Filial excluída com sucesso!');
    }
}
