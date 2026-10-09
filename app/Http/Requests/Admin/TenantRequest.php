<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = $this->route('tenant')?->id ?? $this->tenant?->id;

        return [
            'name' => 'required',
            'company' => 'required|string|max:150',
            'cnpj' => ($this->getMethod() == 'POST') ? 'required|cpf_ou_cnpj|unique:tenants' : 'required|cpf_ou_cnpj|unique:tenants,cnpj,'.$tenantId,
            'email' => 'required',
            'phone' => 'required',
            'whatsapp' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:50',
            'state' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:150',
            'complement' => 'nullable|string|max:100',
            'number' => 'nullable|string|max:50',
            'plan_id' => 'required|exists:plans,id',
            'period_id' => [
                'nullable',
                Rule::exists('periods', 'id')->where(function ($query) {
                    $planId = $this->input('plan_id');

                    if ($planId !== null && $planId !== '') {
                        $query->where('plan_id', $planId);
                    }
                }),
            ],
            'status' => 'required',
            'observations' => 'nullable|string|max:500',
            'automatic_fiscal_emission_enabled' => 'sometimes|boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'company' => 'nome da empresa',
            'cnpj' => 'CNPJ',
            'email' => 'e-mail',
            'phone' => 'telefone',
            'whatsapp' => 'whatsapp',
            'zip_code' => 'CEP',
            'state' => 'estado',
            'city' => 'cidade',
            'district' => 'bairro',
            'street' => 'rua',
            'complement' => 'complemento',
            'number' => 'número',
            'status' => 'status',
            'plan_id' => 'plano',
            'period_id' => 'período',
            'observations' => 'observações',
            'automatic_fiscal_emission_enabled' => 'emissão fiscal automática',
        ];
    }
}
