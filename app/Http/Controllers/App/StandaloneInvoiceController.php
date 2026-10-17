<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\Customer;
use App\Models\App\FiscalDocument;
use App\Models\App\Part;
use App\Models\App\StandaloneInvoice;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\Fiscal\StandaloneInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Nota fiscal avulsa pela tela Notas Fiscais (VETOR-FISCAL-AVULSA-01). Exige a permissão de
 * notas fiscais; o lançamento no financeiro exige também a permissão financeira e o módulo
 * financeiro ativo. Clientes e peças são buscados só dentro do tenant.
 */
class StandaloneInvoiceController extends Controller
{
    public const PAYMENT_METHODS = ['pix', 'cartao', 'dinheiro', 'transferencia', 'boleto'];

    public function __construct(private readonly StandaloneInvoiceService $service) {}

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('fiscal-documents.access');
        $tenantId = (int) resolveCurrentTenantId();
        $products = [SpedyClient::MODEL_NFE, SpedyClient::MODEL_NFCE];

        $validated = $request->validate([
            // Chave gerada ao abrir a confirmação: duplo clique ou reenvio não duplicam nota nem financeiro.
            'request_key' => ['required', 'uuid'],
            'type' => ['required', Rule::in([SpedyClient::MODEL_NFE, SpedyClient::MODEL_NFCE, SpedyClient::MODEL_NFSE])],
            'customer_id' => [
                Rule::requiredIf(fn () => $request->input('type') !== SpedyClient::MODEL_NFCE),
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'items' => [Rule::requiredIf(fn () => in_array($request->input('type'), $products, true)), 'array', 'max:100'],
            'items.*.part_id' => ['required', 'integer', 'distinct', Rule::exists('parts', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:999999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'description' => ['required_if:type,'.SpedyClient::MODEL_NFSE, 'nullable', 'string', 'max:2000'],
            'service_amount' => ['required_if:type,'.SpedyClient::MODEL_NFSE, 'nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'service_list_item' => ['nullable', 'string', 'max:20'],
            'iss_rate' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'payment_method' => [
                Rule::requiredIf(fn () => in_array($request->input('type'), $products, true) || $request->input('finance_mode') === StandaloneInvoice::FINANCE_RECEIVED),
                'nullable',
                Rule::in(self::PAYMENT_METHODS),
            ],
            'finance_mode' => ['required', Rule::in([StandaloneInvoice::FINANCE_NONE, StandaloneInvoice::FINANCE_RECEIVABLE, StandaloneInvoice::FINANCE_RECEIVED])],
            'due_date' => ['required_if:finance_mode,'.StandaloneInvoice::FINANCE_RECEIVABLE, 'nullable', 'date'],
            'paid_at' => ['required_if:finance_mode,'.StandaloneInvoice::FINANCE_RECEIVED, 'nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'customer_id.required' => 'Selecione o cliente (tomador/destinatário).',
            'items.required' => 'Adicione ao menos um produto.',
            'items.*.part_id.distinct' => 'O mesmo produto aparece em mais de uma linha; ajuste a quantidade.',
            'description.required_if' => 'Descreva o serviço prestado.',
            'service_amount.required_if' => 'Informe o valor do serviço.',
            'payment_method.required' => 'Informe a forma de pagamento.',
            'due_date.required_if' => 'Informe o vencimento da conta a receber.',
            'paid_at.required_if' => 'Informe a data do recebimento.',
            'paid_at.before_or_equal' => 'A data do recebimento não pode ser futura.',
            'iss_rate.max' => 'A alíquota de ISS deve ficar entre 0% e 5%.',
        ]);

        try {
            $result = $this->service->request($validated, $tenantId, (int) Auth::id(), (bool) $request->user()?->hasPermission('finance'));
        } catch (FiscalValidationException $exception) {
            // Nada foi gravado: o modal continua aberto com a lista do que corrigir.
            return back()->withErrors(['fiscal' => implode("\n", $exception->problems)]);
        } catch (FiscalEmissionException|SpedyException $exception) {
            return back()->withErrors(['fiscal' => $exception->getMessage()]);
        }

        if ($result['duplicate']) {
            return back()->with('success', 'Esta solicitação já tinha sido registrada; nenhuma nota ou lançamento novo foi criado.');
        }

        return $this->emissionResponse($result['document'], $result['invoice']);
    }

    public function reprocess(StandaloneInvoice $standaloneInvoice): RedirectResponse
    {
        Gate::authorize('fiscal-documents.access');

        try {
            $document = $this->service->reprocess($standaloneInvoice, (int) Auth::id());
        } catch (FiscalValidationException|FiscalEmissionException|SpedyException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->emissionResponse($document, $standaloneInvoice);
    }

    public function discard(StandaloneInvoice $standaloneInvoice): RedirectResponse
    {
        Gate::authorize('fiscal-documents.access');

        if ($standaloneInvoice->account_receivable_id && ! request()->user()?->hasPermission('finance')) {
            return back()->with('error', 'Esta nota avulsa tem lançamento financeiro; o descarte exige a permissão do financeiro.');
        }

        try {
            $this->service->discard($standaloneInvoice, (int) Auth::id());
        } catch (FiscalEmissionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $standaloneInvoice->account_receivable_id
            ? 'Solicitação descartada; a conta a receber em aberto foi cancelada.'
            : 'Solicitação descartada.');
    }

    public function customers(Request $request): JsonResponse
    {
        Gate::authorize('fiscal-documents.access');
        $search = trim((string) $request->get('q', ''));

        $customers = Customer::query()
            ->select(['id', 'name', 'cpfcnpj', 'email', 'zipcode', 'street', 'number', 'district', 'city', 'state'])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('cpfcnpj', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'cpfcnpj' => $customer->cpfcnpj,
                'email' => $customer->email,
                'has_address' => filled($customer->zipcode) && filled($customer->street) && filled($customer->number)
                    && filled($customer->district) && filled($customer->city) && filled($customer->state),
            ]);

        return response()->json($customers);
    }

    public function parts(Request $request): JsonResponse
    {
        Gate::authorize('fiscal-documents.access');
        $search = trim((string) $request->get('q', ''));

        $parts = Part::query()
            ->select(['id', 'name', 'sale_price', 'part_number', 'reference_number', 'ncm', 'cfop'])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('reference_number', 'like', '%'.$search.'%')
                ->orWhere('part_number', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Part $part) => [
                'id' => $part->id,
                'name' => $part->name,
                'sale_price' => (float) $part->sale_price,
                'code' => $part->part_number ?: $part->reference_number,
                'ncm' => $part->ncm,
                'cfop' => $part->cfop,
            ]);

        return response()->json($parts);
    }

    private function emissionResponse(?FiscalDocument $document, StandaloneInvoice $invoice): RedirectResponse
    {
        $finance = match ($invoice->finance_mode) {
            StandaloneInvoice::FINANCE_RECEIVABLE => ' Conta a receber registrada.',
            StandaloneInvoice::FINANCE_RECEIVED => ' Recebimento registrado no caixa.',
            default => '',
        };

        return match ($document?->status) {
            FiscalDocument::STATUS_AUTHORIZED => back()->with('success', 'Nota avulsa autorizada.'.$finance),
            FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_DENIED => back()->with('error', 'Nota avulsa recusada: '.$document->error_message.' Corrija o cadastro e use "Reprocessar".'.$finance),
            FiscalDocument::STATUS_FAILED => back()->with('error', 'Nota avulsa não enviada: '.$document->error_message.$finance),
            default => back()->with('success', 'Nota avulsa enviada para autorização. O resultado aparecerá em instantes.'.$finance),
        };
    }
}
