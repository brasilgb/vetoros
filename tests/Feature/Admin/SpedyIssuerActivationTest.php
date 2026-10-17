<?php

namespace Tests\Feature\Admin;

use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\Spedy\SpedyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** VETOR-FISCAL-01: cadastro automático do emitente na Spedy e liberação do certificado A1. */
class SpedyIssuerActivationTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'https://sandbox-api.spedy.com.br/v1';

    private const PRODUCTION = 'https://api.spedy.com.br/v1';

    private User $root;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->withoutVite();

        config([
            'services.spedy.environment' => 'sandbox',
            'services.spedy.owner_api_key' => 'env-owner-key',
            'services.spedy.technical_responsible' => [],
        ]);

        // O tenant vem antes do root: a UserFactory cria um tenant aleatório quando não há nenhum,
        // e esse segundo tenant podia ocupar a primeira linha da listagem ordenada por empresa.
        $this->tenant = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true, 'cnpj' => '11.222.333/0001-81', 'company' => 'Cliente LTDA',
            'street' => 'Rua A', 'number' => '1', 'district' => 'Centro', 'zip_code' => '90000000', 'city' => 'Porto Alegre', 'state' => 'RS']);
        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
    }

    private function setting(array $attributes = []): FiscalSetting
    {
        return FiscalSetting::query()->withoutGlobalScopes()->updateOrCreate(['tenant_id' => $this->tenant->id], [
            'company_tax_regime' => '1',
            ...$attributes,
        ]);
    }

    private function fresh(): FiscalSetting
    {
        return FiscalSetting::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
    }

    private function register()
    {
        return $this->actingAs($this->root)->post(route('admin.fiscal.companies.register', $this->tenant));
    }

    public function test_registration_links_issuer_to_current_environment_and_releases_certificate_field(): void
    {
        $this->setting();
        Http::fake([
            self::SANDBOX.'/companies?*' => Http::response(['items' => [['id' => 'other', 'federalTaxNumber' => '99888777000166']]]),
            self::SANDBOX.'/companies' => Http::response(['id' => 'co-1', 'apiCredentials' => ['apiKey' => 'co-1-key']]),
            self::SANDBOX.'/companies/co-1/settings' => Http::response([]),
        ]);

        $this->register()->assertSessionHas('success', 'Empresa cadastrada na Spedy.');

        $setting = $this->fresh();
        $this->assertTrue($setting->isRegisteredOnSpedy());
        $this->assertSame('sandbox', $setting->spedy_environment);
        $this->assertNotNull($setting->last_synced_at);
        $this->assertNull($setting->registration_error);

        $admin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('app.fiscal-settings.show'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('certificateUploadAllowed', true)
                ->where('setting.registered_on_spedy', true)
                ->where('setting.environment_mismatch', false)
                ->missing('setting.api_token'));

        $this->actingAs($this->root)->get(route('admin.fiscal.companies.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('platformEnvironment', 'sandbox')
                ->where('tenants.data.0.registered_on_spedy', true)
                ->where('tenants.data.0.spedy_company_id', 'co-1')
                ->where('tenants.data.0.spedy_environment', 'sandbox')
                ->missing('tenants.data.0.api_token'));
    }

    public function test_incomplete_company_data_is_recorded_instead_of_staying_silently_pending(): void
    {
        $this->setting(['company_tax_regime' => null]);
        $this->tenant->update(['street' => null]);
        Http::fake();

        $this->register()->assertSessionHas('error');

        $setting = $this->fresh();
        $this->assertSame(FiscalSetting::REGISTRATION_ERROR, $setting->registration_status);
        $this->assertStringContainsString('regime tributário', $setting->registration_error);
        $this->assertStringContainsString('logradouro', $setting->registration_error);
        Http::assertNothingSent();

        $admin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('app.fiscal-settings.show'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('certificateUploadAllowed', false)
                ->where('setting.registration_status', 'error'));
    }

    public function test_cnpj_already_in_spedy_account_is_reported_without_creating_duplicate(): void
    {
        $this->setting();
        Http::fake([
            self::SANDBOX.'/companies?*' => Http::response(['items' => [['id' => 'orphan-co', 'federalTaxNumber' => '11222333000181']]]),
        ]);

        $this->register()->assertSessionHas('error');

        $setting = $this->fresh();
        $this->assertNull($setting->spedy_company_id);
        $this->assertSame(FiscalSetting::REGISTRATION_ERROR, $setting->registration_status);
        $this->assertStringContainsString('orphan-co', $setting->registration_error);
        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST');
    }

    public function test_timeout_on_creation_is_reported_and_retry_does_not_duplicate(): void
    {
        $this->setting();
        Http::fake([
            self::SANDBOX.'/companies?*' => Http::sequence()
                ->push(['items' => []])
                ->push(['items' => [['id' => 'created-before-timeout', 'federalTaxNumber' => '11222333000181']]]),
            self::SANDBOX.'/companies' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->register()->assertSessionHas('error');
        $this->assertSame(FiscalSetting::REGISTRATION_ERROR, $this->fresh()->registration_status);
        $this->assertStringContainsString('Não foi possível comunicar', $this->fresh()->registration_error);

        $this->register()->assertSessionHas('error');
        $this->assertStringContainsString('created-before-timeout', $this->fresh()->registration_error);
        // Só as duas consultas de CNPJ ficam registradas (o POST com timeout não gera resposta);
        // a segunda tentativa para na consulta e não cria outra empresa.
        Http::assertSentCount(2);
        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST');
    }

    public function test_sync_failure_keeps_existing_valid_link_and_records_last_error(): void
    {
        $this->setting(['spedy_company_id' => 'co-1', 'api_token' => 'co-1-key', 'spedy_environment' => 'sandbox',
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED]);
        Http::fake([self::SANDBOX.'/companies/co-1' => Http::response(['message' => 'indisponível'], 503)]);

        $this->register()->assertSessionHas('error');

        $setting = $this->fresh();
        $this->assertTrue($setting->isRegisteredOnSpedy());
        $this->assertStringContainsString('indisponível no momento', $setting->registration_error);
        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST');
    }

    public function test_link_from_production_account_is_not_used_nor_discarded_while_platform_is_in_sandbox(): void
    {
        $this->setting(['spedy_company_id' => 'prod-co', 'api_token' => 'prod-key', 'spedy_environment' => 'production',
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED, 'enabled' => true, 'nfe_allowed' => true, 'nfe_enabled' => true]);
        Http::fake();

        $setting = $this->fresh();
        $this->assertFalse($setting->isRegisteredOnSpedy());
        $this->assertStringContainsString('outro ambiente', $setting->nativeEmissionBlocker(SpedyClient::MODEL_NFE, true));

        $this->register()->assertSessionHas('error');
        $this->assertSame('prod-key', $this->fresh()->api_token);

        $admin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->withSession(['tenant_id' => $this->tenant->id])
            ->post(route('app.fiscal-settings.certificate'), [
                'certificate' => UploadedFile::fake()->createWithContent('a1.pfx', 'pkcs12'),
                'password' => 'secret',
                'confirm_replacement' => true,
            ])
            ->assertSessionHasErrors('certificate');

        Http::assertNothingSent();
    }

    public function test_sandbox_link_is_replaced_only_when_root_registers_in_production(): void
    {
        config(['services.spedy.environment' => 'production']);
        $this->setting(['spedy_company_id' => 'sandbox-co', 'api_token' => 'sandbox-key', 'spedy_environment' => 'sandbox',
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED, 'certificate_expires_at' => now()->addYear()]);
        Http::fake([
            self::PRODUCTION.'/companies?*' => Http::response(['items' => []]),
            self::PRODUCTION.'/companies' => Http::response(['id' => 'prod-co', 'apiCredentials' => ['apiKey' => 'prod-key']]),
            self::PRODUCTION.'/companies/prod-co/settings' => Http::response([]),
        ]);

        $this->assertFalse($this->fresh()->isRegisteredOnSpedy());

        $this->register()->assertSessionHas('success', 'Empresa cadastrada na Spedy.');

        $setting = $this->fresh();
        $this->assertSame('prod-co', $setting->spedy_company_id);
        $this->assertSame('production', $setting->spedy_environment);
        // O certificado do sandbox não é transportado para a conta de produção.
        $this->assertNull($setting->certificate_expires_at);
        Http::assertNotSent(fn (HttpRequest $request) => str_starts_with($request->url(), self::SANDBOX));
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::PRODUCTION.'/companies' && $request->hasHeader('X-Api-Key', 'env-owner-key'));
    }

    public function test_check_reports_issuer_not_found_without_dropping_the_link(): void
    {
        $this->setting(['spedy_company_id' => 'gone-co', 'api_token' => 'gone-key', 'spedy_environment' => 'sandbox',
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED]);
        Http::fake([self::SANDBOX.'/companies/gone-co' => Http::response([], 404)]);

        $this->actingAs($this->root)->post(route('admin.fiscal.companies.check', $this->tenant))->assertSessionHas('error');

        $setting = $this->fresh();
        $this->assertSame('gone-co', $setting->spedy_company_id);
        $this->assertStringContainsString('não encontrada', $setting->registration_error);
    }

    public function test_invalid_owner_key_has_clear_message(): void
    {
        $this->setting();
        Http::fake([self::SANDBOX.'/companies?*' => Http::response(['message' => 'unauthorized'], 401)]);

        $this->register()->assertSessionHas('error', 'Chave de API da Spedy inválida ou revogada. Confira a integração no RootAdmin.');
        $this->assertSame(FiscalSetting::REGISTRATION_ERROR, $this->fresh()->registration_status);
    }

    public function test_enabling_one_model_does_not_release_the_others(): void
    {
        $this->setting();

        $this->actingAs($this->root)->put(route('admin.fiscal.companies.update', $this->tenant), [
            'emission_enabled' => true, 'nfe_allowed' => false, 'nfce_allowed' => false, 'nfse_allowed' => true, 'production_released' => false,
        ])->assertSessionHas('success');

        $setting = $this->fresh();
        $this->assertTrue($setting->isModelAllowed(SpedyClient::MODEL_NFSE));
        $this->assertFalse($setting->isModelAllowed(SpedyClient::MODEL_NFE));
        $this->assertFalse($setting->isModelAllowed(SpedyClient::MODEL_NFCE));
        $this->assertNull($setting->production_released_at);
    }

    public function test_tenant_users_cannot_register_or_sync_the_issuer(): void
    {
        $this->setting();
        $admin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        Http::fake();

        $this->actingAs($admin)->post(route('admin.fiscal.companies.register', $this->tenant))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.fiscal.companies.check', $this->tenant))->assertForbidden();

        Http::assertNothingSent();
        $this->assertNull($this->fresh()->spedy_company_id);
    }
}
