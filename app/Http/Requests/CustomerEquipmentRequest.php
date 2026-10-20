<?php

namespace App\Http\Requests;

use App\Support\TenantRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerEquipmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $serial = trim((string) $this->input('serial_number'));
        $imei = preg_replace('/\D+/', '', (string) $this->input('imei'));

        $this->merge([
            'serial_number' => $serial === '' ? null : $serial,
            'imei' => $imei === '' ? null : $imei,
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
            'equipment_id' => ['nullable', TenantRule::exists('equipment')],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'imei' => ['nullable', 'digits_between:14,16'],
            'color' => ['nullable', 'string', 'max:255'],
            'accessories' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'equipment_id' => 'categoria',
            'brand' => 'marca',
            'model' => 'modelo',
            'serial_number' => 'número de série',
            'imei' => 'IMEI',
            'color' => 'cor',
            'accessories' => 'acessórios',
            'notes' => 'observações',
            'status' => 'status',
        ];
    }
}
