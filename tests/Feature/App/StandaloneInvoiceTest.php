<?php

namespace Tests\Feature\App;

use App\Models\App\AccountReceivable;
use App\Models\App\AccountReceivablePayment;
use App\Models\App\CashSession;
use App\Models\App\CashSessionMovement;
use App\Models\App\Company;
use App\Models\App\Customer;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\App\Part;
use App\Models\App\StandaloneInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nota fiscal avulsa (VETOR-FISCAL-AVULSA-01): emissão pela tela Notas Fiscais com origem
 * própria, financeiro opcional e Spedy simulada (Http::fake, nenhuma chamada real).
 */
class StandaloneInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'https://sandbox-api.spedy.com.br/v1';

    private const WEBHOOK_SECRET = 'whsec_c2VncmVkby1kZS10ZXN0ZS1kby13ZWJob29r';

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('fiscal');
        Http::fake([
            self::SANDBOX.'/*-invoices/*/xml' => Http::response('<nfeProc>xml</nfeProc>', 200, ['Content-Type' => 'application/xml']),
            self::SANDBOX.'/*-invoices/*/pdf' => Http::response('%PDF-1.4 nota', 200, ['Content-Type' => 'application/pdf']),
        ]);

        config([
            'services.spedy.environment' => 'sandbox',
            'services.spedy.owner_api_key' => 'owner-key-test',
            'services.spedy.webhook_secret' => self::WEBHOOK_SECRET,
            'services.spedy.technical_responsible' => [],
        ]);

        $this->tenant = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true]);
        $this->user = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->prepareTenant($this->tenant);

        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Condomínio Jardim',
            'cpfcnpj' => '11.444.777/0001-61',
            'email' => 'financeiro@condominio.test',
            'zipcode' => '90010-000',
            'state' => 'RS',
            'city' => 'Porto Alegre',
            'district' => 'Centro',
            'street' => 'Rua da Praia',
            'number' => '50',
        ]);
        $this->part = Part::factory()->forTenant($this->tenant->id)->create(['name' => 'Tela Galaxy', 'quantity' => 10, 'ncm' => '85177000', 'cfop' => '5102']);

        $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id]);
    }

    public function test_nfse_without_finance_uses_own_origin_and_configured_taxes(): void
    {
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'enqueued'))]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'enviada para autorização'));

        $invoice = StandaloneInvoice::query()->sole();
        $document = FiscalDocument::query()->sole();

        $this->assertSame(StandaloneInvoice::class, $document->documentable_type);
        $this->assertSame($invoice->id, $document->documentable_id);
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $document->status);
        $this->assertSame('nfse-1', $document->provider_reference);
        $this->assertSame(['service_list_item' => '14.01', 'iss_rate' => 2.5, 'taxation_type' => 'taxationInMunicipality'], $invoice->tax_data);
        $this->assertNull($invoice->account_receivable_id);
        $this->assertSame(0, AccountReceivable::query()->count());

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/service-invoices'
            && $request['integrationId'] === $document->integration_id
            && $request['federalServiceCode'] === '14.01'
            && $request['total']['invoiceAmount'] == 480
            && $request['total']['issRate'] == 2.5
            && $request['receiver']['federalTaxNumber'] === '11444777000161'
            && $request['description'] === 'Consultoria técnica avulsa em rede elétrica');
    }

    public function test_nfse_tax_overrides_are_stored_and_reused_on_reprocess(): void
    {
        Http::fakeSequence(self::SANDBOX.'/service-invoices')
            ->push($this->invoice('nfse-1', 'rejected', ['processingDetail' => ['status' => 'success', 'code' => 'E10', 'message' => 'Item de serviço inválido']]))
            ->push($this->invoice('nfse-1', 'enqueued'));

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse(['service_list_item' => '17.01', 'iss_rate' => 3]))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Item de serviço inválido'));

        $invoice = StandaloneInvoice::query()->sole();
        $first = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_REJECTED, $first->status);

        // Configuração alterada depois: o reprocessamento usa a tributação gravada na solicitação.
        FiscalSetting::query()->update(['service_list_item' => '99.99']);

        $this->post(route('app.fiscal-documents.standalone.reprocess', $invoice))->assertSessionHas('success');

        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $first->refresh()->status);

        $sent = [];
        Http::assertSent(function (HttpRequest $request) use (&$sent) {
            if ($request->method() === 'POST') {
                $sent[] = [$request['integrationId'], $request['federalServiceCode'], $request['total']['issRate']];
            }

            return true;
        });
        $this->assertCount(2, $sent);
        $this->assertSame([$first->integration_id, '17.01', 3.0], $sent[0]);
        $this->assertSame($sent[0], $sent[1]);
    }

    public function test_receivable_is_created_and_authorization_never_settles_it(): void
    {
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'enqueued'))]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse([
            'finance_mode' => StandaloneInvoice::FINANCE_RECEIVABLE,
            'due_date' => now()->addDays(10)->toDateString(),
            'notes' => 'Combinado por telefone',
        ]))->assertSessionHasNoErrors();

        $invoice = StandaloneInvoice::query()->sole();
        $receivable = AccountReceivable::query()->sole();

        $this->assertSame($receivable->id, $invoice->account_receivable_id);
        $this->assertSame(AccountReceivable::SOURCE_STANDALONE_INVOICE, $receivable->source_type);
        $this->assertSame($invoice->id, (int) $receivable->source_id);
        $this->assertSame($this->customer->id, (int) $receivable->customer_id);
        $this->assertSame(480.0, (float) $receivable->balance_amount);
        $this->assertSame(now()->addDays(10)->toDateString(), $receivable->due_date->toDateString());
        $this->assertSame(AccountReceivable::STATUS_PENDING, $receivable->status);

        $body = json_encode(['id' => 'evt-1', 'event' => 'invoice.status_changed', 'data' => $this->invoice('nfse-1', 'authorized')]);
        $this->signedWebhook('evt-1', $body)->assertOk()->assertJson(['processed' => true]);

        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::query()->sole()->status);
        $this->assertSame(AccountReceivable::STATUS_PENDING, $receivable->refresh()->status);
        $this->assertSame(0, AccountReceivablePayment::query()->count());
        $this->assertSame(0, CashSessionMovement::query()->count());
    }

    public function test_received_payment_requires_open_cash_and_enters_the_cash_register(): void
    {
        Http::fake([self::SANDBOX.'/product-invoices' => Http::response($this->invoice('nfe-1', 'authorized', ['model' => 'productInvoice']))]);
        $data = $this->nfe(['finance_mode' => StandaloneInvoice::FINANCE_RECEIVED, 'paid_at' => now()->toDateString()]);

        // Sem caixa aberto nada é gravado e a Spedy não é chamada.
        $this->post(route('app.fiscal-documents.standalone.store'), $data)->assertSessionHasErrors('amount');
        $this->assertSame(0, StandaloneInvoice::query()->count());
        $this->assertSame(0, AccountReceivable::query()->count());
        Http::assertNothingSent();

        $this->openCash();
        $this->post(route('app.fiscal-documents.standalone.store'), $data)
            ->assertSessionHas('success', 'Nota avulsa autorizada. Recebimento registrado no caixa.');

        $receivable = AccountReceivable::query()->sole();
        $payment = AccountReceivablePayment::query()->sole();
        $movement = CashSessionMovement::query()->sole();

        $this->assertSame(AccountReceivable::STATUS_PAID, $receivable->status);
        $this->assertSame(250.0, (float) $payment->amount);
        $this->assertSame('pix', $payment->payment_method);
        $this->assertSame('standalone:'.$data['request_key'], $payment->external_reference);
        $this->assertSame($movement->id, (int) $payment->cash_session_movement_id);
        $this->assertSame(CashSessionMovement::TYPE_ENTRY, $movement->type);
        $this->assertSame(250.0, (float) $movement->amount);

        // A nota não movimenta estoque.
        $this->assertSame(10, (int) $this->part->refresh()->quantity);
    }

    public function test_received_payment_rejects_future_date(): void
    {
        $this->openCash();

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfe([
            'finance_mode' => StandaloneInvoice::FINANCE_RECEIVED,
            'paid_at' => now()->addDays(2)->toDateString(),
        ]))->assertSessionHasErrors('paid_at');

        $this->assertSame(0, StandaloneInvoice::query()->count());
        Http::assertNothingSent();
    }

    public function test_nfe_payload_uses_part_taxes_and_customer_address(): void
    {
        Http::fake([self::SANDBOX.'/product-invoices' => Http::response($this->invoice('nfe-1', 'enqueued', ['model' => 'productInvoice']))]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfe())->assertSessionHasNoErrors();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/product-invoices'
            && $request['receiver']['federalTaxNumber'] === '11444777000161'
            && $request['receiver']['address']['postalCode'] === '90010000'
            && $request['items'][0]['ncm'] === '85177000'
            && $request['items'][0]['cfop'] === 5102
            && $request['items'][0]['quantity'] == 2
            && $request['items'][0]['unitAmount'] == 125
            && $request['payments'][0]['method'] === 'pix'
            && $request['payments'][0]['amount'] == 250);
        $this->assertSame(250.0, (float) StandaloneInvoice::query()->sole()->total_amount);
    }

    public function test_nfce_can_be_issued_without_customer(): void
    {
        FiscalSetting::query()->sole()->update(['nfce_csc_id' => '1', 'nfce_csc' => 'csc']);
        Http::fake([self::SANDBOX.'/consumer-invoices' => Http::response($this->invoice('nfce-1', 'authorized', ['model' => 'consumerInvoice']))]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfe(['type' => 'nfce', 'customer_id' => null, 'payment_method' => 'dinheiro']))
            ->assertSessionHas('success', 'Nota avulsa autorizada.');

        $this->assertSame('nfce', FiscalDocument::query()->sole()->type);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/consumer-invoices'
            && ! isset($request['receiver'])
            && $request['payments'][0]['method'] === 'money');
    }

    public function test_fiscal_problems_are_reported_before_saving_anything(): void
    {
        $this->part->update(['ncm' => null]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfe(['finance_mode' => StandaloneInvoice::FINANCE_RECEIVABLE, 'due_date' => now()->toDateString()]))
            ->assertSessionHasErrors(['fiscal' => 'Informe o NCM (8 dígitos) da peça/produto "Tela Galaxy".']);

        $this->assertSame(0, StandaloneInvoice::query()->count());
        $this->assertSame(0, FiscalDocument::query()->count());
        $this->assertSame(0, AccountReceivable::query()->count());
        Http::assertNothingSent();
    }

    public function test_model_not_released_for_tenant_is_blocked(): void
    {
        FiscalSetting::query()->update(['nfse_allowed' => false]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse())
            ->assertSessionHasErrors(['fiscal' => 'Este tipo de nota ainda não foi liberado para esta conta pela administração.']);

        $this->assertSame(0, StandaloneInvoice::query()->count());
        Http::assertNothingSent();
    }

    public function test_duplicate_request_key_does_not_duplicate_invoice_or_finance(): void
    {
        $this->openCash();
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'enqueued'))]);
        $data = $this->nfse(['finance_mode' => StandaloneInvoice::FINANCE_RECEIVED, 'paid_at' => now()->toDateString(), 'payment_method' => 'pix']);

        $this->post(route('app.fiscal-documents.standalone.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('app.fiscal-documents.standalone.store'), $data)
            ->assertSessionHas('success', 'Esta solicitação já tinha sido registrada; nenhuma nota ou lançamento novo foi criado.');

        $this->assertSame(1, StandaloneInvoice::query()->count());
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(1, AccountReceivable::query()->count());
        $this->assertSame(1, AccountReceivablePayment::query()->count());
        $this->assertSame(1, CashSessionMovement::query()->count());
        Http::assertSentCount(1);
    }

    public function test_timeout_keeps_processing_and_sync_reconciles_by_integration_id(): void
    {
        Http::fake([
            self::SANDBOX.'/service-invoices' => Http::response(['message' => 'boom'], 503),
            self::SANDBOX.'/service-invoices?*' => Http::response(['totalCount' => 1, 'items' => [$this->invoice('nfse-9', 'authorized')]]),
        ]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse())->assertSessionHas('success');

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $document->status);
        $this->assertNull($document->provider_reference);

        // Novo clique em "Reprocessar" com a nota em processamento não envia de novo.
        $this->post(route('app.fiscal-documents.standalone.reprocess', $document->documentable_id))
            ->assertSessionHas('error', 'Já existe uma nota fiscal em processamento para este registro.');

        $document->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
        $this->artisan('fiscal:sync-spedy')->assertSuccessful();

        $document->refresh();
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->status);
        $this->assertSame('nfse-9', $document->provider_reference);
    }

    public function test_definitive_provider_error_keeps_request_for_reprocessing(): void
    {
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response(['errors' => ['receiver.federalTaxNumber' => ['CNPJ inválido']]], 400)]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse())
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'CNPJ inválido'));

        $this->assertSame(FiscalDocument::STATUS_FAILED, FiscalDocument::query()->sole()->status);
        $this->assertSame(1, StandaloneInvoice::query()->count());

        $this->withoutVite()->get(route('app.fiscal-documents.index'))
            ->assertInertia(fn ($page) => $page
                ->where('documents.0.standalone.can_reprocess', true)
                ->where('documents.0.standalone.can_discard', true));
    }

    public function test_authorized_note_is_cancelled_without_touching_finance(): void
    {
        Http::fake([
            self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'authorized')),
            self::SANDBOX.'/service-invoices/nfse-1' => Http::response($this->invoice('nfse-1', 'canceled', ['cancellation' => ['date' => '2026-10-17T15:00:00']])),
        ]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse([
            'finance_mode' => StandaloneInvoice::FINANCE_RECEIVABLE,
            'due_date' => now()->toDateString(),
        ]))->assertSessionHas('success', 'Nota avulsa autorizada. Conta a receber registrada.');

        $document = FiscalDocument::query()->sole();

        $this->post(route('app.fiscal-documents.cancel', $document), ['reason' => 'Serviço lançado em duplicidade pelo atendente'])
            ->assertSessionHas('success', 'Nota fiscal cancelada.');

        $this->assertSame(FiscalDocument::STATUS_CANCELLED, $document->refresh()->status);
        $this->assertSame(AccountReceivable::STATUS_PENDING, AccountReceivable::query()->sole()->status);

        // Nota com histórico (cancelada) não pode ser descartada nem reemitida pela solicitação.
        $this->post(route('app.fiscal-documents.standalone.discard', $document->documentable_id))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'não pode ser descartada'));
    }

    public function test_discard_cancels_open_receivable_but_refuses_when_payment_exists(): void
    {
        $this->openCash();
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'rejected', ['processingDetail' => ['status' => 'success', 'code' => 'E1', 'message' => 'Erro']]))]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse([
            'finance_mode' => StandaloneInvoice::FINANCE_RECEIVED,
            'paid_at' => now()->toDateString(),
            'payment_method' => 'dinheiro',
        ]));
        $paid = StandaloneInvoice::query()->sole();

        $this->post(route('app.fiscal-documents.standalone.discard', $paid))
            ->assertSessionHas('error', 'Esta nota avulsa tem recebimento registrado. Estorne o recebimento antes de descartar a solicitação.');
        $this->assertFalse($paid->refresh()->isDiscarded());

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse([
            'finance_mode' => StandaloneInvoice::FINANCE_RECEIVABLE,
            'due_date' => now()->toDateString(),
        ]));
        $open = StandaloneInvoice::query()->whereKeyNot($paid->id)->sole();

        $this->post(route('app.fiscal-documents.standalone.discard', $open))->assertSessionHas('success');

        $this->assertTrue($open->refresh()->isDiscarded());
        $this->assertSame(AccountReceivable::STATUS_CANCELLED, $open->receivable->status);

        $this->post(route('app.fiscal-documents.standalone.reprocess', $open))
            ->assertSessionHas('error', 'Esta solicitação de nota avulsa foi descartada.');
    }

    public function test_finance_option_requires_module_and_permission(): void
    {
        DB::table('others')->where('tenant_id', $this->tenant->id)->update(['enable_finance' => false]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse([
            'finance_mode' => StandaloneInvoice::FINANCE_RECEIVABLE,
            'due_date' => now()->toDateString(),
        ]))->assertSessionHasErrors('finance_mode');

        $this->assertSame(0, StandaloneInvoice::query()->count());
        Http::assertNothingSent();
    }

    public function test_users_without_fiscal_permission_cannot_issue_or_search(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);
        $this->actingAs($technician);

        // O handler global converte o 403 em redirect com aviso (mesmo padrão das demais telas).
        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse())
            ->assertRedirect()
            ->assertSessionHas('authorization_error');
        $this->getJson(route('app.fiscal-documents.standalone.customers'))->assertForbidden();
        $this->getJson(route('app.fiscal-documents.standalone.parts'))->assertForbidden();

        $this->assertSame(0, StandaloneInvoice::query()->count());
        Http::assertNothingSent();
    }

    public function test_other_tenant_records_are_not_reachable(): void
    {
        $other = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true]);
        $otherCustomer = Customer::factory()->forTenant($other->id)->create(['name' => 'Cliente Alheio', 'cpfcnpj' => '529.982.247-25']);
        $otherPart = Part::factory()->forTenant($other->id)->create(['name' => 'Peça Alheia', 'ncm' => '85177000', 'cfop' => '5102']);
        $otherInvoice = StandaloneInvoice::query()->withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'request_key' => (string) Str::uuid(),
            'type' => 'nfse',
            'customer_id' => $otherCustomer->id,
            'description' => 'Serviço de outra empresa',
            'total_amount' => 100,
        ]);

        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse(['customer_id' => $otherCustomer->id]))
            ->assertSessionHasErrors('customer_id');
        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfe(['items' => [['part_id' => $otherPart->id, 'quantity' => 1, 'unit_price' => 10]]]))
            ->assertSessionHasErrors('items.0.part_id');

        $notFound = 'Não foi possível encontrar o registro desta operação. Atualize a tela e tente novamente.';
        $this->post(route('app.fiscal-documents.standalone.reprocess', $otherInvoice->id))->assertSessionHas('error', $notFound);
        $this->post(route('app.fiscal-documents.standalone.discard', $otherInvoice->id))->assertSessionHas('error', $notFound);

        $this->getJson(route('app.fiscal-documents.standalone.customers', ['q' => 'Alheio']))->assertOk()->assertExactJson([]);
        $this->getJson(route('app.fiscal-documents.standalone.parts', ['q' => 'Alheia']))->assertOk()->assertExactJson([]);

        // Mesma chave de solicitação em outro tenant não colide nem devolve a solicitação alheia.
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'enqueued'))]);
        $this->post(route('app.fiscal-documents.standalone.store'), $this->nfse(['request_key' => $otherInvoice->request_key]))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'enviada para autorização'));
        $this->assertSame(1, StandaloneInvoice::query()->count());
        $this->assertFalse($otherInvoice->refresh()->isDiscarded());
    }

    public function test_page_exposes_modal_options_and_blockers(): void
    {
        FiscalSetting::query()->update(['nfce_csc' => null]);

        $this->withoutVite()->get(route('app.fiscal-documents.index'))
            ->assertOk()
            ->assertDontSee('company-key-secret')
            ->assertInertia(fn ($page) => $page
                ->component('app/fiscal-documents/index')
                ->where('standalone.blockers.nfse', null)
                ->where('standalone.blockers.nfe', null)
                ->where('standalone.blockers.nfce', 'Informe o ID e o CSC da NFC-e nas configurações fiscais.')
                ->where('standalone.nfse.service_list_item', '14.01')
                ->where('standalone.finance_enabled', true));
    }

    private function nfse(array $overrides = []): array
    {
        return [
            'request_key' => (string) Str::uuid(),
            'type' => 'nfse',
            'customer_id' => $this->customer->id,
            'description' => 'Consultoria técnica avulsa em rede elétrica',
            'service_amount' => 480,
            'finance_mode' => StandaloneInvoice::FINANCE_NONE,
            ...$overrides,
        ];
    }

    private function nfe(array $overrides = []): array
    {
        return [
            'request_key' => (string) Str::uuid(),
            'type' => 'nfe',
            'customer_id' => $this->customer->id,
            'items' => [['part_id' => $this->part->id, 'quantity' => 2, 'unit_price' => 125]],
            'payment_method' => 'pix',
            'finance_mode' => StandaloneInvoice::FINANCE_NONE,
            ...$overrides,
        ];
    }

    private function prepareTenant(Tenant $tenant): void
    {
        DB::table('others')->insert([
            'tenant_id' => $tenant->id,
            'navigation' => false,
            'enableparts' => true,
            'enablesales' => true,
            'enable_finance' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Company::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'shortname' => 'Assistência Teste',
            'companyname' => 'Assistencia Teste LTDA',
            'cnpj' => '11.222.333/0001-81',
            'zip_code' => '90000-000',
            'state' => 'RS',
            'city' => 'Porto Alegre',
            'district' => 'Centro',
            'street' => 'Rua dos Andradas',
            'number' => '100',
            'email' => 'contato@assistencia.test',
        ]);

        FiscalSetting::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'spedy_company_id' => 'company-uuid',
            'api_token' => 'company-key-secret',
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED,
            'registered_at' => now(),
            'emission_environment' => FiscalSetting::ENVIRONMENT_HOMOLOGATION,
            'certificate_expires_at' => now()->addYear(),
            'company_tax_regime' => '1',
            'nfe_enabled' => true,
            'nfce_enabled' => true,
            'nfse_enabled' => true,
            'nfe_allowed' => true,
            'nfce_allowed' => true,
            'nfse_allowed' => true,
            'service_list_item' => '14.01',
            'service_city_code' => '4314902',
            'default_iss_rate' => 2.5,
            'default_icms_origin' => '0',
            'default_icms_situation' => '102',
            'default_pis_situation' => '49',
            'default_cofins_situation' => '49',
            'default_commercial_unit' => 'UN',
            'nfse_taxation_type' => 'taxationInMunicipality',
            'tax_settings_confirmed_at' => now(),
        ]);
    }

    private function openCash(): void
    {
        CashSession::query()->withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'opened_by' => $this->user->id,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);
    }

    private function invoice(string $id, string $status, array $extra = []): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'model' => 'serviceInvoice',
            'environmentType' => 'simulation',
            'number' => 77,
            'series' => '1',
            'accessKey' => null,
            'authorization' => $status === 'authorized' ? ['date' => '2026-10-17T10:00:00', 'protocol' => 'PROT-77'] : null,
            'processingDetail' => ['status' => 'success', 'message' => null, 'code' => null],
            ...$extra,
        ];
    }

    private function signedWebhook(string $eventId, string $body)
    {
        $timestamp = time();
        $key = base64_decode(substr(self::WEBHOOK_SECRET, 6));
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', "{$eventId}.{$timestamp}.{$body}", $key, true));

        return $this->call('POST', route('webhook.spedy'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_WEBHOOK_ID' => $eventId,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
        ], $body);
    }
}
