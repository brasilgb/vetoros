<?php

namespace App\Http\Controllers;

use App\Models\App\Image;
use App\Models\App\Order;
use App\Support\OrderFiles;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega fotos e assinatura da OS pelas URLs assinadas e temporárias de App\Support\OrderFiles.
 * A assinatura da URL é a autorização: ela só é emitida para quem já pôde ver a OS (tela
 * interna, API do app ou portal público liberado). Qualquer alteração na URL a invalida.
 */
class OrderFileController extends Controller
{
    public function image(int $image): Response
    {
        $image = Image::withoutGlobalScopes()->findOrFail($image);
        $order = Order::withoutGlobalScopes()->findOrFail($image->order_id);

        abort_if($image->tenant_id !== null && (int) $image->tenant_id !== (int) $order->tenant_id, 404);

        return $this->file(OrderFiles::imageContents($image, $order));
    }

    public function signature(int $order): Response
    {
        $order = Order::withoutGlobalScopes()->findOrFail($order);

        return $this->file(OrderFiles::signatureContents($order));
    }

    private function file(?string $contents): Response
    {
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => OrderFiles::mimeType($contents),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
