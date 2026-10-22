<?php

namespace App\Http\Requests;

use App\Models\App\Equipment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentRequest extends FormRequest
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
            'equipment' => 'required',
            'kind' => ['nullable', Rule::in(array_keys(Equipment::kinds()))],
        ];
    }

    public function attributes(): array
    {
        return [
            'equipment' => 'tipo de equipamento',
            'kind' => 'grupo',
        ];
    }
}
