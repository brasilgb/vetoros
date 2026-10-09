<?php

namespace App\Support\Fiscal;

use App\Models\App\FiscalDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Links do cliente final para o PDF/XML de uma NFS-e: URL assinada e temporária (não previsível,
 * não permanente, sem caminho de armazenamento). Expirado o prazo, a empresa reenvia a fatura e
 * novos links são gerados.
 */
final class FiscalDocumentLinks
{
    public static function expiresAt(): Carbon
    {
        return now()->addDays(max(1, (int) config('services.fiscal_links.days', 30)));
    }

    /** @return array{pdf: string, xml: string, expires_at: Carbon} */
    public static function for(FiscalDocument $document): array
    {
        $expiresAt = self::expiresAt();

        return [
            'pdf' => URL::temporarySignedRoute('fiscal-documents.shared', $expiresAt, ['document' => $document->id, 'format' => 'pdf']),
            'xml' => URL::temporarySignedRoute('fiscal-documents.shared', $expiresAt, ['document' => $document->id, 'format' => 'xml']),
            'expires_at' => $expiresAt,
        ];
    }
}
