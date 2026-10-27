<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PlanRequest extends FormRequest
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
        return [
            'name' => 'required',
            'slug' => 'required',
            'description' => 'required|string|max:500',
            'value' => 'required|numeric|min:0',
            'billing_months' => 'required|integer|in:1,3,6,12,14',
            'is_active' => 'sometimes|boolean',
            'is_public' => 'sometimes|boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'description' => 'descrição',
            'value' => 'valor',
            'billing_months' => 'ciclo de cobrança',
        ];
    }
}
