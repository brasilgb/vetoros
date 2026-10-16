<?php

namespace Tests\Feature\Admin;

use App\Mail\SaasChargeInvoiceMail;
use App\Mail\SaasFiscalNoticeMail;
use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\Admin\TenantCommercialContract;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commercial\SaasContractService;
use App\Services\Fiscal\SaasInvoiceService;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** VETOR-SAAS-CONTRATO-01: contrato comercial personalizado por tenant. */
class SaasCommercialContractTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'https://sandbox-api.spedy.com.br/v1';

    private User $root;

    private Plan $plan;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('fiscal');
        Mail::fake();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-15 10:00:00');

        config([
            'services.spedy.environment' => 'sandbox',
            'services.spedy.owner_api_key' => 'env-owner-key',
            'services.spedy.webhook_secret' => null,
            'services.spedy.technical_responsible' => [],
            'services.mercadopago.token' => 'mp-test-token',
            'services.mercadopago.webhook_token' => 'expected-token',
        ]);

        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
        $this->plan = Plan::factory()->create(['name' => 'Mensal', 'slug' => 'mensal', 'value' => 89.90, 'billing_months' => 1]);
        Period::factory()->create(['plan_id' => $this->plan->id, 'name' => 'Mensal', 'interval' => 'month', 'interval_count' => 1, 'price' => 89.90]);
        $this->tenant = Tenant::factory()->create([
            'plan_id' => $this->plan->id, 'company' => 'Cliente Contratante LTDA', 'name' => 'Maria', 'cnpj' => '11.444.777/0001-61',
            'email' => 'cliente@example.test', 'street' => 'Rua A', 'number' => '1', 'district' => 'Centro', 'zip_code' => '90000000',
            'city' => 'Porto Alegre', 'state' => 'RS', 'expires_at' => null, 'subscription_status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- Cenários ----------------------------------------------------------

    private function issuer(): AdminFiscalSetting
    {
        return AdminFiscalSetting::query()->create([
            'enabled' => true, 'provider' => 'spedy', 'legal_name' => 'ABrasil Sistemas LTDA', 'cnpj' => '22.333.444/0001-55',
            'municipal_registration' => '12345', 'tax_regime' => '1', 'zip_code' => '90000000', 'state' => 'RS', 'city' => 'Porto Alegre',
            'district' => 'Centro', 'street' => 'Rua B', 'number' => '2', 'service_city_code' => '4314902', 'service_list_item' => '01.05',
            'default_iss_rate' => 2, 'nfse_taxation_type' => 'taxationInMunicipality', 'spedy_company_id' => 'platform-co',
            'api_token' => 'platform-issuer-key', 'registration_status' => 'registered', 'certificate_expires_at' => now()->addYear(),
            'tax_settings_confirmed_at' => now(),
        ]);
    }

    private function activate(array $overrides = [], ?Tenant $tenant = null)
    {
        $tenant ??= $this->tenant;

        return $this->actingAs($this->root)->put(route('admin.tenants.commercial.update', $tenant), [
            'custom_contract' => true,
            'saas_invoice_enabled' => false,
            'amount' => '1200.00',
            'billing_months' => 1,
            'start_date' => '2026-10-01',
            'next_due_date' => '2026-10-20',
            'sales_rep' => 'Carlos',
            'notes' => 'Negociado em reunião',
            ...$overrides,
        ]);
    }

    private function process(): void
    {
        $this->artisan('vetoros:process-saas-contracts')->assertSuccessful();
    }

    private function charges()
    {
        return TenantSubscriptionCharge::query()->where('tenant_id', $this->tenant->id)->orderBy('cycle_start')->get();
    }

    private function spedyCreates(string $status = 'authorized'): void
    {
        Http::fake([
            self::SANDBOX.'/service-invoices' => Http::response(['id' => 'saas-1', 'status' => $status, 'number' => 77, 'authorization' => ['protocol' => 'P1', 'date' => '2026-10-20T10:00:00']]),
            self::SANDBOX.'/service-invoices/saas-1/xml' => Http::response('<nfse>saas</nfse>'),
            self::SANDBOX.'/service-invoices/saas-1/pdf' => Http::response('%PDF saas'),
        ]);
    }

    private function enableFiscal(): void
    {
        $this->issuer();
        $this->actingAs($this->root)->put(route('admin.tenants.commercial.update', $this->tenant), [
            'custom_contract' => $this->tenant->refresh()->hasCustomContract(),
            'saas_invoice_enabled' => true,
            ...($this->tenant->hasCustomContract() ? [
                'amount' => (string) $this->tenant->commercialContract->amount,
                'billing_months' => $this->tenant->commercialContract->billing_months,
                'start_date' => $this->tenant->commercialContract->start_date->toDateString(),
                'next_due_date' => $this->tenant->commercialContract->next_due_date->toDateString(),
            ] : []),
        ])->assertSessionHasNoErrors();
    }

    private array $gatewayPayment = [];

    private bool $gatewayMocked = false;

    private function webhook(array $payment)
    {
        // A instância do controller fica guardada na rota: um único mock que devolve o pagamento atual.
        $this->gatewayPayment = $payment;
        if (! $this->gatewayMocked) {
            $service = Mockery::mock(MercadoPagoService::class);
            $service->shouldReceive('getPayment')->andReturnUsing(fn () => (object) $this->gatewayPayment);
            $this->app->instance(MercadoPagoService::class, $service);
            $this->gatewayMocked = true;
        }

        return $this->postJson(route('webhook.mercadopago', ['token' => 'expected-token']), ['type' => 'payment', 'data' => ['id' => $payment['id']]]);
    }

    // ---- 1. Contrato tradicional ------------------------------------------

    public function test_traditional_plan_tenant_is_unchanged(): void
    {
        $this->tenant->update(['expires_at' => '2026-11-15']);
        $this->process();

        $this->assertSame(Tenant::BILLING_PLAN, $this->tenant->refresh()->billing_mode);
        $this->assertSame(0, TenantSubscriptionCharge::query()->count());
        $this->assertSame('2026-11-15', $this->tenant->expires_at->toDateString());

        $this->actingAs($this->root)->get(route('admin.tenants.show', $this->tenant))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('commercial.billing_mode', 'plan')->where('commercial.contract', null));
    }

    // ---- 2-4. Periodicidades -----------------------------------------------

    /** @return array<string, array{int, string}> */
    public static function periodicities(): array
    {
        return ['mensal' => [1, '2026-11-19'], 'semestral' => [6, '2027-04-19'], 'anual' => [12, '2027-10-19']];
    }

    #[DataProvider('periodicities')]
    public function test_custom_contract_cycle_uses_total_amount_per_periodicity(int $months, string $cycleEnd): void
    {
        $this->activate(['billing_months' => $months, 'amount' => '1234.56'])->assertSessionHasNoErrors();

        $contract = $this->tenant->refresh()->commercialContract;
        $this->assertSame(Tenant::BILLING_CUSTOM, $this->tenant->billing_mode);
        $this->assertSame('VOS-'.str_pad((string) $this->tenant->id, 5, '0', STR_PAD_LEFT), $contract->contract_number);
        $this->assertSame('2026-10-20', $this->tenant->expires_at->toDateString());

        $this->process();

        $charge = $this->charges()->sole();
        // Valor total do ciclo, nunca o mensal multiplicado.
        $this->assertSame('1234.56', (string) $charge->amount);
        $this->assertSame('2026-10-20', $charge->due_date->toDateString());
        $this->assertSame($cycleEnd, $charge->cycle_end->toDateString());
        $this->assertSame(Carbon::parse($cycleEnd)->addDay()->toDateString(), $contract->refresh()->next_due_date->toDateString());
        Mail::assertSent(SaasChargeInvoiceMail::class, fn ($mail) => $mail->hasTo('cliente@example.test') && $mail->links === null);
    }

    public function test_amount_validation_rejects_invalid_values(): void
    {
        foreach (['0', '-10', '10.999', 'abc', '12345678901.00'] as $amount) {
            $this->activate(['amount' => $amount])->assertSessionHasErrors('amount');
        }
        $this->activate(['billing_months' => 3])->assertSessionHasErrors('billing_months');

        $this->assertNull($this->tenant->refresh()->commercialContract);
        $this->assertSame(Tenant::BILLING_PLAN, $this->tenant->billing_mode);
    }

    // ---- 5. Alteração de valor entre ciclos --------------------------------

    public function test_amount_change_applies_only_to_next_cycles(): void
    {
        $this->activate(['amount' => '100.00']);
        $this->process();

        $this->activate(['amount' => '150.00', 'next_due_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->assertSame('100.00', (string) $this->charges()->first()->amount);

        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->process();

        $this->assertSame(['100.00', '150.00'], $this->charges()->map(fn ($c) => (string) $c->amount)->all());

        $audit = FiscalAdminAudit::query()->where('action', 'commercial.contract_updated')->latest('id')->first();
        $this->assertSame('100.00', $audit->data['before']['amount']);
        $this->assertSame('150.00', $audit->data['after']['amount']);
        $this->assertSame($this->root->id, $audit->user_id);

        // Ajuste explícito de cobrança em aberto: auditado.
        $open = $this->charges()->last();
        $this->actingAs($this->root)->post(route('admin.tenants.charges.adjust', [$this->tenant, $open]), ['amount' => '140', 'reason' => 'Desconto negociado'])->assertSessionHasNoErrors();
        $this->assertSame('140.00', (string) $open->refresh()->amount);
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'commercial.charge_adjusted', 'subject_id' => $open->id]);
    }

    public function test_next_due_date_cannot_overlap_charged_cycle(): void
    {
        $this->activate();
        $this->process();

        $this->activate(['next_due_date' => '2026-11-01'])->assertSessionHasErrors('next_due_date');
        $this->assertSame('2026-11-20', $this->tenant->refresh()->commercialContract->next_due_date->toDateString());
    }

    // ---- 6-7. Vencimento, inadimplência, suspensão e reativação -------------

    public function test_overdue_charge_goes_to_grace_then_blocks_and_payment_reactivates(): void
    {
        $this->activate();
        $this->process();
        $user = User::factory()->forTenant($this->tenant->id)->create();

        Carbon::setTestNow('2026-10-22 10:00:00');
        $this->assertSame(Tenant::SUBSCRIPTION_GRACE, $this->tenant->refresh()->subscriptionBucket());

        Carbon::setTestNow('2026-10-24 10:00:00');
        $this->assertSame(Tenant::SUBSCRIPTION_BLOCKED, $this->tenant->refresh()->subscriptionBucket());
        $this->actingAs($user)->get(route('app.dashboard'))->assertRedirect(route('subscription.blocked'));
        $this->actingAs($user)->get(route('subscription.blocked'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Subscription/Blocked')->where('charge.amount', '1200.00')->where('plans', []));

        $charge = $this->charges()->sole();
        $this->actingAs($this->root)->post(route('admin.tenants.charges.pay', [$this->tenant, $charge]), ['amount' => '1100'])->assertSessionHasErrors('amount');
        $this->actingAs($this->root)->post(route('admin.tenants.charges.pay', [$this->tenant, $charge]), ['amount' => '1200,00', 'note' => 'TED'])->assertSessionHasNoErrors();

        $this->tenant->refresh();
        $this->assertSame('paid', $charge->refresh()->status);
        $this->assertSame('manual', $charge->payment_method);
        $this->assertSame('2026-11-20', $this->tenant->expires_at->toDateString());
        $this->assertSame(Tenant::SUBSCRIPTION_ACTIVE, $this->tenant->subscriptionBucket());
        $this->assertSame('active', $this->tenant->subscription_status);
    }

    // ---- 8. Idempotência ---------------------------------------------------

    public function test_repeated_processing_does_not_duplicate_charges_or_invoices(): void
    {
        $this->activate();
        $this->process();
        $this->process();
        app(SaasContractService::class)->generateCharge($this->tenant->commercialContract);

        $this->assertCount(1, $this->charges());
        Mail::assertSent(SaasChargeInvoiceMail::class, 1);
    }

    // ---- 9. Pagamento integrado (PIX) e manual -----------------------------

    public function test_pix_pays_open_charge_and_repeated_webhook_is_idempotent(): void
    {
        $this->activate();
        $this->process();
        $charge = $this->charges()->sole();
        $user = User::factory()->forTenant($this->tenant->id)->create();

        $service = Mockery::mock(MercadoPagoService::class);
        $service->shouldReceive('createPixPayment')->once()->withArgs(function (array $request) use ($charge) {
            $this->assertSame(1200.0, $request['transaction_amount']);
            $this->assertSame(['tenant_id' => $this->tenant->id, 'charge_id' => $charge->id], json_decode($request['external_reference'], true));

            return true;
        })->andReturn((object) ['id' => 'mp-c1', 'transaction_amount' => 1200, 'status' => 'pending', 'date_of_expiration' => null,
            'point_of_interaction' => (object) ['transaction_data' => (object) ['qr_code_base64' => 'qr', 'qr_code' => 'copy']]]);
        $this->app->instance(MercadoPagoService::class, $service);

        // O tenant não escolhe plano: o valor é o da cobrança.
        $this->actingAs($user)->postJson(route('subscription.pay'), ['plan_id' => $this->plan->id])->assertOk();

        $approved = ['id' => 'mp-c1', 'status' => 'approved', 'transaction_amount' => 1200, 'date_of_expiration' => null,
            'external_reference' => json_encode(['tenant_id' => $this->tenant->id, 'charge_id' => $charge->id])];
        $this->webhook($approved)->assertOk()->assertJson(['status' => 'success']);
        $this->webhook($approved)->assertOk()->assertJson(['status' => 'success']);

        $charge->refresh();
        $this->assertSame('paid', $charge->status);
        $this->assertSame('pix', $charge->payment_method);
        $this->assertSame(Payment::query()->where('payment_id', 'mp-c1')->value('id'), $charge->payment_id);
        $this->assertSame(1, FiscalAdminAudit::query()->where('action', 'commercial.charge_paid')->count());
        // Plano não foi aplicado nem o vencimento somado por plano.
        $this->assertSame('2026-11-20', $this->tenant->refresh()->expires_at->toDateString());
        $this->assertSame(Tenant::BILLING_CUSTOM, $this->tenant->billing_mode);
    }

    // ---- 10. Estorno -------------------------------------------------------

    public function test_refund_preserves_audit_and_returns_access_to_due_date(): void
    {
        $this->activate();
        $this->process();
        $charge = $this->charges()->sole();
        app(SaasContractService::class)->registerPayment($charge, '1200.00', 'manual');

        $this->actingAs($this->root)->post(route('admin.tenants.charges.refund', [$this->tenant, $charge]), ['reason' => 'Cobrança indevida', 'password' => 'wrong'])
            ->assertSessionHasErrors('password');
        $this->actingAs($this->root)->post(route('admin.tenants.charges.refund', [$this->tenant, $charge]), ['reason' => 'Cobrança indevida', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame('refunded', $charge->status);
        $this->assertSame('1200.00', (string) $charge->paid_amount);
        $this->assertSame('2026-10-20', $this->tenant->refresh()->expires_at->toDateString());
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'commercial.charge_paid', 'subject_id' => $charge->id]);
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'commercial.charge_refunded', 'subject_id' => $charge->id, 'user_id' => $this->root->id]);
    }

    public function test_mercado_pago_refund_webhook_refunds_charge(): void
    {
        $this->activate();
        $this->process();
        $charge = $this->charges()->sole();
        $reference = json_encode(['tenant_id' => $this->tenant->id, 'charge_id' => $charge->id]);

        $this->webhook(['id' => 'mp-r1', 'status' => 'approved', 'transaction_amount' => 1200, 'date_of_expiration' => null, 'external_reference' => $reference])->assertOk();
        $this->webhook(['id' => 'mp-r1', 'status' => 'refunded', 'transaction_amount' => 1200, 'date_of_expiration' => null, 'external_reference' => $reference])
            ->assertJson(['status' => 'refunded']);

        $this->assertSame('refunded', $charge->refresh()->status);
    }

    // ---- 11-13. Emissão fiscal ----------------------------------------------

    public function test_fiscal_disabled_sends_only_invoice(): void
    {
        $this->issuer();
        $this->activate();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->process();

        $this->assertSame(0, AdminFiscalDocument::query()->count());
        Http::assertNothingSent();
        Mail::assertSent(SaasChargeInvoiceMail::class, 1);
        Mail::assertNotSent(SaasFiscalNoticeMail::class);
    }

    public function test_fiscal_option_requires_apt_issuer_and_valid_receiver(): void
    {
        $issuer = $this->issuer();
        $issuer->update(['certificate_expires_at' => now()->subDay()]);
        $this->actingAs($this->root)->put(route('admin.tenants.commercial.update', $this->tenant), ['custom_contract' => false, 'saas_invoice_enabled' => true])
            ->assertSessionHasErrors('saas_invoice_enabled');
        $this->assertFalse($this->tenant->refresh()->saas_invoice_enabled);

        $issuer->update(['certificate_expires_at' => now()->addYear()]);
        $this->tenant->update(['cnpj' => '123']);
        $this->actingAs($this->root)->put(route('admin.tenants.commercial.update', $this->tenant), ['custom_contract' => false, 'saas_invoice_enabled' => true])
            ->assertSessionHasErrors('saas_invoice_enabled');

        $this->tenant->update(['cnpj' => '11.444.777/0001-61']);
        $this->actingAs($this->root)->put(route('admin.tenants.commercial.update', $this->tenant), ['custom_contract' => false, 'saas_invoice_enabled' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->tenant->refresh()->saas_invoice_enabled);
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'commercial.saas_invoice_toggled', 'tenant_id' => $this->tenant->id]);
    }

    public function test_fiscal_enabled_emits_with_platform_issuer_on_fiscal_date_without_payment(): void
    {
        $this->activate();
        $this->enableFiscal();
        $this->spedyCreates();
        $this->process();

        // Cobrança gerada com antecedência; a nota só sai na data fiscal (vencimento).
        $this->assertSame(0, AdminFiscalDocument::query()->count());

        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->process();

        $charge = $this->charges()->sole();
        $this->assertSame('pending', $charge->status);
        $document = AdminFiscalDocument::query()->sole();
        $this->assertSame($charge->id, $document->tenant_subscription_charge_id);
        $this->assertNull($document->payment_id);
        $this->assertTrue($document->automatic);
        $this->assertSame('1200.00', (string) $document->amount);
        $this->assertSame('2026-10-20', $document->reference_start->toDateString());
        $this->assertSame('2026-11-19', $document->reference_end->toDateString());
        $this->assertSame('authorized', $document->status);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/service-invoices'
            && $request->hasHeader('X-Api-Key', 'platform-issuer-key')
            && $request['receiver']['federalTaxNumber'] === '11444777000161'
            && str_contains($request['description'], 'contrato VOS-'));
    }

    public function test_fiscal_option_works_for_traditional_plan_payments(): void
    {
        $this->enableFiscal();
        $this->spedyCreates();

        $this->webhook(['id' => 'mp-plan-1', 'status' => 'approved', 'transaction_amount' => 89.90, 'date_of_expiration' => null, 'date_approved' => '2026-10-15T10:00:00',
            'external_reference' => json_encode(['tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id])])->assertOk();

        $document = AdminFiscalDocument::query()->sole();
        $this->assertSame(Payment::query()->where('payment_id', 'mp-plan-1')->value('id'), $document->payment_id);
        $this->assertTrue($document->automatic);
        Mail::assertSent(SaasFiscalNoticeMail::class, 1);
    }

    // ---- 14-16. Comunicação -----------------------------------------------

    public function test_invoice_goes_first_and_fiscal_notice_only_after_authorization(): void
    {
        $this->activate();
        $this->enableFiscal();
        $this->spedyCreates('enqueued');
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->process();

        $document = AdminFiscalDocument::query()->sole();
        $this->assertSame('processing', $document->status);
        Mail::assertSent(SaasChargeInvoiceMail::class, fn ($mail) => $mail->links === null);
        Mail::assertNotSent(SaasFiscalNoticeMail::class);

        // Antes da autorização o link não entrega arquivo.
        $this->get(URL::temporarySignedRoute('saas-fiscal-documents.shared', now()->addDay(), ['document' => $document->id, 'format' => 'pdf']))->assertNotFound();

        Http::fake([self::SANDBOX.'/service-invoices/saas-1' => Http::response(['id' => 'saas-1', 'status' => 'authorized', 'number' => 77])]);
        app(SaasInvoiceService::class)->refresh($document->refresh());

        Mail::assertSent(SaasFiscalNoticeMail::class, 1);
        $this->assertDatabaseHas('tenant_subscription_charge_deliveries', ['kind' => 'fiscal', 'status' => 'sent']);
    }

    public function test_pdf_and_xml_are_available_through_signed_links_after_authorization(): void
    {
        $this->activate();
        $this->enableFiscal();
        $this->spedyCreates();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->process();

        $links = null;
        Mail::assertSent(SaasFiscalNoticeMail::class, function (SaasFiscalNoticeMail $mail) use (&$links) {
            $links = $mail->links;

            return $mail->hasTo('cliente@example.test');
        });

        $this->get($links['pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('<nfse>saas</nfse>', $this->get($links['xml'])->assertOk()->getContent());
        $this->get(route('saas-fiscal-documents.shared', ['document' => AdminFiscalDocument::query()->value('id'), 'format' => 'pdf']))->assertForbidden();

        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->get($links['pdf'])->assertForbidden();
    }

    public function test_resending_fiscal_notice_does_not_issue_another_invoice(): void
    {
        $this->activate();
        $this->enableFiscal();
        $this->spedyCreates();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->process();
        $charge = $this->charges()->sole();

        $this->actingAs($this->root)->post(route('admin.tenants.charges.resend-fiscal', [$this->tenant, $charge]))->assertSessionHas('success');
        $this->actingAs($this->root)->post(route('admin.tenants.charges.resend-invoice', [$this->tenant, $charge]))->assertSessionHas('success');
        $this->actingAs($this->root)->post(route('admin.tenants.charges.emit', [$this->tenant, $charge]))->assertSessionHas('error');
        $this->process();

        $this->assertSame(1, AdminFiscalDocument::query()->count());
        Http::assertSentCount(3); // uma emissão + XML + PDF
        Mail::assertSent(SaasFiscalNoticeMail::class, 2);
        // Reenvio da fatura com a nota autorizada leva os links.
        Mail::assertSent(SaasChargeInvoiceMail::class, fn ($mail) => $mail->links !== null);
    }

    // ---- 17-18. Falhas isoladas --------------------------------------------

    public function test_spedy_failure_keeps_the_charge(): void
    {
        $this->activate();
        $this->enableFiscal();
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response(['message' => 'indisponível'], 422)]);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->process();

        $charge = $this->charges()->sole();
        $this->assertSame('pending', $charge->status);
        $this->assertSame('failed', AdminFiscalDocument::query()->sole()->status);
        $this->assertSame('2026-10-20', $this->tenant->refresh()->expires_at->toDateString());
        Mail::assertSent(SaasChargeInvoiceMail::class, 1);
    }

    public function test_smtp_failure_keeps_invoice_and_fiscal_document(): void
    {
        $this->activate();
        $this->enableFiscal();
        $this->spedyCreates();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP fora do ar'));
        $this->process();

        $charge = $this->charges()->sole();
        $this->assertSame('pending', $charge->status);
        $this->assertNull($charge->invoice_sent_at);
        $this->assertSame('authorized', AdminFiscalDocument::query()->sole()->status);
        $this->assertDatabaseHas('tenant_subscription_charge_deliveries', ['kind' => 'invoice', 'status' => 'failed', 'error' => 'SMTP fora do ar']);
        $this->assertDatabaseHas('admin_fiscal_document_deliveries', ['status' => 'failed']);

        // Nova execução tenta a fatura de novo, sem nova nota.
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->process();
        $this->assertSame(2, $charge->deliveries()->where('kind', 'invoice')->where('status', 'failed')->count());
        $this->assertSame(1, AdminFiscalDocument::query()->count());
    }

    // ---- 19. Tenant não altera condições comerciais -------------------------

    public function test_tenant_users_cannot_change_commercial_conditions(): void
    {
        $this->activate();
        $this->process();
        $charge = $this->charges()->sole();
        $admin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->put(route('admin.tenants.commercial.update', $this->tenant), ['custom_contract' => true, 'saas_invoice_enabled' => true, 'amount' => '1', 'billing_months' => 1, 'start_date' => '2026-10-01', 'next_due_date' => '2027-01-01'])
            ->assertRedirect();
        $this->actingAs($admin)->post(route('admin.tenants.charges.pay', [$this->tenant, $charge]), ['amount' => '1200'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.tenants.commercial.revert', $this->tenant), ['plan_id' => $this->plan->id, 'confirm' => true, 'password' => 'password'])->assertRedirect();

        $this->assertSame('1200.00', (string) $this->tenant->refresh()->commercialContract->amount);
        $this->assertSame('pending', $charge->refresh()->status);
        $this->assertSame(Tenant::BILLING_CUSTOM, $this->tenant->billing_mode);
        $this->assertFalse($this->tenant->saas_invoice_enabled);

        // O formulário geral da empresa não grava campos comerciais nem mexe no vencimento do contrato.
        $this->actingAs($this->root)->patch(route('admin.tenants.update', $this->tenant), [
            ...$this->tenant->only(['name', 'company', 'cnpj', 'email', 'phone', 'status']),
            'phone' => '51999999999', 'plan_id' => $this->plan->id, 'billing_mode' => 'plan', 'saas_invoice_enabled' => true,
        ])->assertSessionHasNoErrors();
        $this->tenant->refresh();
        $this->assertSame(Tenant::BILLING_CUSTOM, $this->tenant->billing_mode);
        $this->assertFalse($this->tenant->saas_invoice_enabled);
        $this->assertSame('2026-10-20', $this->tenant->expires_at->toDateString());
    }

    // ---- 20. Retorno ao plano tradicional ----------------------------------

    public function test_reverting_to_plan_cancels_open_charges_without_duplication(): void
    {
        $this->activate();
        $this->process();
        $paid = $this->charges()->sole();
        app(SaasContractService::class)->registerPayment($paid, '1200', 'manual');
        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->process();
        $open = $this->charges()->last();

        $this->actingAs($this->root)->post(route('admin.tenants.commercial.revert', $this->tenant), ['plan_id' => $this->plan->id, 'password' => 'password'])
            ->assertSessionHasErrors('confirm');
        $trial = Plan::factory()->create(['name' => 'Trial', 'slug' => 'trial', 'value' => 0, 'billing_months' => 14]);
        $this->actingAs($this->root)->post(route('admin.tenants.commercial.revert', $this->tenant), ['plan_id' => $trial->id, 'confirm' => true, 'password' => 'password'])
            ->assertSessionHasErrors('plan_id');

        $this->actingAs($this->root)->post(route('admin.tenants.commercial.revert', $this->tenant), ['plan_id' => $this->plan->id, 'confirm' => true, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->tenant->refresh();
        $this->assertSame(Tenant::BILLING_PLAN, $this->tenant->billing_mode);
        $this->assertSame('paid', $paid->refresh()->status);
        $this->assertSame('cancelled', $open->refresh()->status);
        $this->assertFalse($this->tenant->commercialContract->active);
        $this->assertSame('2026-11-20', $this->tenant->expires_at->toDateString());

        // Sem novas cobranças do contrato; a renovação pelo plano parte do período já pago.
        Carbon::setTestNow('2026-12-20 10:00:00');
        $this->process();
        $this->assertCount(2, $this->charges());

        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->webhook(['id' => 'mp-plan-2', 'status' => 'approved', 'transaction_amount' => 89.90, 'date_of_expiration' => null,
            'external_reference' => json_encode(['tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id])])->assertOk();
        $this->assertSame('2026-12-20', $this->tenant->refresh()->expires_at->toDateString());
    }

    public function test_converting_paid_plan_does_not_charge_already_paid_period(): void
    {
        $this->tenant->update(['expires_at' => '2026-11-10']);

        $this->activate(['next_due_date' => '2026-10-20'])->assertSessionHasErrors('next_due_date');
        $this->activate(['next_due_date' => '2026-11-10'])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-10', $this->tenant->refresh()->expires_at->toDateString());
        $this->process();
        $this->assertCount(0, $this->charges());

        // PIX de plano antigo aprovado depois da conversão não renova por plano.
        $this->webhook(['id' => 'mp-old', 'status' => 'approved', 'transaction_amount' => 89.90, 'date_of_expiration' => null,
            'external_reference' => json_encode(['tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id])])
            ->assertJson(['status' => 'ignored_custom_contract']);
        $this->assertSame('2026-11-10', $this->tenant->refresh()->expires_at->toDateString());
    }

    // ---- 21. Isolamento entre tenants --------------------------------------

    public function test_charges_are_isolated_between_tenants(): void
    {
        $other = Tenant::factory()->create(['email' => 'outro@example.test', 'cnpj' => '11.222.333/0001-81', 'expires_at' => null]);
        $this->activate();
        $this->activate(['amount' => '50.00'], $other);
        $this->process();

        $mine = $this->charges()->sole();
        $theirs = TenantSubscriptionCharge::query()->where('tenant_id', $other->id)->sole();
        // 404 em requisição não-GET volta com aviso (bootstrap/app.php).
        $notFound = 'Não foi possível encontrar o registro desta operação. Atualize a tela e tente novamente.';

        $this->actingAs($this->root)->post(route('admin.tenants.charges.pay', [$this->tenant, $theirs]), ['amount' => '50'])->assertSessionHas('error', $notFound);
        $this->actingAs($this->root)->post(route('admin.tenants.charges.refund', [$this->tenant, $theirs]), ['reason' => 'xxxxx', 'password' => 'password'])->assertSessionHas('error', $notFound);
        $this->assertSame('pending', $theirs->refresh()->status);

        // Webhook com tenant e cobrança trocados é recusado.
        $this->webhook(['id' => 'mp-x', 'status' => 'approved', 'transaction_amount' => 1200, 'date_of_expiration' => null,
            'external_reference' => json_encode(['tenant_id' => $this->tenant->id, 'charge_id' => $theirs->id])])->assertStatus(422);
        $this->assertSame('pending', $theirs->refresh()->status);

        $this->assertSame($mine->id, app(SaasContractService::class)->openChargeFor($this->tenant->refresh())->id);
        $this->actingAs($this->root)->get(route('admin.tenants.show', $this->tenant))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('commercial.charges', 1)->where('commercial.charges.0.id', $mine->id));
        Mail::assertSent(SaasChargeInvoiceMail::class, fn ($mail) => $mail->hasTo('outro@example.test') && $mail->charge->is($theirs));
    }

    public function test_tenant_with_contract_history_cannot_be_deleted(): void
    {
        $this->activate();

        $this->actingAs($this->root)->delete(route('admin.tenants.destroy', $this->tenant))->assertSessionHas('error');
        $this->assertModelExists($this->tenant);
        $this->assertModelExists(TenantCommercialContract::query()->sole());
    }
}
