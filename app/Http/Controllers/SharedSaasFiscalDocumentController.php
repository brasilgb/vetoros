<?php

namespace App\Http\Controllers;

use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\App\FiscalDocument;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\SaasInvoiceService;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF/XML da NFS-e do SaaS para o cliente contratante, sem login, por link assinado e temporário
 * (FiscalDocumentLinks::forSaas). Só notas autorizadas/canceladas; o link não abre outro documento.
 */
class SharedSaasFiscalDocumentController extends Controller
{
    public function __invoke(Request $request, SaasInvoiceService $service, int $document, string $format): Response
    {
        if (! $request->hasValidSignature()) {
            return response()->view('fiscal.shared-link-unavailable', ['reason' => 'expired'], 403);
        }

        $model = AdminFiscalDocument::query()->find($document);

        if (! $model || ! in_array($model->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true)) {
            return response()->view('fiscal.shared-link-unavailable', ['reason' => 'missing'], 404);
        }

        try {
            $content = $service->download($model, $format);
        } catch (FiscalEmissionException|SpedyException) {
            return response()->view('fiscal.shared-link-unavailable', ['reason' => 'temporary'], 503);
        }

        FiscalAdminAudit::record('saas.invoice_file_accessed', $model->tenant_id, $model, ['format' => $format]);

        return response($content, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/xml',
            'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="nfse-vetoros-'.($model->number ?: $model->id).".{$format}\"",
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
