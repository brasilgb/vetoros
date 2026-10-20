<?php

namespace App\Support;

use App\Models\App\Company;
use App\Models\App\Order;
use Illuminate\Database\Eloquent\Model;

/**
 * Representação pública da OS (portal /os/{token}): só campos expressamente permitidos.
 * Nunca inclui senha do aparelho, custos internos, anotações internas, responsáveis ou
 * identificadores de empresa/usuário. Campo novo no portal entra aqui de propósito.
 */
final class PublicOrderPresenter
{
    private const TRACKING_FIELDS = [
        'order_number', 'tracking_token', 'service_status', 'created_at', 'delivery_forecast', 'delivery_date',
        'model', 'accessories', 'state_conservation', 'defect', 'budget_value', 'budget_description',
        'services_performed', 'parts_value', 'service_value', 'service_cost', 'warranty_days',
        'warranty_expires_at', 'is_warranty_return', 'fiscal_document_number', 'fiscal_document_url',
        'customer_notification_acknowledged_at', 'customer_pickup_acknowledged_at',
        'customer_feedback_rating', 'customer_feedback_comment', 'customer_feedback_submitted_at',
        'customer_update_note', 'customer_update_note_at',
    ];

    private const RECEIPT_FIELDS = [
        'order_number', 'tracking_token', 'defect', 'model', 'delivery_forecast', 'budget_value',
        'budget_description', 'services_performed', 'parts_value', 'service_value', 'service_cost',
    ];

    private const PAYMENT_PROOF_FIELDS = ['order_number', 'model', 'service_cost'];

    private const FISCAL_PROOF_FIELDS = [
        'order_number', 'model', 'fiscal_document_number', 'fiscal_document_url', 'fiscal_issued_at', 'fiscal_notes',
    ];

    // O recibo identifica quem deixou o aparelho (como no papel entregue ao cliente).
    private const RECEIPT_CUSTOMER_FIELDS = ['name', 'cpfcnpj', 'phone', 'street', 'number', 'district'];

    private const TRACKING_COMPANY_FIELDS = ['cnpj', 'companyname', 'shortname', 'logo', 'whatsapp'];

    private const RECEIPT_COMPANY_FIELDS = ['cnpj', 'companyname', 'shortname', 'logo', 'telephone', 'street', 'number', 'district', 'city', 'state'];

    public static function tracking(Order $order): array
    {
        return [
            ...self::fields($order, self::TRACKING_FIELDS),
            'customer' => self::fields($order->customer, ['name']),
            'equipment' => self::fields($order->equipment, ['equipment']),
            'images' => $order->images->map(fn ($image) => ['id' => $image->getKey(), 'url' => OrderFiles::imageUrl($image)])->values()->all(),
            'order_payments' => self::payments($order),
            'warranty_source_order' => self::fields($order->warrantySourceOrder, ['order_number', 'warranty_expires_at']),
        ];
    }

    public static function receipt(Order $order): array
    {
        return [
            ...self::fields($order, self::RECEIPT_FIELDS),
            'customer' => self::fields($order->customer, self::RECEIPT_CUSTOMER_FIELDS),
            'equipment' => self::fields($order->equipment, ['equipment']),
            // Peça no recibo: nome, preço de venda e quantidade; nunca custo ou estoque.
            'order_parts' => $order->orderParts->map(fn ($part) => [
                'id' => $part->getKey(),
                'name' => $part->getAttribute('name'),
                'sale_price' => $part->getAttribute('sale_price'),
                'pivot' => ['quantity' => (int) ($part->getRelation('pivot')?->getAttribute('quantity') ?? 1)],
            ])->values()->all(),
        ];
    }

    public static function paymentProof(Order $order): array
    {
        return [
            ...self::fields($order, self::PAYMENT_PROOF_FIELDS),
            'customer' => self::fields($order->customer, ['name']),
            'equipment' => self::fields($order->equipment, ['equipment']),
            'order_payments' => self::payments($order),
        ];
    }

    public static function fiscalProof(Order $order): array
    {
        return [
            ...self::fields($order, self::FISCAL_PROOF_FIELDS),
            'customer' => self::fields($order->customer, ['name']),
            'equipment' => self::fields($order->equipment, ['equipment']),
        ];
    }

    public static function trackingCompany(?Company $company): ?array
    {
        return self::fields($company, self::TRACKING_COMPANY_FIELDS);
    }

    public static function receiptCompany(?Company $company): ?array
    {
        return self::fields($company, self::RECEIPT_COMPANY_FIELDS);
    }

    private static function payments(Order $order): array
    {
        return $order->orderPayments
            ->map(fn ($payment) => self::fields($payment, ['id', 'amount', 'paid_at', 'payment_method']))
            ->values()
            ->all();
    }

    /** Campos permitidos com casts e datas já serializados como no Eloquent. */
    private static function fields(?Model $model, array $fields): ?array
    {
        if (! $model) {
            return null;
        }

        return array_intersect_key($model->attributesToArray(), array_flip($fields));
    }
}
