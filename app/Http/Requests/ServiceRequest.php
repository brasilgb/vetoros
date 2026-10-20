<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
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
            'equipment_id' => ['required', 'integer', Rule::exists('equipment', 'id')->where('tenant_id', resolveCurrentTenantId())],
            'service' => 'required|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'equipment_id' => 'equipamento',
            'service' => 'serviço',
        ];
    }
}
