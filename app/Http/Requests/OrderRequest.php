<?php

namespace App\Http\Requests;

use App\Models\App\Order;
use App\Support\OrderStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $orderType = $this->input('order_type', Order::TYPE_EQUIPMENT);
        $defect = $this->input('defect');

        if ($orderType === Order::TYPE_EXTERNAL_SERVICE && ! filled($defect)) {
            $defect = $this->input('service_type')
                ?: $this->input('service_details')
                ?: 'Serviço externo';
        }

        $this->merge([
            'order_type' => $orderType,
            'defect' => $defect,
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
            'order_type' => ['required', 'string', Rule::in([Order::TYPE_EQUIPMENT, Order::TYPE_EXTERNAL_SERVICE])],
            'customer_id' => 'required',
            'equipment_id' => [
                Rule::requiredIf($this->input('order_type', Order::TYPE_EQUIPMENT) === Order::TYPE_EQUIPMENT),
                'nullable',
                'exists:equipment,id',
            ],
            'customer_equipment_id' => [
                'nullable',
                Rule::exists('customer_equipments', 'id')->where('customer_id', $this->input('customer_id')),
            ],
            'model' => 'nullable|string|max:50',
            'password' => 'nullable|string|max:50',
            'defect' => [
                Rule::requiredIf($this->input('order_type', Order::TYPE_EQUIPMENT) === Order::TYPE_EQUIPMENT),
                'nullable',
                'string',
                'max:500',
            ],
            'service_type' => [
                Rule::requiredIf($this->input('order_type', Order::TYPE_EQUIPMENT) === Order::TYPE_EXTERNAL_SERVICE),
                'nullable',
                'string',
                'max:150',
            ],
            'service_details' => 'nullable|string|max:500',
            'materials_used' => 'nullable|string|max:500',
            'state_conservation' => 'nullable|string|max:500',
            'accessories' => 'nullable|string|max:500',
            'service_status' => ['required', 'integer', Rule::in(OrderStatus::values())],
            'status_reason' => 'nullable|string|max:500',
            'status_change_kind' => ['nullable', 'string', Rule::in([OrderStatus::KIND_REGRESSION, OrderStatus::KIND_CORRECTION, OrderStatus::KIND_REOPEN])],
            'warranty_days' => 'nullable|integer|min:0|max:3650',
            'is_warranty_return' => 'nullable|boolean',
            'warranty_source_order_id' => [
                Rule::requiredIf($this->boolean('is_warranty_return')),
                'nullable',
                'integer',
                'exists:orders,id',
            ],

            'budget_description' => [
                Rule::requiredIf((int) $this->service_status === OrderStatus::BUDGET_GENERATED),
                'nullable',
                'string',
                'max:500',
            ],
            'services_performed' => 'nullable|string|max:500',
            'budget_value' => [
                Rule::requiredIf((int) $this->service_status === OrderStatus::BUDGET_GENERATED),
                function ($attribute, $value, $fail) {
                    // Remove pontos de milhar e troca vírgula por ponto para checar o número real
                    $numericValue = (float) str_replace(['.', ','], ['', '.'], $value);

                    if ((int) $this->service_status === OrderStatus::BUDGET_GENERATED && $numericValue <= 0) {
                        $fail('O valor do orçamento deve ser maior que 0,00 quando for "Orçamento gerado".');
                    }
                },
            ],
            'budget_link' => 'nullable|string|max:2048',
            'manual_parts_value' => ['nullable', $this->nonNegativeMoney()],
            'manual_parts_cost' => ['nullable', $this->nonNegativeMoney()],
            'discount_amount' => ['nullable', $this->nonNegativeMoney()],
            'surcharge_amount' => ['nullable', $this->nonNegativeMoney()],
            'delivery_forecast_reason' => 'nullable|string|max:500',
            'budget_valid_until' => 'nullable|date',

            'user_id' => 'nullable|exists:users,id',
            'schedule_id' => 'nullable|exists:schedules,id',
            'delivery_date' => 'nullable|date',
            'delivery_forecast' => 'required|date',
            'observations' => 'nullable|string|max:500',
        ];
    }

    /**
     * Aceita "1.234,56", "1234.56" ou número; recusa negativo e texto não numérico.
     */
    private function nonNegativeMoney(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $raw = trim((string) $value);
            $normalized = str_contains($raw, ',') ? str_replace(',', '.', str_replace('.', '', $raw)) : $raw;

            if ($raw !== '' && (! is_numeric($normalized) || (float) $normalized < 0)) {
                $fail('Informe um valor válido, maior ou igual a zero.');
            }
        };
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'cliente',
            'order_type' => 'tipo da ordem',
            'budget_description' => 'descrição do orçamento',
            'budget_value' => 'valor do orçamento',
            'budget_link' => 'link orçamento de peças',
            'equipment_id' => 'equipamento',
            'customer_equipment_id' => 'equipamento do cliente',
            'model' => 'marca e modelo',
            'password' => 'senha',
            'defect' => 'defeito',
            'service_type' => 'tipo do serviço',
            'service_details' => 'detalhes do serviço',
            'materials_used' => 'materiais utilizados',
            'state_conservation' => 'estado de conservação',
            'accessories' => 'acessórios',
            'user_id' => 'técnico',
            'manual_parts_value' => 'peças avulsas',
            'manual_parts_cost' => 'custo das peças avulsas',
            'discount_amount' => 'desconto',
            'surcharge_amount' => 'acréscimo',
            'delivery_forecast_reason' => 'motivo da alteração do prazo',
            'budget_valid_until' => 'validade do orçamento',
            'status_reason' => 'motivo da alteração de status',
            'status_change_kind' => 'tipo da alteração de status',
            'schedule_id' => 'agendamento',
            'delivery_date' => 'data de entrega',
            'warranty_days' => 'garantia em dias',
            'is_warranty_return' => 'retorno em garantia',
            'warranty_source_order_id' => 'ordem de serviço de origem da garantia',
            'delivery_forecast' => 'previsão de entrega',
            'observations' => 'observações',
        ];
    }
}
