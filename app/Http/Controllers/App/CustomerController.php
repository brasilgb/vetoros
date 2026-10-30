<?php

namespace App\Http\Controllers\App;

use App\Exceptions\WhatsAppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Models\App\AccountReceivable;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\WhatsappMessage;
use App\Services\OrderCommunicationContextService;
use App\Services\WhatsAppService;
use App\Support\OrderStatus;
use App\Support\TenantSequence;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function duplicateCheck(Request $request)
    {
        Gate::authorize('customers.access');

        $cpfcnpj = preg_replace('/\D+/', '', (string) $request->input('cpfcnpj'));
        $phone = preg_replace('/\D+/', '', (string) $request->input('phone'));
        $whatsapp = preg_replace('/\D+/', '', (string) $request->input('whatsapp'));
        $email = mb_strtolower(trim((string) $request->input('email')));
        $excludeId = $request->integer('exclude_id');

        if ($cpfcnpj === '' && $phone === '' && $whatsapp === '' && $email === '') {
            return response()->json(['matches' => []]);
        }

        $matches = Customer::query()
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->where(function ($query) use ($cpfcnpj, $phone, $whatsapp, $email) {
                $query
                    ->when($cpfcnpj !== '', fn ($query) => $query->orWhere('cpfcnpj', $cpfcnpj))
                    ->when($phone !== '', fn ($query) => $query->orWhere('phone', $phone)->orWhere('whatsapp', $phone))
                    ->when($whatsapp !== '', fn ($query) => $query->orWhere('whatsapp', $whatsapp)->orWhere('phone', $whatsapp))
                    ->when($email !== '', fn ($query) => $query->orWhereRaw('LOWER(email) = ?', [$email]));
            })
            ->latest('id')
            ->limit(5)
            ->get(['id', 'customer_number', 'name', 'cpfcnpj', 'phone', 'whatsapp', 'email']);

        return response()->json(['matches' => $matches]);
    }

    private function normalizeCsvValue(array $row, int $index): ?string
    {
        if (! array_key_exists($index, $row)) {
            return null;
        }

        $value = trim((string) $row[$index]);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value ?? '');

        if ($value === '') {
            return null;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }

    private function normalizeCsvDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d');
            } catch (\Throwable $e) {
                // tenta o próximo formato
            }
        }

        return null;
    }

    private function normalizeSpreadsheetNumber(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^-?\d+(?:[.,]\d+)?E[+-]?\d+$/i', $value) === 1) {
            $normalized = str_replace(',', '.', $value);

            if (is_numeric($normalized)) {
                return number_format((float) $normalized, 0, '', '');
            }
        }

        return $value;
    }

    private function normalizeCsvEmail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $email = trim($value);

        if ($email === '') {
            return null;
        }

        // E-mails repetidos sao permitidos na importacao; apenas normalizamos o valor.
        return mb_strtolower($email);
    }

    private function limitString(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }

    private function flushCustomerImportBatch(array &$batch, array &$errors, int &$inserted): void
    {
        if (empty($batch)) {
            return;
        }

        try {
            Customer::insert(array_column($batch, 'data'));
            $inserted += count($batch);
            $batch = [];

            return;
        } catch (\Throwable $batchException) {
            // Cai para insercao individual para identificar quais linhas estao invalidas.
        }

        foreach ($batch as $entry) {
            try {
                DB::table('customers')->insert($entry['data']);
                $inserted++;
            } catch (\Throwable $rowException) {
                $errors[] = 'Linha ' . $entry['line'] . ': ' . $rowException->getMessage();
            }
        }

        $batch = [];
    }

    public function ImportCustomer(Request $request)
    {
        Gate::authorize('customers.access');

        ini_set('max_execution_time', 300); // 5 minutos
        ini_set('memory_limit', '512M');

        try {
            $request->validate([
                'arquivo' => 'required|mimes:csv,txt|max:20480',
            ], [
                'arquivo.required' => 'Por favor, selecione um arquivo.',
                'arquivo.mimes' => 'O arquivo deve ser do tipo CSV ou TXT.',
            ]);

            $tenantId = Auth::user()->tenant_id;
            $path = $request->file('arquivo')->getRealPath();
            $file = fopen($path, 'r');

            if ($file === false) {
                throw new \RuntimeException('Não foi possível abrir o arquivo enviado.');
            }

            // Detectar delimitador automaticamente
            $firstLine = fgets($file);
            rewind($file);

            $delimiters = [',', ';', '|'];
            $delimiter = ',';

            $maxCount = 0;
            foreach ($delimiters as $d) {
                $count = substr_count($firstLine, $d);
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $delimiter = $d;
                }
            }

            // Pula cabeçalho
            fgetcsv($file, 0, $delimiter);

            $ultimoNumero = Customer::where('tenant_id', $tenantId)->max('customer_number') ?? 0;
            $dadosParaInserir = [];
            $inserted = 0;
            $skippedEmpty = 0;
            $truncatedFields = 0;
            $lineNumber = 1;
            $errors = [];

            while (($linha = fgetcsv($file, 0, $delimiter)) !== false) {
                $lineNumber++;

                $name = $this->normalizeCsvValue($linha, 0);
                if ($name === null) {
                    $skippedEmpty++;
                    continue;
                }

                $rawCpfCnpj = $this->normalizeCsvValue($linha, 1) ?? '';
                $cpfCnpj = in_array($rawCpfCnpj, ['', '0', '---', '--', '*', 'n/a', 'NULL', 'null'], true)
                    ? null
                    : $this->limitString($rawCpfCnpj, 50);

                $ultimoNumero++;

                $phone = $this->normalizeSpreadsheetNumber($this->normalizeCsvValue($linha, 11));
                $whatsapp = $this->normalizeSpreadsheetNumber($this->normalizeCsvValue($linha, 13));
                $contactPhone = $this->normalizeSpreadsheetNumber($this->normalizeCsvValue($linha, 14));

                $email = $this->limitString($this->normalizeCsvEmail($this->normalizeCsvValue($linha, 3)), 50);
                $zipcode = $this->limitString($this->normalizeCsvValue($linha, 4), 20);
                $state = $this->limitString($this->normalizeCsvValue($linha, 5), 20);
                $city = $this->limitString($this->normalizeCsvValue($linha, 6), 50);
                $district = $this->limitString($this->normalizeCsvValue($linha, 7), 50);
                $street = $this->limitString($this->normalizeCsvValue($linha, 8), 80);
                $complement = $this->limitString($this->normalizeCsvValue($linha, 9), 80);
                $contactName = $this->limitString($this->normalizeCsvValue($linha, 12), 50);
                $observations = $this->limitString($this->normalizeCsvValue($linha, 15), 500);

                $dadosParaInserir[] = [
                    'line' => $lineNumber,
                    'data' => [
                        'tenant_id' => $tenantId,
                        'customer_number' => $ultimoNumero,
                        'name' => $name,
                        'cpfcnpj' => $cpfCnpj,
                        'birth' => $this->normalizeCsvDate($this->normalizeCsvValue($linha, 2)),
                        'email' => $email,
                        'zipcode' => $zipcode,
                        'state' => $state,
                        'city' => $city,
                        'district' => $district,
                        'street' => $street,
                        'complement' => $complement,
                        'number' => is_numeric((string) $this->normalizeCsvValue($linha, 10)) ? (int) $this->normalizeCsvValue($linha, 10) : null,
                        'phone' => $this->limitString($phone, 20),
                        'contactname' => $contactName,
                        'whatsapp' => $this->limitString($whatsapp, 255),
                        'contactphone' => $this->limitString($contactPhone, 20),
                        'observations' => $observations,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ];

                $truncatedFields += (int) ($email !== $this->normalizeCsvEmail($this->normalizeCsvValue($linha, 3)));
                $truncatedFields += (int) ($zipcode !== $this->normalizeCsvValue($linha, 4));
                $truncatedFields += (int) ($state !== $this->normalizeCsvValue($linha, 5));
                $truncatedFields += (int) ($city !== $this->normalizeCsvValue($linha, 6));
                $truncatedFields += (int) ($district !== $this->normalizeCsvValue($linha, 7));
                $truncatedFields += (int) ($street !== $this->normalizeCsvValue($linha, 8));
                $truncatedFields += (int) ($complement !== $this->normalizeCsvValue($linha, 9));
                $truncatedFields += (int) ($contactName !== $this->normalizeCsvValue($linha, 12));
                $truncatedFields += (int) ($this->limitString($phone, 20) !== $phone);
                $truncatedFields += (int) ($this->limitString($whatsapp, 255) !== $whatsapp);
                $truncatedFields += (int) ($this->limitString($contactPhone, 20) !== $contactPhone);
                $truncatedFields += (int) ($observations !== $this->normalizeCsvValue($linha, 15));

                if (count($dadosParaInserir) >= 100) {
                    $this->flushCustomerImportBatch($dadosParaInserir, $errors, $inserted);
                }
            }

            if (! empty($dadosParaInserir)) {
                $this->flushCustomerImportBatch($dadosParaInserir, $errors, $inserted);
            }

            fclose($file);

            $message = "Importação concluída: {$inserted} clientes salvos.";
            if ($skippedEmpty > 0 || $truncatedFields > 0 || count($errors) > 0) {
                $message .= ' Ajustados:';
                if ($skippedEmpty > 0) {
                    $message .= " {$skippedEmpty} linhas sem nome ignoradas";
                }
                if ($truncatedFields > 0) {
                    $message .= ($skippedEmpty > 0 ? ' e' : '') . " {$truncatedFields} campos longos truncados";
                }
                if (count($errors) > 0) {
                    $message .= ($skippedEmpty > 0 || $truncatedFields > 0 ? ' e' : '') . ' ' . count($errors) . ' linhas com erro';
                }
                $message .= '.';
            }

            if (count($errors) > 0) {
                $message .= ' Primeiros erros: ' . implode(' | ', array_slice($errors, 0, 3)) . '.';
            }

            return redirect()->back()->with('import_success', $message);
        } catch (\Exception $e) {
            return redirect()->back()->with('import_error', 'Falha na importação: ' . $e->getMessage());
        }
    }

    public function getClientes()
    {
        Gate::authorize('customers.access');

        $clientes = Customer::get();

        return [
            'success' => true,
            'result' => $clientes,
        ];
    }

    public function preRegister(Request $request)
    {
        abort_unless(
            Auth::user()?->hasPermission('customers') || Auth::user()?->hasPermission('orders'),
            403
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cpfcnpj' => ['nullable', 'string', 'max:50'],
            'birth' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:50'],
            'zipcode' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:50'],
            'district' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:80'],
            'complement' => ['nullable', 'string', 'max:80'],
            'number' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contactname' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:255'],
            'contactphone' => ['nullable', 'string', 'max:20'],
            'observations' => ['nullable', 'string', 'max:500'],
        ]);

        $data['customer_number'] = TenantSequence::next(Customer::class, 'customer_number');

        $customer = Customer::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Pré-cadastro realizado com sucesso.',
            'result' => $customer,
        ], 201);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, OrderCommunicationContextService $orderCommunicationContextService)
    {
        Gate::authorize('customers.access');

        $search = $request->search;
        $pending = $request->get('pending');

        $receivableTotalsSub = AccountReceivable::query()
            ->selectRaw('customer_id, COALESCE(SUM(total_amount), 0) as total_order_amount')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as total_paid_amount')
            ->selectRaw('COALESCE(SUM(balance_amount), 0) as pending_amount')
            ->whereIn('status', [
                AccountReceivable::STATUS_PENDING,
                AccountReceivable::STATUS_PARTIAL,
            ])
            ->groupBy('customer_id');

        $query = Customer::query()
            ->leftJoinSub($receivableTotalsSub, 'receivable_totals', function ($join) {
                $join->on('receivable_totals.customer_id', '=', 'customers.id');
            })
            ->select('customers.*')
            ->selectRaw('COALESCE(receivable_totals.total_order_amount, 0) as total_order_amount')
            ->selectRaw('COALESCE(receivable_totals.total_paid_amount, 0) as total_paid_amount')
            ->selectRaw('COALESCE(receivable_totals.pending_amount, 0) as pending_amount')
            ->orderBy('customers.id', 'DESC');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('customers.name', 'like', '%' . $search . '%')
                    ->orWhere('customers.cpfcnpj', 'like', '%' . $search . '%')
                    ->orWhere('customers.phone', 'like', '%' . $search . '%')
                    ->orWhere('customers.whatsapp', 'like', '%' . $search . '%')
                    ->orWhere('customers.email', 'like', '%' . $search . '%');
            });
        }

        if ($pending === '1') {
            $query->whereRaw('COALESCE(receivable_totals.pending_amount, 0) > 0.009');
        }

        $query->with(['orders' => function ($ordersQuery) {
            $ordersQuery
                ->whereIn('service_status', [OrderStatus::BUDGET_GENERATED, OrderStatus::SERVICE_COMPLETED, OrderStatus::DELIVERED])
                ->with('orderPayments:id,order_id,amount')
                ->orderBy('id', 'DESC');
        }]);

        $customers = $query->paginate(\App\Support\Pagination::perPage())->withQueryString();
        $tenantId = (int) Auth::user()?->tenant_id;

        $customers->setCollection(
            $customers->getCollection()->map(function (Customer $customer) use ($orderCommunicationContextService, $tenantId) {
                $customer->setAttribute(
                    'whatsapp_options',
                    $this->buildCustomerWhatsappOptions($customer, $orderCommunicationContextService, $tenantId)
                );

                return $customer;
            })
        );

        $customerlast = Customer::orderBy('id', 'DESC')->first();

        return Inertia::render('app/customers/index', [
            'customers' => $customers,
            'customerlast' => $customerlast,
            'search' => $search,
            'pending' => $pending,
            'whats' => WhatsappMessage::first(),
        ]);
    }

    /**
     * Monta, por cliente, uma opção de envio de WhatsApp para cada ordem que tenha um
     * contexto de comunicação relevante no momento (cobrança pendente, orçamento parado,
     * aguardando feedback, orçamento recém-gerado ou serviço concluído). Usa exatamente
     * as mesmas regras da listagem de Ordens (OrderCommunicationContextService), então o
     * que aparece aqui é consistente com o que o operador já vê lá.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomerWhatsappOptions(Customer $customer, OrderCommunicationContextService $service, ?int $tenantId): array
    {
        $options = [];

        foreach ($customer->orders as $order) {
            $remaining = round(max(0, (float) ($order->service_cost ?? 0) - (float) $order->orderPayments->sum('amount')), 2);

            $isPendingPayment = $service->isPendingPayment($order, $tenantId, $remaining);
            $isBudgetFollowUp = $service->isBudgetFollowUp($order, $tenantId);
            $isFeedbackOpen = $service->isFeedbackWindowOpen($order, $tenantId) && ! $order->customer_feedback_submitted_at;

            if ($isPendingPayment) {
                $context = 'pending_payment';
                $label = 'Cobrança pendente';
            } elseif ($isBudgetFollowUp) {
                $context = 'budget_follow_up';
                $label = 'Orçamento parado';
            } elseif ($isFeedbackOpen) {
                $context = 'default';
                $label = 'Pedido de feedback';
            } elseif ((int) $order->service_status === OrderStatus::BUDGET_GENERATED) {
                $context = 'default';
                $label = 'Orçamento gerado';
            } elseif ((int) $order->service_status === OrderStatus::SERVICE_COMPLETED) {
                $context = 'default';
                $label = 'Serviço concluído';
            } else {
                continue;
            }

            $options[] = [
                'key' => "order-{$order->id}",
                'label' => "{$label} — OS #{$order->order_number}",
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'context' => $context,
                'status' => (int) $order->service_status,
                'feedback' => $isFeedbackOpen,
                'amount_due' => $remaining,
                'days_pending' => $service->communicationDaysPending($order),
            ];
        }

        return $options;
    }

    /**
     * Busca leve usada por selects com autocomplete (ordens, orçamentos, agendamentos, PDV).
     * Retorna só os campos necessários pra exibir/selecionar, sem carregar a tabela inteira.
     */
    public function search(Request $request)
    {
        Gate::authorize('customers.access');

        $search = trim((string) $request->get('q', ''));

        $customers = Customer::query()
            ->select(['id', 'name', 'cpfcnpj'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('cpfcnpj', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('whatsapp', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($customers);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        Gate::authorize('customers.access');

        return Inertia::render('app/customers/create-customer');
    }

    /**
     * O form de edição é alimentado com o model `Customer` inteiro (`initialData`
     * do useForm) para não duplicar a definição dos campos no frontend. Isso
     * significa que qualquer relação que algum dia seja anexada ao `$customer`
     * antes de ser enviado pro Inertia (ex.: `equipments`) vaza pro payload do
     * PUT/POST e quebra o `update()`/`create()` com "Column not found" — já
     * aconteceu com `equipments`. Filtra explicitamente pra colunas reais da
     * tabela em vez de confiar em `$request->all()` cru.
     */
    private function customerColumnsFromRequest(Request $request): array
    {
        return $request->except([
            'id', 'tenant_id', 'customer_number', 'created_at', 'updated_at',
            'orders', 'sales', 'schedules', 'maintenanceContracts', 'equipments',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CustomerRequest $request): RedirectResponse
    {
        Gate::authorize('customers.access');

        $data = $this->customerColumnsFromRequest($request);
        $request->validated();
        $data['customer_number'] = TenantSequence::next(Customer::class, 'customer_number');
        Customer::create($data);

        return redirect()->route('app.customers.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer, Request $request)
    {
        Gate::authorize('customers.access');

        return Inertia::render('app/customers/edit-customer', [
            'customer' => $customer,
            'equipments' => $customer->equipments()->latest()->get(),
            'equipmentTypes' => Equipment::orderBy('equipment')->get(['id', 'equipment']),
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Customer $customer, Request $request)
    {
        Gate::authorize('customers.access');

        return Redirect::route('app.customers.show', [
            'customer' => $customer->id,
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('customers.access');

        $data = $this->customerColumnsFromRequest($request);
        $request->validated();
        $customer->update($data);

        return redirect()->route('app.customers.show', ['customer' => $customer->id]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        Gate::authorize('customers.access');

        if ($customer->orders()->exists() || $customer->sales()->exists() || $customer->schedules()->exists() || $customer->maintenanceContracts()->exists() || $customer->equipments()->exists()) {
            return redirect()->route('app.customers.index')->with(
                'error',
                'Não é possível excluir este cliente porque existem ordens, vendas, agendamentos, contratos de manutenção ou equipamentos vinculados.'
            );
        }

        $customer->delete();

        return redirect()->route('app.customers.index')->with('success', 'Cliente excluido com sucesso!');
    }

    /**
     * Envia, via WAHA, uma mensagem de WhatsApp para o telefone cadastrado do cliente.
     */
    public function sendWhatsapp(Request $request, Customer $customer, WhatsAppService $whatsAppService): RedirectResponse
    {
        Gate::authorize('customers.access');

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $whatsAppService->sendText((int) $customer->tenant_id, $customer->whatsapp, $validated['message']);
        } catch (WhatsAppException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Mensagem encaminhada ao WhatsApp. Acompanhe o status de entrega.');
    }
}
