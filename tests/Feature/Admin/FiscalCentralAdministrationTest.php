<?php

namespace Tests\Feature\Admin;

use App\Mail\SaasFiscalDocumentMail;
use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\Plan;
use App\Models\Admin\SpedyPlatformSetting;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyPlatformConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** FISCAL-SPEDY-04: administração fiscal central no RootAdmin e notas do SaaS. */
class FiscalCentralAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'https://sandbox-api.spedy.com.br/v1';

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('fiscal');
        $this->withoutVite();

        config([
            'services.spedy.environment' => 'sandbox',
            'services.spedy.owner_api_key' => 'env-owner-key',
            'services.spedy.webhook_secret' => null,
            'services.spedy.technical_responsible' => [],
        ]);

        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
    }

    public function test_only_root_admin_reaches_fiscal_administration(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $operator = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_OPERATOR]);

        foreach ([$tenantAdmin, $operator] as $user) {
            foreach (['admin.fiscal.integration', 'admin.fiscal.companies.index', 'admin.fiscal.monitoring', 'admin.fiscal.saas.index'] as $name) {
                $this->actingAs($user)->get(route($name))->assertForbidden();
            }
            $this->actingAs($user)->putJson(route('admin.fiscal.companies.update', $tenant), [
                'emission_enabled' => true, 'nfe_allowed' => true, 'nfce_allowed' => true, 'nfse_allowed' => true, 'production_released' => false,
            ])->assertForbidden();
        }

        $this->assertFalse((bool) $tenant->refresh()->automatic_fiscal_emission_enabled);

        auth()->logout();
        $this->get(route('admin.fiscal.integration'))->assertRedirect(route('login'));

        foreach (['admin.fiscal.integration', 'admin.fiscal.companies.index', 'admin.fiscal.monitoring', 'admin.fiscal.saas.index'] as $name) {
            $this->actingAs($this->root)->get(route($name))->assertOk();
        }
    }

    public function test_owner_key_is_write_only_takes_precedence_and_requires_password(): void
    {
        $this->actingAs($this->root)->put(route('admin.fiscal.integration.update'), [
            'environment' => 'sandbox', 'owner_api_key' => 'db-owner-key-1234567890', 'password' => 'errada',
        ])->assertSessionHasErrors('password');
        $this->assertSame('env-owner-key', SpedyPlatformConfig::ownerApiKey());

        $this->put(route('admin.fiscal.integration.update'), [
            'environment' => 'sandbox', 'owner_api_key' => 'db-owner-key-1234567890', 'password' => 'password',
        ])->assertSessionHas('success');

        $this->assertSame('db-owner-key-1234567890', SpedyPlatformConfig::ownerApiKey());
        $this->assertSame('database', SpedyPlatformConfig::source('owner_api_key'));
        $this->assertStringNotContainsString('db-owner-key', (string) DB::table('spedy_platform_settings')->value('owner_api_key'));

        $response = $this->get(route('admin.fiscal.integration'))->assertOk();
        $response->assertDontSee('db-owner-key-1234567890');
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('integration.owner_key_last4', '7890')
            ->where('integration.owner_key_source', 'database'));

        $audit = FiscalAdminAudit::query()->where('action', 'spedy.credentials_updated')->sole();
        $this->assertStringNotContainsString('db-owner-key', json_encode($audit->data));
    }

    public function test_environment_switch_resets_sandbox_registrations_and_production_rollback_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        FiscalSetting::query()->create(['tenant_id' => $tenant->id, 'spedy_company_id' => 'sandbox-co', 'api_token' => 'sandbox-key', 'registration_status' => 'registered']);

        $this->actingAs($this->root)->put(route('admin.fiscal.integration.update'), ['environment' => 'production', 'password' => 'password'])
            ->assertSessionHasErrors('confirm_environment_change');

        $this->put(route('admin.fiscal.integration.update'), ['environment' => 'production', 'password' => 'password', 'confirm_environment_change' => true])
            ->assertSessionHas('success');

        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNull($setting->spedy_company_id);
        $this->assertNull($setting->api_token);
        $this->assertSame('production', SpedyPlatformConfig::environment());

        $setting->forceFill(['spedy_company_id' => 'prod-co', 'api_token' => 'prod-key', 'registration_status' => 'registered'])->save();
        $this->put(route('admin.fiscal.integration.update'), ['environment' => 'sandbox', 'password' => 'password', 'confirm_environment_change' => true])
            ->assertSessionHasErrors('environment');
        $this->assertSame('prod-key', $setting->refresh()->api_token);
    }

    public function test_diagnostic_and_webhook_configuration_store_secret_without_exposing_it(): void
    {
        Http::fake([
            self::SANDBOX.'/companies?*' => Http::response(['items' => []]),
            self::SANDBOX.'/webhooks?*' => Http::response(['items' => []]),
            self::SANDBOX.'/webhooks' => Http::response(['id' => 'wh-1', 'event' => 'invoice.status_changed', 'url' => route('webhook.spedy'), 'enabled' => true]),
            self::SANDBOX.'/webhooks/secret' => Http::response(['secret' => 'whsec_c2VncmVkby1hZG1pbg==']),
        ]);

        $this->actingAs($this->root)->post(route('admin.fiscal.integration.diagnose'))->assertSessionHas('success');
        $this->assertTrue(SpedyPlatformSetting::current()->last_diagnostic_ok);

        $this->post(route('admin.fiscal.integration.webhook'), ['password' => 'password'])->assertSessionHas('success');

        $this->assertSame('whsec_c2VncmVkby1hZG1pbg==', SpedyPlatformConfig::webhookSecret());
        $this->get(route('admin.fiscal.integration'))->assertDontSee('c2VncmVkby1hZG1pbg');
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/webhooks')
            && $request['event'] === 'invoice.status_changed' && $request->hasHeader('X-Api-Key', 'env-owner-key'));
    }

    public function test_spedy_failures_do_not_leak_keys_into_responses_or_logs(): void
    {
        Log::spy();
        Http::fake([self::SANDBOX.'/companies?*' => Http::response(['message' => 'falha interna'], 500)]);

        $this->actingAs($this->root)->post(route('admin.fiscal.integration.diagnose'))
            ->assertSessionHas('error', fn (string $message) => ! str_contains($message, 'env-owner-key'));

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode($context), 'env-owner-key'));
        $this->assertFalse(SpedyPlatformSetting::current()->last_diagnostic_ok);
    }

    public function test_root_admin_release_and_block_take_effect_on_emission(): void
    {
        $tenant = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => false]);
        $setting = FiscalSetting::query()->create([
            'tenant_id' => $tenant->id, 'enabled' => true, 'nfe_enabled' => true, 'provider' => 'spedy',
            'spedy_company_id' => 'co-1', 'api_token' => 'key-1', 'registration_status' => 'registered',
            'certificate_expires_at' => now()->addYear(), 'tax_settings_confirmed_at' => now(), 'company_tax_regime' => '1',
        ]);
        $service = app(NativeFiscalService::class);

        $this->assertStringContainsString('não está liberada', $service->blocker($tenant->id, SpedyClient::MODEL_NFE));

        $payload = ['emission_enabled' => true, 'nfe_allowed' => false, 'nfce_allowed' => false, 'nfse_allowed' => false, 'production_released' => false];
        $this->actingAs($this->root)->put(route('admin.fiscal.companies.update', $tenant), $payload)->assertSessionHas('success');
        $this->assertStringContainsString('ainda não foi liberado', $service->blocker($tenant->id, SpedyClient::MODEL_NFE));

        $this->put(route('admin.fiscal.companies.update', $tenant), [...$payload, 'nfe_allowed' => true])->assertSessionHas('success');
        $this->assertNull($service->blocker($tenant->id, SpedyClient::MODEL_NFE));

        // Produção só com aprovação (e senha).
        $setting->update(['emission_environment' => 'production']);
        $this->assertStringContainsString('produção ainda não foi aprovada', $service->blocker($tenant->id, SpedyClient::MODEL_NFE));
        $this->put(route('admin.fiscal.companies.update', $tenant), [...$payload, 'nfe_allowed' => true, 'production_released' => true])->assertSessionHasErrors('password');
        $this->put(route('admin.fiscal.companies.update', $tenant), [...$payload, 'nfe_allowed' => true, 'production_released' => true, 'password' => 'password'])->assertSessionHas('success');
        $this->assertNull($service->blocker($tenant->id, SpedyClient::MODEL_NFE));

        $this->put(route('admin.fiscal.companies.update', $tenant), [...$payload, 'emission_enabled' => false, 'nfe_allowed' => true, 'production_released' => true])->assertSessionHas('success');
        $this->assertNotNull($service->blocker($tenant->id, SpedyClient::MODEL_NFE));
        $this->assertTrue(FiscalAdminAudit::query()->where('tenant_id', $tenant->id)->where('action', 'company.emission_blocked')->exists());
    }

    public function test_tenant_cannot_register_issuer_or_switch_to_production_by_itself(): void
    {
        $tenant = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true]);
        $user = User::factory()->forTenant($tenant->id)->create();
        FiscalSetting::query()->create(['tenant_id' => $tenant->id]);

        $this->assertFalse(Route::has('app.fiscal-settings.register'));

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->put(route('app.fiscal-settings.update'), ['emission_environment' => 'production'])
            ->assertSessionHasErrors('emission_environment');

        Http::assertNothingSent();
    }

    public function test_root_admin_registers_tenant_issuer_with_owner_key(): void
    {
        $tenant = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true, 'cnpj' => '11222333000181', 'company' => 'Cliente LTDA',
            'street' => 'Rua A', 'number' => '1', 'district' => 'Centro', 'zip_code' => '90000000', 'city' => 'Porto Alegre', 'state' => 'RS']);
        FiscalSetting::query()->create(['tenant_id' => $tenant->id, 'company_tax_regime' => '1']);

        Http::fake([
            self::SANDBOX.'/companies' => Http::response(['id' => 'co-new', 'apiCredentials' => ['apiKey' => 'co-new-key']]),
            self::SANDBOX.'/companies/co-new/settings' => Http::response([]),
        ]);

        $this->actingAs($this->root)->post(route('admin.fiscal.companies.register', $tenant))->assertSessionHas('success');

        $setting = FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('co-new', $setting->spedy_company_id);
        $this->assertTrue(FiscalAdminAudit::query()->where('action', 'company.registered')->where('tenant_id', $tenant->id)->exists());
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/companies' && $request->hasHeader('X-Api-Key', 'env-owner-key'));
    }

    public function test_monitoring_reads_only_database_and_filters_by_tenant(): void
    {
        [$a, $b] = [Tenant::factory()->create(['company' => 'Empresa A']), Tenant::factory()->create(['company' => 'Empresa B'])];
        foreach ([[$a, 'authorized'], [$a, 'rejected'], [$b, 'authorized']] as $i => [$tenant, $status]) {
            FiscalDocument::query()->create(['tenant_id' => $tenant->id, 'documentable_type' => 'x', 'documentable_id' => $i + 1, 'type' => 'nfe',
                'provider' => 'spedy', 'status' => $status, 'integration_id' => "int-{$i}"]);
        }

        $this->actingAs($this->root)->get(route('admin.fiscal.monitoring', ['tenant_id' => $a->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total', 2)
                ->where('summary.authorized', 1)
                ->where('summary.rejected', 1)
                ->where('byTenant.0.tenant', 'Empresa A'));

        Http::assertNothingSent();
    }

    public function test_saas_invoice_uses_paid_amount_platform_issuer_and_prevents_duplicates(): void
    {
        [$tenant, $payment] = $this->saasScenario();

        Http::fake([
            self::SANDBOX.'/service-invoices' => Http::response(['id' => 'saas-1', 'status' => 'authorized', 'number' => 77, 'authorization' => ['protocol' => 'P1', 'date' => '2026-10-07T10:00:00']]),
            self::SANDBOX.'/service-invoices/saas-1/xml' => Http::response('<nfse>saas</nfse>'),
            self::SANDBOX.'/service-invoices/saas-1/pdf' => Http::response('%PDF saas'),
        ]);

        $this->actingAs($this->root)->post(route('admin.fiscal.saas.emit', $payment), ['reference_start' => '2026-10-01', 'reference_end' => '2026-10-31'])
            ->assertSessionHas('success', 'NFS-e autorizada.');

        $document = AdminFiscalDocument::query()->sole();
        $this->assertSame('authorized', $document->status);
        $this->assertSame('77', $document->number);
        $this->assertSame(59.9, (float) $document->amount);
        $this->assertSame($payment->id, $document->payment_id);
        $this->assertSame($document->id, $payment->refresh()->admin_fiscal_document_id);
        $this->assertStringStartsWith('saas/', $document->xml_path);
        $this->assertSame(0, FiscalDocument::query()->withoutGlobalScopes()->count());

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/service-invoices'
            && $request->hasHeader('X-Api-Key', 'platform-issuer-key')
            && $request['total']['invoiceAmount'] === 59.9
            && $request['receiver']['federalTaxNumber'] === '44555666000177'
            && str_contains($request['description'], '01/10/2026 a 31/10/2026'));

        $this->post(route('admin.fiscal.saas.emit', $payment), ['reference_start' => '2026-10-01', 'reference_end' => '2026-10-31'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'já possui NFS-e autorizada'));
        $this->assertSame(1, AdminFiscalDocument::query()->count());
        Http::assertSentCount(3);
    }

    public function test_saas_invoice_is_never_issued_for_unapproved_payment_or_by_tenant_users(): void
    {
        [$tenant, $payment] = $this->saasScenario();
        $payment->update(['status' => 'pending']);

        $this->actingAs($this->root)->post(route('admin.fiscal.saas.emit', $payment), ['reference_start' => '2026-10-01', 'reference_end' => '2026-10-31'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'aprovados'));

        $client = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $payment->update(['status' => 'approved']);
        $this->actingAs($client)->post(route('admin.fiscal.saas.emit', $payment), ['reference_start' => '2026-10-01', 'reference_end' => '2026-10-31'])->assertForbidden();

        $this->assertSame(0, AdminFiscalDocument::query()->count());
        Http::assertNothingSent();
    }

    public function test_saas_communication_failure_keeps_processing_and_cancel_requires_password(): void
    {
        [, $payment] = $this->saasScenario();
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response(['message' => 'indisponível'], 503)]);

        $this->actingAs($this->root)->post(route('admin.fiscal.saas.emit', $payment), ['reference_start' => '2026-10-01', 'reference_end' => '2026-10-31'])
            ->assertSessionHas('success');
        $document = AdminFiscalDocument::query()->sole();
        $this->assertSame('processing', $document->status);
        $this->assertNull($document->provider_reference);

        $document->update(['status' => 'authorized', 'provider_reference' => 'saas-9']);
        $this->post(route('admin.fiscal.saas.cancel', $document), ['reason' => 'Cobrança lançada em duplicidade', 'password' => 'errada'])->assertSessionHasErrors('password');

        Http::fake([
            self::SANDBOX.'/service-invoices/saas-9' => Http::response(['id' => 'saas-9', 'status' => 'canceled', 'cancellation' => ['date' => '2026-10-08T09:00:00']]),
            self::SANDBOX.'/service-invoices/saas-9/*' => Http::response('arquivo do cancelamento'),
        ]);
        $this->post(route('admin.fiscal.saas.cancel', $document), ['reason' => 'Cobrança lançada em duplicidade', 'password' => 'password'])->assertSessionHas('success');
        $this->assertSame('cancelled', $document->refresh()->status);
    }

    public function test_email_delivery_is_recorded_and_failure_does_not_reissue(): void
    {
        [, $payment] = $this->saasScenario();
        $document = AdminFiscalDocument::query()->create([
            'tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id, 'type' => 'nfse', 'provider' => 'spedy', 'status' => 'authorized',
            'provider_reference' => 'saas-5', 'integration_id' => 'int-saas-5', 'amount' => 59.9, 'number' => '5',
        ]);
        Http::fake([self::SANDBOX.'/service-invoices/saas-5/pdf' => Http::response('%PDF nota')]);

        Mail::fake();
        $this->actingAs($this->root)->post(route('admin.fiscal.saas.send', $document), ['email' => 'cliente@example.test'])->assertSessionHas('success');
        Mail::assertSent(SaasFiscalDocumentMail::class, fn ($mail) => $mail->hasTo('cliente@example.test'));
        $this->assertDatabaseHas('admin_fiscal_document_deliveries', ['admin_fiscal_document_id' => $document->id, 'email' => 'cliente@example.test', 'status' => 'sent']);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP fora do ar'));
        $this->post(route('admin.fiscal.saas.send', $document), ['email' => 'cliente@example.test'])->assertSessionHas('error');
        $this->assertDatabaseHas('admin_fiscal_document_deliveries', ['admin_fiscal_document_id' => $document->id, 'status' => 'failed']);

        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST');
        $this->assertSame(1, AdminFiscalDocument::query()->count());
    }

    public function test_webhook_updates_saas_document_only_for_platform_cnpj(): void
    {
        [, $payment] = $this->saasScenario();
        SpedyPlatformSetting::current()->update(['webhook_secret' => 'whsec_dGVzdGUtc2Fhcw==']);
        $document = AdminFiscalDocument::query()->create([
            'tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id, 'type' => 'nfse', 'provider' => 'spedy', 'status' => 'processing',
            'provider_reference' => 'saas-7', 'integration_id' => 'int-saas-7', 'amount' => 59.9,
        ]);
        Http::fake(['*' => Http::response('arquivo')]);

        $send = function (string $id, string $cnpj) {
            $body = json_encode(['id' => $id, 'event' => 'invoice.status_changed', 'data' => ['id' => 'saas-7', 'status' => 'authorized', 'number' => 8, 'company' => ['federalTaxNumber' => $cnpj]]]);
            $ts = time();
            $sig = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", base64_decode('dGVzdGUtc2Fhcw=='), true));

            return $this->call('POST', route('webhook.spedy'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_ID' => $id, 'HTTP_WEBHOOK_TIMESTAMP' => (string) $ts, 'HTTP_WEBHOOK_SIGNATURE' => $sig], $body);
        };

        $send('evt-a', '99999999000199')->assertJson(['ignored' => true]);
        $this->assertSame('processing', $document->refresh()->status);

        $send('evt-b', '22333444000155')->assertJson(['processed' => true]);
        $this->assertSame('authorized', $document->refresh()->status);
    }

    /** @return array{0: Tenant, 1: Payment} */
    private function saasScenario(): array
    {
        AdminFiscalSetting::query()->create([
            'enabled' => true, 'provider' => 'spedy', 'legal_name' => 'ABrasil Sistemas LTDA', 'cnpj' => '22.333.444/0001-55',
            'municipal_registration' => '12345', 'tax_regime' => '1', 'zip_code' => '90000000', 'state' => 'RS', 'city' => 'Porto Alegre',
            'district' => 'Centro', 'street' => 'Rua B', 'number' => '2', 'service_city_code' => '4314902', 'service_list_item' => '01.05',
            'default_iss_rate' => 2, 'nfse_taxation_type' => 'taxationInMunicipality', 'spedy_company_id' => 'platform-co',
            'api_token' => 'platform-issuer-key', 'registration_status' => 'registered', 'certificate_expires_at' => now()->addYear(),
            'tax_settings_confirmed_at' => now(),
        ]);

        $plan = Plan::query()->create(['name' => 'Mensal', 'slug' => 'mensal-saas-test', 'value' => 89.9, 'billing_months' => 1, 'description' => 'x']);
        $tenant = Tenant::factory()->create(['company' => 'Cliente Contratante', 'cnpj' => '44.555.666/0001-77', 'email' => 'cliente@example.test']);
        // Valor efetivamente cobrado difere do preço de tabela do plano.
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id, 'gateway' => 'mercadopago', 'payment_id' => 'mp-1', 'idempotency_key' => 'idem-1', 'amount' => 59.9, 'status' => 'approved',
            'raw_response' => ['external_reference' => json_encode(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]), 'date_approved' => '2026-10-01T10:00:00'],
        ]);

        return [$tenant, $payment];
    }
}
