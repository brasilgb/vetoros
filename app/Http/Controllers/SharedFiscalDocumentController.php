<?php

namespace App\Http\Controllers;

use App\Models\App\AccountReceivable;
use App\Models\App\FiscalDocument;
use App\Models\App\MaintenanceContractLog;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF/XML da NFS-e para o cliente final, sem login, por link assinado e temporário
 * (FiscalDocumentLinks). Serve só notas autorizadas/canceladas de cobrança de contrato, a partir da
 * cópia no disco fiscal. O documento é buscado pelo id assinado, sem escopo de sessão: o link não
 * depende de quem está logado e não abre documentos fora do que foi assinado.
 */
class SharedFiscalDocumentController extends Controller
{
    public function __invoke(Request $request, NativeFiscalService $fiscal, int $document, string $format): Response
    {
        if (! $request->hasValidSignature()) {
            return response()->view('fiscal.shared-link-unavailable', ['reason' => 'expired'], 403);
        }

        $model = FiscalDocument::query()->withoutGlobalScopes()->find($document);

        if (! $model
            || $model->documentable_type !== AccountReceivable::class
            || ! in_array($model->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true)) {
            return response()->view('fiscal.shared-link-unavailable', ['reason' => 'missing'], 404);
        }

        try {
            $content = $fiscal->download($model, $format);
        } catch (FiscalEmissionException|SpedyException) {
            return response()->view('fiscal.shared-link-unavailable', ['reason' => 'temporary'], 503);
        }

        $this->recordAccess($model, $format);
        $name = sprintf('nfse-%s.%s', $model->number ?: $model->id, $format);

        return response($content, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/xml',
            'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function recordAccess(FiscalDocument $document, string $format): void
    {
        $receivable = AccountReceivable::query()->withoutGlobalScopes()->find($document->documentable_id);

        if (! $receivable?->isMaintenanceContract()) {
            return;
        }

        MaintenanceContractLog::query()->withoutGlobalScopes()->create([
            'tenant_id' => $receivable->tenant_id,
            'maintenance_contract_id' => $receivable->source_id,
            'user_id' => null,
            'action' => 'invoice_file_accessed',
            'data' => ['account_receivable_id' => $receivable->id, 'fiscal_document_id' => $document->id, 'format' => $format],
        ]);
    }
}
