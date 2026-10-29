<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('number') && $this->input('number') !== null) {
            $this->merge(['number' => (string) $this->input('number')]);
        }
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
            'name' => ['required', 'string', 'max:255'],
            'cpfcnpj' => ['required', 'string', 'max:18'],
            // 'email'  => ($this->getMethod() == 'POST') ? 'required|unique:customers' : 'required|unique:customers,email,' . $this->customer->id,
            'phone' => ['required', 'string', 'max:20'],
            // WhatsApp é opcional, mas quando preenchido precisa ser um número com DDD (10 ou 11 dígitos).
            'whatsapp' => ['nullable', 'digits_between:10,11'],
            'zipcode' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:50'],
            'district' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:80'],
            'number' => ['nullable', 'string', 'max:20'],
            'observations' => 'nullable|string|max:500',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'phone' => 'telefone',
            'whatsapp' => 'WhatsApp',
            'cpfcnpj' => 'CPF/CNPJ',
            'zipcode' => 'CEP',
            'state' => 'UF',
            'city' => 'cidade',
            'district' => 'bairro',
            'street' => 'logradouro',
            'number' => 'número',
            'observations' => 'observações',
        ];
    }
}
