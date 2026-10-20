<?php

namespace App\Support;

use App\Models\App\Order;

/**
 * Assinatura digital do cliente no recebimento do equipamento. O PNG fica no armazenamento
 * privado da OS (App\Support\OrderFiles: {tenant_id}/{order_id}/signature.png) e só é
 * entregue por URL assinada.
 */
final class OrderSignature
{
    public static function store(Order $order, string $base64): bool
    {
        $image = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $base64), true);

        if ($image === false || $image === '') {
            return false;
        }

        OrderFiles::storeSignature($order, $image);

        $order->forceFill(['customer_signature_captured_at' => now()])->save();

        return true;
    }

    public static function exists(Order $order): bool
    {
        return OrderFiles::hasSignature($order);
    }

    public static function url(Order $order): ?string
    {
        return OrderFiles::signatureUrl($order);
    }

    public static function contents(Order $order): ?string
    {
        return OrderFiles::signatureContents($order);
    }
}
