<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\App\Other;
use App\Models\App\StandaloneInvoice;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FiscalDocumentController extends Controller
{
    public function index(NativeFiscalService $fiscal): Response
    {
        Gate::authorize('fiscal-documents.access');

        $documents = FiscalDocument::query()
            ->latest()
            ->limit(50)
            ->get([
                'id',
                'documentable_type',
                'documentable_id',
                'type',
                'provider',
                'environment',
                'number',
                'series',
                'access_key',
                'status',
                'pdf_url',
                'xml_url',
                'error_message',
                'issued_at',
                'cancelled_at',
                'created_at',
            ]);

        // Notas avulsas (VETOR-FISCAL-AVULSA-01): tomador e situação do financeiro opcional.
        $standalone = StandaloneInvoice::query()
            ->whereIn('id', $documents->where('documentable_type', StandaloneInvoice::class)->pluck('documentable_id'))
            ->with(['customer:id,name', 'receivable:id,status,balance_amount,due_date'])
            ->get()
            ->keyBy('id');

        $documents = $documents->map(function (FiscalDocument $document) use ($standalone) {
            $native = $document->isNative();
            $hasFile = $native && in_array($document->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true);
            $invoice = $document->documentable_type === StandaloneInvoice::class ? $standalone->get($document->documentable_id) : null;
            $retryable = in_array($document->status, [FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_FAILED], true);

            return [
                ...$document->only(['id', 'documentable_type', 'documentable_id', 'type', 'provider', 'environment', 'number', 'series', 'access_key', 'status', 'error_message', 'issued_at', 'cancelled_at', 'created_at']),
                'pdf_url' => $hasFile ? route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'pdf']) : $document->pdf_url,
                'xml_url' => $hasFile ? route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'xml']) : $document->xml_url,
                'can_refresh' => $native && in_array($document->status, [...FiscalDocument::PENDING_STATUSES, FiscalDocument::STATUS_REJECTED], true),
                'can_cancel' => $native && $document->status === FiscalDocument::STATUS_AUTHORIZED,
                'standalone' => $invoice ? [
                    'id' => $invoice->id,
                    'customer' => $invoice->customer?->name,
                    'total_amount' => (float) $invoice->total_amount,
                    'discarded' => $invoice->isDiscarded(),
                    'finance_mode' => $invoice->finance_mode,
                    'receivable_status' => $invoice->receivable?->status,
                    'can_reprocess' => $retryable && ! $invoice->isDiscarded(),
                    'can_discard' => $retryable && ! $invoice->isDiscarded(),
                ] : null,
            ];
        });

        return Inertia::render('app/fiscal-documents/index', [
            'documents' => $documents,
            'standalone' => $this->standaloneOptions($fiscal),
        ]);
    }

    /** O que o modal de nota avulsa pode oferecer: modelos liberados, tributação padrão e financeiro. */
    private function standaloneOptions(NativeFiscalService $fiscal): array
    {
        $tenantId = (int) resolveCurrentTenantId();
        $setting = FiscalSetting::query()->where('tenant_id', $tenantId)->first();
        $models = [];

        foreach ([SpedyClient::MODEL_NFSE, SpedyClient::MODEL_NFE, SpedyClient::MODEL_NFCE] as $model) {
            $models[$model] = $fiscal->blocker($tenantId, $model);
        }

        return [
            'blockers' => $models,
            'environment' => $setting?->emission_environment,
            'nfse' => [
                'service_list_item' => $setting?->service_list_item,
                'iss_rate' => $setting?->default_iss_rate !== null ? (float) $setting->default_iss_rate : null,
                'taxation_type' => $setting?->nfse_taxation_type,
                'city_code' => $setting?->service_city_code,
            ],
            'products' => [
                'tax_regime' => $setting?->company_tax_regime,
                'icms_situation' => $setting?->default_icms_situation,
                'commercial_unit' => $setting?->default_commercial_unit,
            ],
            'finance_enabled' => Other::financeEnabled($tenantId) && (bool) Auth::user()?->hasPermission('finance'),
            'payment_methods' => StandaloneInvoiceController::PAYMENT_METHODS,
        ];
    }
}
