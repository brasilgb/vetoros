<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\FiscalDocument;
use App\Models\App\Order;
use App\Models\App\Sale;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class FiscalEmissionController extends Controller
{
    public function __construct(private readonly NativeFiscalService $service) {}

    public function emitSale(Request $request, Sale $sale): RedirectResponse
    {
        abort_unless(Gate::allows('update', $sale), 403);

        $validated = $request->validate([
            'model' => 'required|in:'.SpedyClient::MODEL_NFE.','.SpedyClient::MODEL_NFCE,
        ]);

        return $this->run(fn () => $this->service->emitForSale($sale, $validated['model'], (int) Auth::id()));
    }

    public function emitOrder(Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        return $this->run(fn () => $this->service->emitForOrder($order, (int) Auth::id()));
    }

    public function refresh(FiscalDocument $fiscalDocument): RedirectResponse
    {
        Gate::authorize('fiscal-documents.access');

        try {
            $document = $this->service->refresh($fiscalDocument);
        } catch (FiscalEmissionException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Status da nota atualizado: '.$this->statusLabel($document->status).'.');
    }

    public function cancel(Request $request, FiscalDocument $fiscalDocument): RedirectResponse
    {
        Gate::authorize('fiscal-documents.access');
        $this->authorizeDocumentable($fiscalDocument);

        $validated = $request->validate([
            'reason' => 'required|string|min:15|max:255',
        ], [
            'reason.min' => 'A justificativa deve ter ao menos 15 caracteres.',
        ]);

        try {
            $document = $this->service->cancel($fiscalDocument, $validated['reason'], (int) Auth::id());
        } catch (FiscalEmissionException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $document->status === FiscalDocument::STATUS_CANCELLED
            ? 'Nota fiscal cancelada.'
            : 'Cancelamento enviado. O resultado aparecerá em instantes.');
    }

    public function file(FiscalDocument $fiscalDocument, string $format): Response
    {
        Gate::authorize('fiscal-documents.access');
        abort_unless(in_array($format, ['pdf', 'xml'], true), 404);

        try {
            $content = $this->service->download($fiscalDocument, $format);
        } catch (FiscalEmissionException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $name = sprintf('%s-%s.%s', $fiscalDocument->type, $fiscalDocument->number ?: $fiscalDocument->id, $format);

        return response($content, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/xml',
            'Content-Disposition' => ($format === 'pdf' ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function run(callable $emit): RedirectResponse
    {
        try {
            $document = $emit();
        } catch (FiscalValidationException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (FiscalEmissionException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return match ($document->status) {
            FiscalDocument::STATUS_AUTHORIZED => back()->with('success', 'Nota fiscal autorizada.'),
            FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_DENIED => back()->with('error', 'Nota recusada: '.$document->error_message),
            default => back()->with('success', 'Nota fiscal enviada para autorização. O resultado aparecerá em instantes em Notas fiscais.'),
        };
    }

    private function authorizeDocumentable(FiscalDocument $document): void
    {
        $documentable = $document->documentable;

        if ($documentable instanceof Order) {
            $this->authorize('update', $documentable);
        } elseif ($documentable instanceof Sale) {
            abort_unless(Gate::allows('update', $documentable), 403);
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            FiscalDocument::STATUS_AUTHORIZED => 'autorizada',
            FiscalDocument::STATUS_PROCESSING => 'em processamento',
            FiscalDocument::STATUS_CONTINGENCY => 'em contingência',
            FiscalDocument::STATUS_REJECTED => 'rejeitada',
            FiscalDocument::STATUS_DENIED => 'denegada',
            FiscalDocument::STATUS_CANCELLED => 'cancelada',
            default => 'falhou',
        };
    }
}
