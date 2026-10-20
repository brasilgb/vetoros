<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BudgetsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('budgets.access');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Só equipamentos da própria empresa.
            'equipment_id' => ['required', 'integer', Rule::exists('equipment', 'id')->where('tenant_id', resolveCurrentTenantId())],
            'model' => 'required',
            'service' => 'required|string|max:500',
            'description' => 'required|string|max:500',
            'estimated_time' => 'required',
            'part_value' => 'nullable',
            'labor_value' => 'required',
            'total_value' => 'required',
            'warranty' => 'required', // Garantia
            'validity' => 'required',
            'obs' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'equipment_id' => 'equipamento',
            'model' => 'modelo',
            'service' => 'serviço',
            'description' => 'descrição',
            'estimated_time' => 'tempo estimado',
            'part_value' => 'valor das peças',
            'labor_value' => 'valor mão de obra',
            'total_value' => 'valor total',
            'warranty' => 'garantia',
            'validity' => 'validade',
        ];
    }
}
