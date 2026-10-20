<?php

namespace App\Http\Requests;

use App\Support\TenantRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', TenantRule::exists('suppliers')],
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.part_id' => ['required', TenantRule::exists('parts')],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'fornecedor',
            'expected_date' => 'previsão de entrega',
            'notes' => 'observações',
            'items' => 'itens',
            'items.*.part_id' => 'peça',
            'items.*.quantity' => 'quantidade',
            'items.*.unit_cost' => 'custo unitário',
        ];
    }
}
