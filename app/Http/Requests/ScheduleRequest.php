<?php

namespace App\Http\Requests;

use App\Models\App\Schedule;
use App\Support\TenantRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'material_checklist' => Schedule::normalizeMaterialChecklist($this->input('material_checklist', [])),
            'technician_checklist' => Schedule::normalizeTechnicianChecklist($this->input('technician_checklist', [])),
        ]);
    }

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
            'customer_id' => 'required',
            'order_id' => ['nullable', TenantRule::exists('orders')],
            'user_id' => 'required',
            'schedules' => 'required',
            'service' => 'required|string|max:500',
            'details' => 'nullable|string|max:500',
            'material_checklist' => 'nullable|array',
            'material_checklist.*.name' => 'required|string|max:150',
            'material_checklist.*.quantity' => 'required|integer|min:1|max:999',
            'material_checklist.*.part_id' => ['nullable', 'integer', TenantRule::exists('parts')],
            'material_checklist.*.used' => 'boolean',
            'technician_checklist' => 'nullable|array',
            'technician_checklist.*' => 'required|string|max:500',
            'observations' => 'nullable|string|max:500',
            'status' => 'required',
            'send_to_technician' => 'boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'cliente',
            'user_id' => 'técnico',
            'schedules' => 'horário da visita',
            'service' => 'serviço',
            'details' => 'detalhes do serviço',
            'material_checklist' => 'checklist de material',
            'technician_checklist' => 'checklist técnico',
            'status' => 'status do agendamento',
            'send_to_technician' => 'enviar ao técnico',
        ];
    }
}
