<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\EfiIntegrationTest;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\SaasPaymentSetting;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\Efi\EfiException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesEfi;
use Tests\TestCase;

/**
 * VETOROS-EFI-TEST-01: modal "Testar pagamento" da integração Efí, contra o simulador das APIs
 * (sem credencial real, sem cobrança real). Cada modalidade, webhook, isolamento e segurança.
 */
class EfiIntegrationTestTest extends TestCase
{
    use FakesEfi, RefreshDatabase;

    private User $root;

    private int $tenantsBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
        $this->configureEfi();
        // Testar a integração não depende de homologação concluída.
        SaasPaymentSetting::current()->forceFill(['efi_homologated_at' => null])->save();
        $this->tenantsBefore = Tenant::query()->count();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function create(array $payload)
    {
        return $this->actingAs($this->root)->postJson(route('admin.finance.efi.integration-tests.store'), $payload);
    }

    private function payer(): array
    {
        return ['name' => 'Teste Integracao VetorOS', 'cpf' => '529.982.247-25', 'email' => 'teste@vetoros.test', 'birth' => '1990-01-01', 'phone_number' => '51999999999'];
    }

    private function cardPayload(string $token = 'card-token-ok'): array
    {
        return [
            'method' => 'card', 'amount' => '5,00', 'payer' => $this->payer(),
            'billing' => ['street' => 'Rua Teste', 'number' => '100', 'neighborhood' => 'Centro', 'zipcode' => '90010-000', 'city' => 'Porto Alegre', 'state' => 'RS'],
            'card' => ['payment_token' => $token, 'card_mask' => 'XXXXXXXXXXXX1111'],
        ];
    }

    private function assertIsolated(): void
    {
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame($this->tenantsBefore, Tenant::query()->count());
        $this->assertSame(0, FiscalAdminAudit::query()->where('action', 'like', 'saas_payment.%')->count());
        $this->assertSame(0, FiscalAdminAudit::query()->where('action', 'like', 'commercial.%')->count());
    }

    public function test_pix_shows_qr_code_and_webhook_updates_status_automatically(): void
    {
        $response = $this->create(['method' => 'pix', 'amount' => '1,00'])->assertCreated()->json();

        $this->assertSame(['pix', 'homologation', '1.00', 'pending', 'integration_test'], [$response['method'], $response['environment'], $response['amount'], $response['status'], $response['source']]);
        $this->assertMatchesRegularExpression('/^VOT[A-Z0-9]{29}$/', $response['provider_id']);
        $this->assertSame('000201PIX'.$response['provider_id'], $response['pix_copy_paste']);
        $this->assertNotEmpty($response['pix_qr_image']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v2/cob/') && $request->method() === 'PUT'
            && $request['valor']['original'] === '1.00' && ! isset($request['devedor']));

        // Webhook: só confirma consultando a API; repetido não processa de novo.
        $this->payCob($response['provider_id'], '1.00');
        $this->pixWebhook($response['provider_id'])->assertOk()->assertJson(['queued' => 1]);
        $this->pixWebhook($response['provider_id'])->assertOk()->assertJson(['queued' => 0]);

        $shown = $this->actingAs($this->root)->getJson(route('admin.finance.efi.integration-tests.show', $response['id']))->assertOk()->json();
        $this->assertSame(['paid', 1], [$shown['status'], $shown['webhook_count']]);
        $this->assertNotNull($shown['webhook_received_at']);
        $this->assertIsolated();
    }

    public function test_pix_manual_refresh_and_webhook_without_payment_keeps_pending(): void
    {
        $id = $this->create(['method' => 'pix', 'amount' => '2.50', 'payer' => ['name' => 'Fulano Teste', 'cpf' => '52998224725']])->assertCreated()->json('id');
        $test = EfiIntegrationTest::query()->findOrFail($id);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT' && ($request['devedor']['cpf'] ?? null) === '52998224725');

        $this->pixWebhook($test->provider_id)->assertOk();
        $this->assertSame('pending', $test->refresh()->status);

        $this->payCob($test->provider_id, '2.50');
        $this->actingAs($this->root)->postJson(route('admin.finance.efi.integration-tests.refresh', $id))->assertOk()->assertJson(['status' => 'paid']);
        $this->assertIsolated();
    }

    public function test_billet_shows_due_date_line_and_link_and_charges_notification_is_recognized(): void
    {
        $response = $this->create(['method' => 'billet', 'amount' => '5,00', 'payer' => $this->payer()])->assertCreated()->json();

        $this->assertSame(['billet', '5.00', 'pending', '2026-10-23'], [$response['method'], $response['amount'], $response['status'], $response['due_date']]);
        $this->assertSame('34191.79001 01043.510047', $response['barcode']);
        $this->assertStringStartsWith('https://boleto.test/', $response['billet_link']);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/charge/one-step')
            && $request['items'][0]['value'] === 500 && $request['payment']['banking_billet']['customer']['cpf'] === '52998224725'
            && str_contains($request['metadata']['notification_url'], '/api/webhooks/efi-charges/'));

        $this->efiCharges[$response['provider_id']]['status'] = 'paid';
        $this->chargesNotification('tok-test-1', [['identifiers' => ['charge_id' => (int) $response['provider_id']], 'status' => ['current' => 'paid']]])->assertOk();

        $test = EfiIntegrationTest::query()->findOrFail($response['id']);
        $this->assertSame(['paid', 1], [$test->status, $test->webhook_count]);
        $this->assertIsolated();
    }

    public function test_billet_respects_api_minimum(): void
    {
        $this->create(['method' => 'billet', 'amount' => '1,00', 'payer' => $this->payer()])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(0, EfiIntegrationTest::query()->count());
    }

    public function test_card_authorization_and_card_data_never_stored(): void
    {
        $response = $this->create($this->cardPayload())->assertCreated()->json();

        $this->assertSame(['card', 'paid', 'XXXXXXXXXXXX1111'], [$response['method'], $response['status'], $response['card_mask']]);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/charge/one-step')
            && $request['payment']['credit_card']['payment_token'] === 'card-token-ok');

        $stored = json_encode(EfiIntegrationTest::query()->findOrFail($response['id'])->getAttributes());
        $this->assertStringNotContainsString('card-token-ok', $stored);
        $this->assertIsolated();
    }

    public function test_card_refusal_is_shown(): void
    {
        $this->create($this->cardPayload('refused-token'))->assertCreated()->assertJson(['status' => 'failed', 'provider_status' => 'unpaid']);
        $this->assertIsolated();
    }

    public function test_api_error_is_recorded_without_credentials(): void
    {
        $this->pixDown = true;

        $response = $this->create(['method' => 'pix', 'amount' => '1,00'])->assertStatus(502)->json();

        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('HTTP 503', $response['error']);
        $this->assertStringNotContainsString('Client_Secret', json_encode($response));
        $audit = FiscalAdminAudit::query()->where('action', 'efi.integration_test_created')->sole();
        $this->assertSame(['pix', 'homologation', 'error'], [$audit->data['method'], $audit->data['environment'], $audit->data['status']]);
        $this->assertStringNotContainsString('Client_', json_encode($audit->data));
    }

    public function test_production_requires_explicit_confirmation_and_password(): void
    {
        SaasPaymentSetting::current()->forceFill(['efi_environment' => 'production'])->save();
        $before = count(Http::recorded());

        $this->create(['method' => 'pix', 'amount' => '1,00'])->assertUnprocessable()->assertJsonValidationErrors(['confirm_production', 'password']);
        $this->create(['method' => 'pix', 'amount' => '1,00', 'confirm_production' => true, 'password' => 'errada'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($before, count(Http::recorded()));
        $this->assertSame(0, EfiIntegrationTest::query()->count());

        // Com confirmação: usa a URL de produção; o ambiente configurado não é alterado.
        Http::fake(['https://pix.api.efipay.com.br/*' => Http::response(['access_token' => 't', 'expires_in' => 3600, 'status' => 'ATIVA', 'pixCopiaECola' => '000201'])]);
        $this->create(['method' => 'pix', 'amount' => '1,00', 'confirm_production' => true, 'password' => 'password'])->assertCreated()->assertJson(['environment' => 'production']);
        $this->assertSame('production', SaasPaymentSetting::current()->efi_environment);
        $this->assertNull(SaasPaymentSetting::current()->efi_homologated_at);
    }

    public function test_only_root_admin_and_method_needs_credentials(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantUser = User::factory()->forTenant($tenant->id)->create();
        $this->actingAs($tenantUser)->postJson(route('admin.finance.efi.integration-tests.store'), ['method' => 'pix', 'amount' => '1'])->assertForbidden();

        SaasPaymentSetting::current()->forceFill(['efi_payee_code' => null])->save();
        $this->create($this->cardPayload())->assertUnprocessable()->assertJsonValidationErrors('method');
    }

    public function test_settings_page_lists_recent_tests_without_calling_efi(): void
    {
        $this->create(['method' => 'pix', 'amount' => '1,00'])->assertCreated();
        $calls = count(Http::recorded());

        $this->actingAs($this->root)->get(route('admin.finance.efi'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/finance/efi')
            ->where('integration_test.methods', ['pix', 'billet', 'card'])
            ->where('integration_test.recent.0.method', 'pix')
            ->where('integration_test.recent.0.created_by', $this->root->name)
            ->missing('integration_test.recent.0.raw_response'));

        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_invalid_identifier_error_identifies_endpoint_without_echoing_provider_data(): void
    {
        $response = new HttpResponse(new PsrResponse(400, ['Content-Type' => 'application/json'], json_encode([
            'nome' => 'identificador_invalido', 'mensagem' => 'Client_Secret_sensitive',
        ])));

        $billet = EfiException::fromResponse('emitir boleto', $response);
        $card = EfiException::fromResponse('cobrar cartão', $response);

        $this->assertStringContainsString('identificador_invalido', $billet->getMessage());
        $this->assertStringContainsString('API de Emissão', $billet->getMessage());
        $this->assertStringContainsString('tokenização do cartão', $card->getMessage());
        $this->assertStringNotContainsString('Client_Secret_sensitive', $billet->getMessage().$card->getMessage());
    }

    public function test_settings_checks_distinguish_missing_card_identifier_from_billet_and_pix(): void
    {
        SaasPaymentSetting::current()->forceFill(['efi_payee_code' => null, 'last_connection_test_at' => null])->save();

        $this->actingAs($this->root)->get(route('admin.finance.efi'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/finance/efi')
            ->where('settings.checks.authentication', 'API Cobranças: autenticação ainda não testada neste ambiente.')
            ->where('settings.checks.card', 'Cartão: informe o Identificador de conta em API > Introdução na Efí.')
            ->where('settings.checks.billet', 'Boleto: teste a conexão da API Cobranças e confirme a habilitação da API de Emissão.')
            ->where('settings.checks.pix', 'Pix: teste a conexão da API Pix.'));
    }
}
