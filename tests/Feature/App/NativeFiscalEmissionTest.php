<?php

namespace Tests\Feature\App;

use App\Models\App\Company;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\App\Order;
use App\Models\App\OrderItem;
use App\Models\App\Part;
use App\Models\App\Sale;
use App\Models\App\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\FiscalEmissionException;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyCompanyService;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\Fiscal\Spedy\SpedyPayloadBuilder;
use App\Services\SaleService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NativeFiscalEmissionTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'https://sandbox-api.spedy.com.br/v1';

    private const WEBHOOK_SECRET = 'whsec_c2VncmVkby1kZS10ZXN0ZS1kby13ZWJob29r';

    private Tenant $tenant;

    private User $user;

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
        $this->user = User::factory()->forTenant($this->tenant->id)->create();

        DB::table('others')->insert([
            'tenant_id' => $this->tenant->id,
            'navigation' => false,
            'enableparts' => true,
            'enablesales' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Company::query()->create([
            'tenant_id' => $this->tenant->id,
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

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    public function test_emission_is_blocked_until_platform_tenant_and_registration_are_ready(): void
    {
        $service = app(NativeFiscalService::class);

        config(['services.spedy.owner_api_key' => null]);
        $this->assertStringContainsString('não está disponível na plataforma', $service->blocker($this->tenant->id, SpedyClient::MODEL_NFE));

        config(['services.spedy.owner_api_key' => 'owner-key-test']);
        $this->tenant->update(['automatic_fiscal_emission_enabled' => false]);
        $this->assertStringContainsString('não está liberada', $service->blocker($this->tenant->id, SpedyClient::MODEL_NFE));

        $this->tenant->update(['automatic_fiscal_emission_enabled' => true]);
        $this->fiscalSetting(['registration_status' => FiscalSetting::REGISTRATION_PENDING, 'spedy_company_id' => null, 'api_token' => null]);
        $this->assertStringContainsString('cadastro da empresa emissora', $service->blocker($this->tenant->id, SpedyClient::MODEL_NFE));

        $this->fiscalSetting(['certificate_expires_at' => now()->subDay()]);
        $this->assertStringContainsString('certificado digital', $service->blocker($this->tenant->id, SpedyClient::MODEL_NFE));

        $this->fiscalSetting();
        $this->assertNull($service->blocker($this->tenant->id, SpedyClient::MODEL_NFE));
        $this->assertStringContainsString('CSC', $service->blocker($this->tenant->id, SpedyClient::MODEL_NFCE));
    }

    public function test_company_registration_creates_issuer_once_and_keeps_key_encrypted(): void
    {
        $setting = FiscalSetting::query()->create([
            'tenant_id' => $this->tenant->id,
            'enabled' => true,
            'nfe_enabled' => true,
            'company_tax_regime' => '1',
            'state_registration' => '123.456.789',
        ]);

        Http::fake([
            self::SANDBOX.'/companies' => Http::response(['id' => 'company-uuid', 'apiCredentials' => ['apiKey' => 'company-key-secret']]),
            self::SANDBOX.'/companies/company-uuid' => Http::response(['id' => 'company-uuid']),
            self::SANDBOX.'/companies/company-uuid/settings' => Http::response([]),
        ]);

        $service = app(SpedyCompanyService::class);
        $service->sync($setting);
        $service->sync($setting->refresh());

        $setting->refresh();
        $this->assertSame('company-uuid', $setting->spedy_company_id);
        $this->assertSame(FiscalSetting::REGISTRATION_REGISTERED, $setting->registration_status);
        $this->assertSame('company-key-secret', $setting->api_token);
        $this->assertStringNotContainsString('company-key-secret', (string) DB::table('fiscal_settings')->where('id', $setting->id)->value('api_token'));

        Http::assertSentCount(4);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->url() === self::SANDBOX.'/companies'
            && $request->hasHeader('X-Api-Key', 'owner-key-test')
            && $request['federalTaxNumber'] === '11222333000181'
            && $request['taxRegime'] === 'simplesNacional'
            && $request['stateTaxNumber'] === '123456789');
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && $request->url() === self::SANDBOX.'/companies/company-uuid'
            && $request->hasHeader('X-Api-Key', 'owner-key-test'));
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/settings')
            && $request->hasHeader('X-Api-Key', 'company-key-secret')
            && $request['productInvoice']['environmentType'] === 'development'
            && $request['serviceInvoice']['environmentType'] === 'simulation');
    }

    public function test_company_registration_reports_missing_company_data_without_calling_provider(): void
    {
        Company::query()->where('tenant_id', $this->tenant->id)->update(['cnpj' => null, 'street' => null]);
        $this->tenant->update(['cnpj' => '123']);
        $setting = FiscalSetting::query()->create(['tenant_id' => $this->tenant->id]);

        try {
            app(SpedyCompanyService::class)->sync($setting);
            $this->fail('Esperava FiscalValidationException.');
        } catch (FiscalValidationException $exception) {
            $this->assertContains('Informe o CNPJ da empresa (14 dígitos) em Dados da empresa.', $exception->problems);
            $this->assertContains('Selecione o regime tributário nas configurações fiscais.', $exception->problems);
        }

        Http::assertNothingSent();
    }

    public function test_certificate_is_forwarded_and_only_metadata_is_stored(): void
    {
        $setting = $this->fiscalSetting(['certificate_expires_at' => null, 'certificate_subject' => null]);

        Http::fake([
            self::SANDBOX.'/companies/company-uuid/certificates' => Http::response([
                'id' => 'cert-1',
                'subject' => 'ASSISTENCIA TESTE LTDA:11222333000181',
                'expirationAt' => '2027-10-01T00:00:00',
                'isActive' => true,
            ]),
        ]);

        $this->post(route('app.fiscal-settings.certificate'), [
            'certificate' => UploadedFile::fake()->createWithContent('meu-certificado.pfx', 'conteudo-pfx'),
            'password' => 'senha-do-certificado',
        ])->assertSessionHas('success');

        $setting->refresh();
        $this->assertSame('2027-10-01', $setting->certificate_expires_at->toDateString());
        $this->assertStringNotContainsString('senha-do-certificado', json_encode(DB::table('fiscal_settings')->where('id', $setting->id)->first()));
        Http::assertSent(fn (HttpRequest $request) => $request->isMultipart() && $request->hasHeader('X-Api-Key', 'company-key-secret'));
    }

    public function test_nfe_payload_maps_sale_customer_items_and_interstate_cfop(): void
    {
        $setting = $this->fiscalSetting();
        $sale = $this->sale(['state' => 'SC', 'cpfcnpj' => '11.444.777/0001-61']);

        $payload = app(SpedyPayloadBuilder::class)->productInvoice($sale, Company::query()->first(), $setting, 'integration-1');

        $this->assertSame('integration-1', $payload['integrationId']);
        $this->assertSame('interstate', $payload['destination']);
        $this->assertFalse($payload['isFinalCustomer']);
        $this->assertSame('11444777000161', $payload['receiver']['federalTaxNumber']);
        $this->assertSame('SC', $payload['receiver']['address']['city']['state']);
        $this->assertSame(6102, $payload['items'][0]['cfop']);
        $this->assertSame('85177010', $payload['items'][0]['ncm']);
        $this->assertSame(['origin' => 0, 'csosn' => 102], $payload['items'][0]['taxes']['icms']);
        $this->assertSame($payload['items'][0]['quantity'], $payload['items'][0]['quantityTax']);
        $this->assertSame('pix', $payload['payments'][0]['method']);
        $this->assertSame(250.0, $payload['payments'][0]['amount']);
    }

    public function test_nfe_requires_identified_customer_but_nfce_does_not(): void
    {
        $setting = $this->fiscalSetting();
        $sale = $this->sale();
        $sale->update(['customer_id' => null]);
        $sale->unsetRelation('customer');

        try {
            app(SpedyPayloadBuilder::class)->productInvoice($sale, Company::query()->first(), $setting, 'x');
            $this->fail('Esperava FiscalValidationException.');
        } catch (FiscalValidationException $exception) {
            $this->assertStringContainsString('emita NFC-e', $exception->getMessage());
        }

        $payload = app(SpedyPayloadBuilder::class)->consumerInvoice($sale, $setting, 'y');
        $this->assertArrayNotHasKey('receiver', $payload);
        $this->assertTrue($payload['isFinalCustomer']);
    }

    public function test_sale_nfe_emission_reserves_document_and_blocks_duplicates(): void
    {
        $this->fiscalSetting(['nfce_csc_id' => '1', 'nfce_csc' => 'csc']);
        $sale = $this->sale();

        Http::fake([
            self::SANDBOX.'/product-invoices' => Http::response(['id' => 'invoice-1', 'status' => 'enqueued', 'model' => 'productInvoice']),
        ]);

        $this->post(route('app.sales.fiscal.emit', $sale), ['model' => 'nfe'])
            ->assertSessionHas('success');

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalSetting::PROVIDER_SPEDY, $document->provider);
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $document->status);
        $this->assertSame('invoice-1', $document->provider_reference);
        $this->assertNotEmpty($document->integration_id);
        $this->assertTrue($document->request_payload['receiver']['redacted']);

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('X-Api-Key', 'company-key-secret')
            && $request['integrationId'] === $document->integration_id);

        $this->post(route('app.sales.fiscal.emit', $sale), ['model' => 'nfce'])
            ->assertSessionHas('error', 'Já existe uma nota fiscal em processamento para este registro.');

        Http::assertSentCount(1);
    }

    public function test_authorized_invoice_is_mirrored_on_sale(): void
    {
        $this->fiscalSetting();
        $sale = $this->sale();

        Http::fake([
            self::SANDBOX.'/product-invoices' => Http::response($this->invoice('invoice-1', 'authorized')),
        ]);

        $document = app(NativeFiscalService::class)->emitForSale($sale, SpedyClient::MODEL_NFE, $this->user->id);

        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->status);
        $this->assertSame('1234', $document->number);
        $this->assertSame('PROT-1', $document->authorization_protocol);

        $sale->refresh();
        $this->assertSame('1234', $sale->fiscal_document_number);
        $this->assertSame(str_repeat('4', 44), $sale->fiscal_document_key);
        $this->assertNotNull($sale->fiscal_issued_at);

        $this->expectException(FiscalEmissionException::class);
        app(NativeFiscalService::class)->emitForSale($sale, SpedyClient::MODEL_NFE, $this->user->id);
    }

    public function test_rejected_invoice_is_resent_with_same_integration_id(): void
    {
        $this->fiscalSetting();
        $sale = $this->sale();

        Http::fakeSequence(self::SANDBOX.'/product-invoices')
            ->push($this->invoice('invoice-1', 'rejected', ['processingDetail' => ['status' => 'success', 'code' => '778', 'message' => 'NCM inexistente']]))
            ->push($this->invoice('invoice-1', 'enqueued'));

        $service = app(NativeFiscalService::class);
        $first = $service->emitForSale($sale, SpedyClient::MODEL_NFE, $this->user->id);
        $this->assertSame(FiscalDocument::STATUS_REJECTED, $first->status);
        $this->assertSame('[778] NCM inexistente', $first->error_message);

        $second = $service->emitForSale($sale, SpedyClient::MODEL_NFE, $this->user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $second->status);
        $this->assertSame(1, FiscalDocument::query()->count());

        $ids = [];
        Http::assertSent(function (HttpRequest $request) use (&$ids) {
            $ids[] = $request['integrationId'];

            return true;
        });
        $this->assertCount(1, array_unique($ids));
    }

    public function test_validation_problem_marks_document_failed_without_calling_provider(): void
    {
        $this->fiscalSetting();
        $sale = $this->sale();
        Part::query()->update(['ncm' => null]);

        $this->post(route('app.sales.fiscal.emit', $sale), ['model' => 'nfe'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'NCM'));

        $this->assertSame(FiscalDocument::STATUS_FAILED, FiscalDocument::query()->sole()->status);
        Http::assertNothingSent();
    }

    public function test_transient_failure_keeps_processing_and_sync_reconciles_by_integration_id(): void
    {
        $this->fiscalSetting();
        $sale = $this->sale();

        Http::fake([
            // POST sem resposta e, depois, a consulta por integrationId (padrões exatos para não capturar o XML/PDF).
            self::SANDBOX.'/product-invoices' => Http::response(['message' => 'boom'], 503),
            self::SANDBOX.'/product-invoices?*' => Http::response(['totalCount' => 1, 'items' => [$this->invoice('invoice-9', 'authorized')]]),
        ]);

        $document = app(NativeFiscalService::class)->emitForSale($sale, SpedyClient::MODEL_NFE, $this->user->id);
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $document->status);
        $this->assertNull($document->provider_reference);

        $document->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
        $this->artisan('fiscal:sync-spedy')->assertSuccessful();

        $document->refresh();
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->status);
        $this->assertSame('invoice-9', $document->provider_reference);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
            && str_contains($request->url(), 'integrationId='.$document->integration_id));
    }

    public function test_definitive_provider_error_marks_document_failed(): void
    {
        $this->fiscalSetting();
        $sale = $this->sale();

        Http::fake([self::SANDBOX.'/product-invoices' => Http::response(['errors' => ['receiver.federalTaxNumber' => ['CPF inválido']]], 400)]);

        try {
            app(NativeFiscalService::class)->emitForSale($sale, SpedyClient::MODEL_NFE, $this->user->id);
            $this->fail('Esperava SpedyException.');
        } catch (SpedyException $exception) {
            $this->assertStringContainsString('CPF inválido', $exception->getMessage());
            $this->assertStringNotContainsString('company-key-secret', $exception->getMessage());
        }

        $this->assertSame(FiscalDocument::STATUS_FAILED, FiscalDocument::query()->sole()->status);
    }

    public function test_order_nfse_emission_uses_service_items_and_public_pdf_link(): void
    {
        $this->fiscalSetting(['nfse_enabled' => true]);
        $order = $this->order();

        Http::fake([
            self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'authorized', ['model' => 'serviceInvoice'])),
            self::SANDBOX.'/service-invoices/nfse-1/pdf' => Http::response('%PDF-1.4 nfse', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->post(route('app.orders.fiscal.emit', $order))->assertSessionHas('success', 'Nota fiscal autorizada.');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/service-invoices'
            && $request['total']['invoiceAmount'] === 180.0
            && $request['total']['issRate'] === 2.5
            && $request['federalServiceCode'] === '14.01'
            && $request['taxationType'] === 'taxationInMunicipality'
            && str_contains($request['description'], 'Troca de tela')
            && $request['location']['code'] === 4314902);

        $order->refresh();
        $this->assertSame(route('os.fiscal-proof.pdf', ['token' => $order->tracking_token]), $order->fiscal_document_url);

        auth()->logout();
        $this->get(route('os.fiscal-proof.pdf', ['token' => $order->tracking_token]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_cancellation_requires_authorized_note_and_valid_reason(): void
    {
        $this->fiscalSetting();
        $document = $this->nativeDocument(FiscalDocument::STATUS_AUTHORIZED, 'invoice-1');

        $this->post(route('app.fiscal-documents.cancel', $document), ['reason' => 'curta'])
            ->assertSessionHasErrors('reason');

        Http::fake([self::SANDBOX.'/product-invoices/invoice-1' => Http::response($this->invoice('invoice-1', 'canceled', ['cancellation' => ['date' => '2026-10-06T15:00:00']]))]);

        $this->post(route('app.fiscal-documents.cancel', $document), ['reason' => 'Erro de digitação no valor da venda'])
            ->assertSessionHas('success', 'Nota fiscal cancelada.');

        $document->refresh();
        $this->assertSame(FiscalDocument::STATUS_CANCELLED, $document->status);
        $this->assertNotNull($document->cancelled_at);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'DELETE'
            && $request['reason'] === 'Erro de digitação no valor da venda');

        $this->expectException(FiscalEmissionException::class);
        app(NativeFiscalService::class)->cancel($document, 'Erro de digitação no valor da venda', $this->user->id);
    }

    public function test_webhook_rejects_missing_or_invalid_signature(): void
    {
        $body = json_encode(['id' => 'evt-1', 'event' => 'invoice.status_changed', 'data' => ['id' => 'invoice-1', 'status' => 'authorized']]);

        $this->call('POST', route('webhook.spedy'), [], [], [], $this->webhookServer('evt-1', time(), 'v1,assinatura-falsa'), $body)
            ->assertStatus(401);

        $this->call('POST', route('webhook.spedy'), [], [], [], $this->webhookServer('evt-1', time() - 3600, $this->sign('evt-1', time() - 3600, $body)), $body)
            ->assertStatus(401);

        config(['services.spedy.webhook_secret' => null]);
        $this->call('POST', route('webhook.spedy'), [], [], [], $this->webhookServer('evt-1', time(), $this->sign('evt-1', time(), $body)), $body)
            ->assertStatus(401);
    }

    public function test_webhook_applies_status_once_and_ignores_regressions(): void
    {
        $this->fiscalSetting();
        $document = $this->nativeDocument(FiscalDocument::STATUS_PROCESSING, 'invoice-1');

        $authorized = json_encode(['id' => 'evt-1', 'event' => 'invoice.status_changed', 'data' => $this->invoice('invoice-1', 'authorized')]);
        $this->signedWebhook('evt-1', $authorized)->assertOk()->assertJson(['processed' => true]);
        $this->signedWebhook('evt-1', $authorized)->assertOk()->assertJson(['duplicate' => true]);

        $late = json_encode(['id' => 'evt-0', 'event' => 'invoice.status_changed', 'data' => $this->invoice('invoice-1', 'received')]);
        $this->signedWebhook('evt-0', $late)->assertOk();

        $document->refresh();
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->status);
        $this->assertSame(2, DB::table('fiscal_webhook_events')->count());

        $unknown = json_encode(['id' => 'evt-2', 'event' => 'invoice.status_changed', 'data' => $this->invoice('outra-conta', 'authorized')]);
        $this->signedWebhook('evt-2', $unknown)->assertOk()->assertJson(['ignored' => true]);
    }

    public function test_documents_of_other_tenants_are_not_reachable(): void
    {
        $other = Tenant::factory()->create();
        $document = FiscalDocument::query()->create([
            'tenant_id' => $other->id,
            'documentable_type' => Sale::class,
            'documentable_id' => 999,
            'type' => SpedyClient::MODEL_NFE,
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'provider_reference' => 'other-invoice',
            'integration_id' => 'other-integration',
            'status' => FiscalDocument::STATUS_AUTHORIZED,
        ]);

        // O handler global converte registro inexistente em redirect com mensagem.
        $notFound = 'Não foi possível encontrar o registro desta operação. Atualize a tela e tente novamente.';
        $this->post(route('app.fiscal-documents.refresh', $document))->assertSessionHas('error', $notFound);
        $this->post(route('app.fiscal-documents.cancel', $document), ['reason' => 'Tentativa de outro tenant qualquer'])->assertSessionHas('error', $notFound);
        $this->get(route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'pdf']))->assertNotFound();

        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->refresh()->status);

        Http::assertNothingSent();
    }

    public function test_fiscal_settings_page_never_exposes_secrets(): void
    {
        $this->fiscalSetting(['nfce_csc' => 'csc-super-secreto']);
        $this->withoutVite();

        $this->get(route('app.fiscal-settings.show'))
            ->assertOk()
            ->assertDontSee('company-key-secret')
            ->assertDontSee('csc-super-secreto')
            ->assertInertia(fn ($page) => $page
                ->component('app/fiscal-settings/index')
                ->where('setting.nfce_csc_set', true)
                ->where('blockers.nfe', null));
    }

    public function test_updating_settings_keeps_csc_when_blank_and_resyncs_registered_issuer(): void
    {
        $setting = $this->fiscalSetting(['nfce_csc' => 'csc-atual']);

        Http::fake([
            self::SANDBOX.'/companies/company-uuid' => Http::response(['id' => 'company-uuid']),
            self::SANDBOX.'/companies/company-uuid/settings' => Http::response([]),
        ]);

        $this->put(route('app.fiscal-settings.update'), [
            'company_tax_regime' => '1',
            'emission_environment' => 'homologation',
            'nfe_enabled' => true,
            'nfce_enabled' => true,
            'nfse_enabled' => false,
            'nfce_csc_id' => '1',
            'nfce_csc' => '',
            'default_nfce_series' => '2',
        ])->assertSessionHas('success');

        $this->assertSame('csc-atual', $setting->refresh()->nfce_csc);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/settings')
            && $request['consumerInvoice']['csc'] === 'csc-atual'
            && $request['consumerInvoice']['series'] === '2');
    }

    public function test_emission_requires_explicit_tax_confirmation_and_tax_changes_reset_it(): void
    {
        $setting = $this->fiscalSetting(['tax_settings_confirmed_at' => null]);
        $service = app(NativeFiscalService::class);

        $this->assertStringContainsString('validados pela contabilidade', $service->blocker($this->tenant->id, SpedyClient::MODEL_NFE));

        Http::fake([
            self::SANDBOX.'/companies/company-uuid' => Http::response(['id' => 'company-uuid']),
            self::SANDBOX.'/companies/company-uuid/settings' => Http::response([]),
        ]);

        $payload = ['company_tax_regime' => '1', 'emission_environment' => 'homologation', 'nfe_enabled' => true, 'nfce_enabled' => false, 'nfse_enabled' => true,
            'default_commercial_unit' => 'UN', 'default_icms_origin' => '0', 'default_icms_situation' => '102', 'default_pis_situation' => '49', 'default_cofins_situation' => '49',
            'nfse_taxation_type' => 'taxationInMunicipality'];

        $this->put(route('app.fiscal-settings.update'), [...$payload, 'tax_settings_confirmed' => true])->assertSessionHas('success');
        $this->assertNotNull($setting->refresh()->tax_settings_confirmed_at);
        $this->assertSame($this->user->id, $setting->tax_settings_confirmed_by);
        $this->assertNull($service->blocker($this->tenant->id, SpedyClient::MODEL_NFE));

        // Mesmos dados sem marcar a confirmação: mantém a confirmação.
        $this->put(route('app.fiscal-settings.update'), $payload)->assertSessionHas('success');
        $this->assertNotNull($setting->refresh()->tax_settings_confirmed_at);

        // Alterar um dado tributário sem reconfirmar: volta a bloquear.
        $this->put(route('app.fiscal-settings.update'), [...$payload, 'default_icms_situation' => '103'])->assertSessionHas('success');
        $this->assertNull($setting->refresh()->tax_settings_confirmed_at);
        $this->assertNotNull($service->blocker($this->tenant->id, SpedyClient::MODEL_NFE));
    }

    public function test_payload_never_presumes_cfop_or_tax_codes(): void
    {
        $setting = $this->fiscalSetting(['default_icms_situation' => '', 'default_pis_situation' => '', 'default_commercial_unit' => '']);
        $sale = $this->sale();
        Part::query()->update(['cfop' => null]);

        try {
            app(SpedyPayloadBuilder::class)->productInvoice($sale->fresh(), Company::query()->first(), $setting, 'x');
            $this->fail('Esperava FiscalValidationException.');
        } catch (FiscalValidationException $exception) {
            $this->assertContains('Informe o CFOP (4 dígitos) da peça/produto "Tela Galaxy".', $exception->problems);
            $this->assertContains('Informe a CSOSN nas configurações fiscais.', $exception->problems);
            $this->assertContains('Informe a CST do PIS nas configurações fiscais.', $exception->problems);
            $this->assertContains('Informe a unidade comercial nas configurações fiscais.', $exception->problems);
        }

        Http::assertNothingSent();
    }

    public function test_regime_normal_with_taxed_icms_cst_is_blocked(): void
    {
        $setting = $this->fiscalSetting(['company_tax_regime' => '3', 'default_icms_situation' => '00', 'default_pis_situation' => '01']);

        try {
            app(SpedyPayloadBuilder::class)->consumerInvoice($this->sale(), $setting, 'x');
            $this->fail('Esperava FiscalValidationException.');
        } catch (FiscalValidationException $exception) {
            $this->assertStringContainsString('CST de ICMS 00 exige base e alíquota', $exception->getMessage());
            $this->assertStringContainsString('PIS/COFINS com alíquota', $exception->getMessage());
        }

        $payload = app(SpedyPayloadBuilder::class)->consumerInvoice($this->sale(), $this->fiscalSetting(['company_tax_regime' => '3', 'default_icms_situation' => '41', 'default_pis_situation' => '07', 'default_cofins_situation' => '07']), 'y');
        $this->assertSame(['origin' => 0, 'cst' => 41], $payload['items'][0]['taxes']['icms']);
    }

    public function test_sale_discount_is_distributed_and_surcharge_is_blocked(): void
    {
        $setting = $this->fiscalSetting();
        $sale = $this->sale();
        $second = Part::factory()->forTenant($this->tenant->id)->create(['name' => 'Capa', 'quantity' => 5]);
        SaleItem::query()->create(['sale_id' => $sale->id, 'part_id' => $second->id, 'quantity' => 1, 'unit_price' => 50]);
        // Itens: 2 x 125 + 1 x 50 = 300; venda fechada por 270 (desconto de 30).
        $sale->update(['total_amount' => 270, 'paid_amount' => 270]);

        $payload = app(SpedyPayloadBuilder::class)->consumerInvoice($sale->fresh(), $setting, 'x');

        $this->assertSame(25.0, $payload['items'][0]['discountAmount']);
        $this->assertSame(5.0, $payload['items'][1]['discountAmount']);
        $this->assertSame(270.0, $payload['payments'][0]['amount']);

        $sale->update(['total_amount' => 310]);
        $this->expectException(FiscalValidationException::class);
        app(SpedyPayloadBuilder::class)->consumerInvoice($sale->fresh(), $setting, 'y');
    }

    public function test_authorized_files_are_stored_with_hash_and_served_from_local_copy(): void
    {
        $this->fiscalSetting();
        $sale = $this->sale();

        Http::fake([self::SANDBOX.'/product-invoices' => Http::response($this->invoice('invoice-1', 'authorized'))]);

        $document = app(NativeFiscalService::class)->emitForSale($sale, SpedyClient::MODEL_NFE, $this->user->id)->refresh();

        $this->assertNotNull($document->xml_path);
        Storage::disk('fiscal')->assertExists($document->xml_path);
        Storage::disk('fiscal')->assertExists($document->pdf_path);
        $this->assertSame(hash('sha256', '<nfeProc>xml</nfeProc>'), $document->xml_sha256);
        $this->assertStringStartsWith($this->tenant->id.'/nfe/', $document->xml_path);

        Storage::disk('fiscal')->put($document->xml_path, '<nfeProc>local</nfeProc>');
        $this->get(route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'xml']))
            ->assertOk()
            ->assertSee('<nfeProc>local</nfeProc>', false);
    }

    public function test_sync_releases_unconfirmed_submission_after_thirty_minutes_despite_queue_rotation(): void
    {
        $this->fiscalSetting();
        $document = $this->nativeDocument(FiscalDocument::STATUS_PROCESSING, 'placeholder');
        $document->forceFill(['provider_reference' => null, 'submitted_at' => now()->subMinutes(40)])->save();
        FiscalDocument::query()->whereKey($document->id)->update(['updated_at' => now()->subMinutes(3)]);

        Http::fake([self::SANDBOX.'/product-invoices?*' => Http::response(['totalCount' => 0, 'items' => []])]);

        $this->artisan('fiscal:sync-spedy')->assertSuccessful();

        $this->assertSame(FiscalDocument::STATUS_FAILED, $document->refresh()->status);
        $this->assertStringContainsString('não confirmado', $document->error_message);
    }

    public function test_webhook_ignores_event_whose_company_cnpj_differs_from_tenant(): void
    {
        $this->fiscalSetting();
        $document = $this->nativeDocument(FiscalDocument::STATUS_PROCESSING, 'invoice-1');

        $body = json_encode(['id' => 'evt-x', 'event' => 'invoice.status_changed', 'data' => [
            ...$this->invoice('invoice-1', 'authorized'),
            'company' => ['federalTaxNumber' => '99888777000166'],
        ]]);
        $this->signedWebhook('evt-x', $body)->assertOk()->assertJson(['ignored' => true]);
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $document->refresh()->status);

        $body = json_encode(['id' => 'evt-y', 'event' => 'invoice.status_changed', 'data' => [
            ...$this->invoice('invoice-1', 'authorized'),
            'company' => ['federalTaxNumber' => '11222333000181'],
        ]]);
        $this->signedWebhook('evt-y', $body)->assertOk()->assertJson(['processed' => true]);
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->refresh()->status);
    }

    public function test_sale_and_order_with_native_invoice_cannot_be_cancelled_or_deleted(): void
    {
        $this->fiscalSetting();
        $document = $this->nativeDocument(FiscalDocument::STATUS_AUTHORIZED, 'invoice-1');
        $sale = Sale::query()->findOrFail($document->documentable_id);

        $this->post(route('app.sales.cancel', $sale), ['cancel_reason' => 'Cliente desistiu da compra'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Cancele a nota'));
        $this->assertNotSame('cancelled', $sale->refresh()->status);

        $document->update(['status' => FiscalDocument::STATUS_CANCELLED]);
        $sale->update(['status' => 'cancelled']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('preservar o histórico fiscal');
        app(SaleService::class)->delete($sale, $this->user);
    }

    public function test_order_with_native_invoice_cannot_be_deleted(): void
    {
        $this->fiscalSetting();
        $order = $this->order();
        $order->update(['service_status' => OrderStatus::OPEN]);
        FiscalDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'documentable_type' => Order::class,
            'documentable_id' => $order->id,
            'type' => SpedyClient::MODEL_NFSE,
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'provider_reference' => 'nfse-1',
            'integration_id' => 'integration-nfse-1',
            'status' => FiscalDocument::STATUS_CANCELLED,
        ]);

        $this->delete(route('app.orders.destroy', $order))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'histórico fiscal'));
        $this->assertNotNull(Order::query()->find($order->id));
    }

    /** VETOR-ROOT-FISCAL-02.1: limites comprovados viram validação explícita, sem corte silencioso. */
    public function test_nfe_receiver_name_above_sefaz_limit_blocks_instead_of_truncating(): void
    {
        $setting = $this->fiscalSetting();
        $sale = $this->sale(['name' => str_repeat('C', SpedyPayloadBuilder::MAX_NFE_RECEIVER_NAME + 1)]);

        try {
            app(SpedyPayloadBuilder::class)->productInvoice($sale, Company::query()->first(), $setting, 'x');
            $this->fail('Esperava FiscalValidationException.');
        } catch (FiscalValidationException $exception) {
            $this->assertStringContainsString('aceita até 60', $exception->getMessage());
        }

        $sale->customer->update(['name' => str_repeat('C', SpedyPayloadBuilder::MAX_NFE_RECEIVER_NAME)]);
        $payload = app(SpedyPayloadBuilder::class)->productInvoice($sale->refresh(), Company::query()->first(), $setting, 'y');
        $this->assertSame(str_repeat('C', SpedyPayloadBuilder::MAX_NFE_RECEIVER_NAME), $payload['receiver']['name']);
    }

    public function test_item_description_above_nfe_limit_blocks_instead_of_truncating(): void
    {
        $setting = $this->fiscalSetting();
        $sale = $this->sale();
        $sale->items->first()->part->update(['name' => str_repeat('P', SpedyPayloadBuilder::MAX_NFE_ITEM_DESCRIPTION + 1)]);

        foreach (['productInvoice', 'consumerInvoice'] as $method) {
            $sale->refresh();
            $arguments = $method === 'productInvoice' ? [$sale, Company::query()->first(), $setting, 'x'] : [$sale, $setting, 'x'];

            try {
                app(SpedyPayloadBuilder::class)->{$method}(...$arguments);
                $this->fail("Esperava FiscalValidationException em {$method}.");
            } catch (FiscalValidationException $exception) {
                $this->assertStringContainsString('passa de 120 caracteres', $exception->getMessage());
            }
        }
    }

    public function test_nfse_receiver_name_is_sent_whole(): void
    {
        $setting = $this->fiscalSetting();
        $order = $this->order();
        $longName = 'Cliente com Nome Empresarial Bastante Longo para o Tomador da Nota de Servico LTDA';
        $order->customer->update(['name' => $longName]);

        $payload = app(SpedyPayloadBuilder::class)->serviceInvoice($order->refresh(), $setting, 'z');

        $this->assertGreaterThan(SpedyPayloadBuilder::MAX_NFE_RECEIVER_NAME, mb_strlen($longName));
        $this->assertSame($longName, $payload['receiver']['name']);
    }

    public function test_issuer_trade_name_and_email_above_contract_limit_block_registration_payload(): void
    {
        $setting = $this->fiscalSetting();
        $company = Company::query()->first();
        $company->forceFill(['shortname' => str_repeat('F', SpedyPayloadBuilder::MAX_ISSUER_NAME + 1), 'email' => str_repeat('e', 75).'@x.com']);

        try {
            app(SpedyPayloadBuilder::class)->company($company, $this->tenant, $setting);
            $this->fail('Esperava FiscalValidationException.');
        } catch (FiscalValidationException $exception) {
            $this->assertStringContainsString('nome curto da empresa', $exception->getMessage());
            $this->assertStringContainsString('e-mail da empresa', $exception->getMessage());
        }
    }

    private function fiscalSetting(array $overrides = []): FiscalSetting
    {
        return FiscalSetting::query()->updateOrCreate(['tenant_id' => $this->tenant->id], [
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
            'nfse_enabled' => false,
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
            // Liberações do RootAdmin (FISCAL-SPEDY-04).
            'nfe_allowed' => true,
            'nfce_allowed' => true,
            'nfse_allowed' => true,
            ...$overrides,
        ]);
    }

    private function sale(array $customer = []): Sale
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'cpfcnpj' => '529.982.247-25',
            'state' => 'RS',
            'zipcode' => '90010-000',
            'number' => '50',
            ...$customer,
        ]);
        $part = Part::factory()->forTenant($this->tenant->id)->create(['name' => 'Tela Galaxy', 'quantity' => 10]);

        $sale = Sale::query()->create([
            'tenant_id' => $this->tenant->id,
            'sales_number' => 1,
            'customer_id' => $customer->id,
            'total_amount' => 250,
            'paid_amount' => 250,
            'financial_status' => 'paid',
            'payment_method' => 'pix',
            'status' => 'completed',
        ]);
        SaleItem::query()->create(['sale_id' => $sale->id, 'part_id' => $part->id, 'quantity' => 2, 'unit_price' => 125]);

        return $sale;
    }

    private function order(): Order
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['cpfcnpj' => '529.982.247-25']);
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_value' => 999,
            'services_performed' => null,
        ]);
        OrderItem::query()->create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => OrderItem::TYPE_SERVICE,
            'description' => 'Troca de tela',
            'quantity' => 1,
            'unit_price' => 180,
            'total_price' => 180,
        ]);

        return $order;
    }

    private function nativeDocument(string $status, string $reference): FiscalDocument
    {
        $sale = $this->sale();

        return FiscalDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'documentable_type' => Sale::class,
            'documentable_id' => $sale->id,
            'type' => SpedyClient::MODEL_NFE,
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'provider_reference' => $reference,
            'integration_id' => 'integration-'.$reference,
            'status' => $status,
            'number' => '1234',
            'access_key' => str_repeat('4', 44),
        ]);
    }

    private function invoice(string $id, string $status, array $extra = []): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'model' => 'productInvoice',
            'environmentType' => 'development',
            'number' => 1234,
            'series' => '1',
            'accessKey' => str_repeat('4', 44),
            'authorization' => $status === 'authorized' ? ['date' => '2026-10-06T10:00:00', 'protocol' => 'PROT-1'] : null,
            'processingDetail' => ['status' => 'success', 'message' => null, 'code' => null],
            ...$extra,
        ];
    }

    private function signedWebhook(string $eventId, string $body)
    {
        $timestamp = time();

        return $this->call('POST', route('webhook.spedy'), [], [], [], $this->webhookServer($eventId, $timestamp, $this->sign($eventId, $timestamp, $body)), $body);
    }

    private function sign(string $eventId, int $timestamp, string $body): string
    {
        $key = base64_decode(substr(self::WEBHOOK_SECRET, 6));

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$eventId}.{$timestamp}.{$body}", $key, true));
    }

    private function webhookServer(string $eventId, int $timestamp, string $signature): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_WEBHOOK_ID' => $eventId,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
        ];
    }
}
