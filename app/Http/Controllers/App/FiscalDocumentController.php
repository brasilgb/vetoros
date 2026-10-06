<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\FiscalDocument;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FiscalDocumentController extends Controller
{
    public function index(): Response
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
            ])
            ->map(function (FiscalDocument $document) {
                $native = $document->isNative();
                $hasFile = $native && in_array($document->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true);

                return [
                    ...$document->only(['id', 'documentable_type', 'documentable_id', 'type', 'provider', 'environment', 'number', 'series', 'access_key', 'status', 'error_message', 'issued_at', 'cancelled_at', 'created_at']),
                    'pdf_url' => $hasFile ? route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'pdf']) : $document->pdf_url,
                    'xml_url' => $hasFile ? route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'xml']) : $document->xml_url,
                    'can_refresh' => $native && in_array($document->status, [...FiscalDocument::PENDING_STATUSES, FiscalDocument::STATUS_REJECTED], true),
                    'can_cancel' => $native && $document->status === FiscalDocument::STATUS_AUTHORIZED,
                ];
            });

        return Inertia::render('app/fiscal-documents/index', ['documents' => $documents]);
    }
}
