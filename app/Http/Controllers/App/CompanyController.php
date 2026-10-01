<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\Company;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

class CompanyController extends Controller
{
    private function currentTenantId(): ?int
    {
        return Auth::user()?->tenant_id ? (int) Auth::user()->tenant_id : null;
    }

    public function getEmpresaInfo()
    {
        $empresa = Company::query()
            ->where('tenant_id', $this->currentTenantId())
            ->first();

        return response()->json([
            'success' => true,
            'data' => $empresa,
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Company $company)
    {
        Gate::authorize('company.access');

        $tenantId = $this->currentTenantId();
        $company = Company::query()->firstOrCreate([
            'tenant_id' => $tenantId,
        ]);

        return Inertia::render('app/company/index', ['company' => $company]);
    }

    /**
     * Display the specified resource.
     */
    public function update(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('company.access');
        abort_if((int) $company->tenant_id !== (int) $this->currentTenantId(), 403);

        if ($request->has('number') && $request->input('number') !== null) {
            $request->merge(['number' => (string) $request->input('number')]);
        }

        $data = $request->validate([
            'shortname' => ['nullable', 'string', 'max:50'],
            'companyname' => ['nullable', 'string', 'max:50'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:50'],
            'district' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:50'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:50'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'site' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:50'],
        ]);
        $storePath = public_path('storage/logos');
        $oldLogo = $company->logo;
        $fileName = null;

        try {
            if ($request->hasFile('logo')) {
                $fileName = Str::uuid().'.'.$request->file('logo')->extension();
                $request->file('logo')->move($storePath, $fileName);
            }
            $data['logo'] = $fileName ?? $oldLogo;

            DB::transaction(function () use ($company, $data) {
                $company->update($data);

                // O cadastro do tenant usa nomes diferentes e não possui logo/site.
                $tenantData = Arr::only($data, [
                    'cnpj', 'email', 'whatsapp', 'zip_code', 'state', 'city',
                    'district', 'street', 'number', 'complement',
                ]);
                foreach (['companyname' => 'company', 'telephone' => 'phone'] as $source => $target) {
                    if (array_key_exists($source, $data)) {
                        $tenantData[$target] = $data[$source];
                    }
                }
                // Estes campos são obrigatórios no tenant, mas opcionais na empresa.
                foreach (['cnpj', 'email'] as $required) {
                    if (($tenantData[$required] ?? null) === null) {
                        unset($tenantData[$required]);
                    }
                }
                $tenant = Tenant::query()->find($company->tenant_id);
                $tenant?->update($tenantData);
            });
        } catch (Throwable $exception) {
            if ($fileName && File::isFile($storePath.DIRECTORY_SEPARATOR.$fileName)) {
                File::delete($storePath.DIRECTORY_SEPARATOR.$fileName);
            }
            throw $exception;
        }

        // Só remove o logo anterior depois de confirmar a gravação no banco.
        if ($fileName && $oldLogo && File::isFile($storePath.DIRECTORY_SEPARATOR.basename($oldLogo))) {
            try {
                File::delete($storePath.DIRECTORY_SEPARATOR.basename($oldLogo));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->route('app.company.index')->with('success', 'Dados da filial alterados com sucesso!');
    }
}
