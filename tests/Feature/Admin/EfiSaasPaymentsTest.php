<?php

namespace Tests\Feature\Admin;

use App\Mail\SaasChargeInvoiceMail;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\Admin\SaasPaymentEvent;
use App\Models\Admin\SaasPaymentSetting;
use App\Models\Admin\SaasRecurringAuthorization;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commercial\SaasContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesEfi;
use Tests\TestCase;

/**
 * VETOR-SAAS-EFI-02: Pix, boleto/Bolix, cartão e recorrência pela Efí, contra um simulador controlado
 * das APIs Pix e Cobranças (sem credenciais reais, sem cobrança real).
 */
class EfiSaasPaymentsTest extends TestCase
{
    use FakesEfi, RefreshDatabase;

    private User $root;

    private Plan $plan;

    private Tenant $tenant;

    private User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
        $this->plan = Plan::factory()->create(['name' => 'Mensal', 'slug' => 'mensal', 'value' => 89.90, 'billing_months' => 1]);
        Period::factory()->create(['plan_id' => $this->plan->id, 'name' => 'Mensal', 'interval' => 'month', 'interval_count' => 1, 'price' => 89.90]);
        $this->tenant = $this->makeTenant('11.444.777/0001-61', 'cliente@example.test');
        $this->tenantUser = User::factory()->forTenant($this->tenant->id)->create();

        $this->configureEfi();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeTenant(string $cnpj, string $email): Tenant
    {
        return Tenant::factory()->create([
            'plan_id' => $this->plan->id, 'company' => 'Cliente '.$email, 'name' => 'Maria', 'cnpj' => $cnpj, 'email' => $email, 'phone' => '51999998888',
            'street' => 'Rua A', 'number' => '1', 'district' => 'Centro', 'zip_code' => '90000-000', 'city' => 'Porto Alegre', 'state' => 'RS',
            'expires_at' => null, 'subscription_status' => 'active',
        ]);
    }

    private function contract(?Tenant $tenant = null, string $amount = '1200.00'): TenantSubscriptionCharge
    {
        $tenant ??= $this->tenant;
        $this->actingAs($this->root)->put(route('admin.tenants.commercial.update', $tenant), [
            'custom_contract' => true, 'saas_invoice_enabled' => false, 'amount' => $amount, 'billing_months' => 1,
            'start_date' => '2026-10-01', 'next_due_date' => '2026-10-20',
        ])->assertSessionHasNoErrors();
        $this->process();

        return TenantSubscriptionCharge::query()->where('tenant_id', $tenant->id)->orderByDesc('cycle_start')->firstOrFail();
    }

    /** Só Pix habilitado: sem o Bolix automático do agendador, o Pix cria a própria cobrança (cob). */
    private function onlyPix(): void
    {
        SaasPaymentSetting::current()->forceFill(['efi_methods' => ['pix']])->save();
    }

    private function process(): void
    {
        $this->artisan('vetoros:process-saas-contracts')->assertSuccessful();
    }

    private function cardPayload(array $overrides = []): array
    {
        return [
            'payment_token' => 'card-token-ok', 'card_mask' => 'XXXXXXXXXXXX1111',
            'holder' => ['name' => 'Maria Silva', 'cpf' => '529.982.247-25', 'birth' => '1985-05-10', 'email' => 'maria@example.test', 'phone_number' => '51999998888'],
            'billing' => ['street' => 'Rua A', 'number' => '1', 'neighborhood' => 'Centro', 'zipcode' => '90000-000', 'city' => 'Porto Alegre', 'state' => 'RS'],
            ...$overrides,
        ];
    }

    // ---- Pix -------------------------------------------------------------------

    public function test_pix_creates_unique_charge_and_reuses_pending_one(): void
    {
        $this->onlyPix();
        $charge = $this->contract();

        $first = $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->assertOk()->json();
        $second = $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->assertOk()->json();

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $this->cobRequests());
        $payment = Payment::query()->findOrFail($first['id']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{26,35}$/', $payment->payment_id);
        $this->assertSame([Payment::GATEWAY_EFI, 'pix', $charge->id], [$payment->gateway, $payment->method, $payment->tenant_subscription_charge_id]);
        $this->assertSame('000201PIX'.$payment->payment_id, $first['qr_code_copy_paste']);
        $this->assertSame('iVBORw0KGgo=', $first['qr_code']);

        // O valor enviado à Efí é o da competência, nunca o preço de um plano.
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v2/cob/') && $request->method() === 'PUT'
            && $request['valor']['original'] === '1200.00' && $request['devedor']['cnpj'] === '11444777000161' && $request['chave'] === 'pix@abrasil.test');
    }

    public function test_pix_webhook_confirms_by_api_and_repeated_events_are_ignored(): void
    {
        $this->onlyPix();
        $charge = $this->contract();
        $payment = Payment::query()->findOrFail($this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->json('id'));

        $this->payCob($payment->payment_id);
        $this->pixWebhook($payment->payment_id)->assertOk()->assertJson(['queued' => 1]);
        $this->pixWebhook($payment->payment_id)->assertOk()->assertJson(['queued' => 0]);

        $this->assertSame('paid', $charge->refresh()->status);
        $this->assertSame('efi_pix', $charge->payment_method);
        $this->assertSame($payment->id, (int) $charge->payment_id);
        $this->assertSame(['paid', '1200.00'], [$payment->refresh()->status, (string) $payment->paid_amount]);
        $this->assertSame(1, FiscalAdminAudit::query()->where('action', 'commercial.charge_paid')->count());
        $this->assertSame(SaasPaymentEvent::STATUS_PROCESSED, SaasPaymentEvent::query()->sole()->status);
        $this->assertSame('2026-11-20', $this->tenant->refresh()->expires_at->toDateString());

        $this->actingAs($this->tenantUser)->getJson(route('subscription.check_status', ['payment' => $payment->id]))->assertJson(['status' => 'active']);
    }

    public function test_webhook_payload_is_never_trusted_without_api_confirmation(): void
    {
        $this->onlyPix();
        $charge = $this->contract();
        $payment = Payment::query()->findOrFail($this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->json('id'));

        // A notificação diz que pagou, mas a API ainda mostra a cobrança ativa.
        $this->pixWebhook($payment->payment_id)->assertOk();
        $this->assertSame('pending', $charge->refresh()->status);
        $this->assertSame('pending', $payment->refresh()->status);

        // Segredo errado: recusado; txid de outra aplicação/conta: ignorado.
        $this->pixWebhook($payment->payment_id, 'E2', 'outro-segredo')->assertForbidden();
        $this->pixWebhook('TXIDDEOUTRAAPLICACAO000000001', 'E3')->assertOk()->assertJson(['queued' => 0]);
        $this->assertSame(SaasPaymentEvent::STATUS_IGNORED, SaasPaymentEvent::query()->where('event_key', 'E3')->value('status'));

        // A tela também não libera sozinha.
        $this->actingAs($this->tenantUser)->getJson(route('subscription.check_status', ['payment' => $payment->id]))->assertJson(['status' => 'pending']);
    }

    public function test_api_failure_keeps_charge_and_returns_safe_error(): void
    {
        $this->onlyPix();
        $charge = $this->contract();
        $this->pixDown = true;

        $response = $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->assertStatus(502)->json();

        $this->assertStringNotContainsString('Client_Secret', json_encode($response));
        $this->assertSame(0, Payment::query()->where('gateway', 'efi')->count());
        $this->assertSame('pending', $charge->refresh()->status);
    }

    public function test_temporary_failure_in_confirmation_is_retried_on_redelivery(): void
    {
        $this->onlyPix();
        $charge = $this->contract();
        $payment = Payment::query()->findOrFail($this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->json('id'));
        $this->payCob($payment->payment_id);

        $this->pixDown = true;
        try {
            $this->pixWebhook($payment->payment_id);
        } catch (\Throwable) {
            // Fila síncrona nos testes: a falha temporária sobe para a fila retentar.
        }
        $this->assertSame(SaasPaymentEvent::STATUS_FAILED, SaasPaymentEvent::query()->sole()->status);
        $this->assertSame('pending', $charge->refresh()->status);

        $this->pixDown = false;
        $this->pixWebhook($payment->payment_id)->assertOk()->assertJson(['queued' => 1]);
        $this->assertSame('paid', $charge->refresh()->status);
    }

    // ---- Boleto/Bolix ----------------------------------------------------------

    public function test_billet_uses_contract_due_date_and_bolix_pix_is_reused(): void
    {
        $charge = $this->contract();
        Payment::query()->where('gateway', 'efi')->delete();

        $billet = $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.billet'))->assertOk()->json();
        $this->assertSame('34191.79001 01043.510047', $billet['barcode']);
        $this->assertSame('data:image/svg+xml;base64,PHN2Zz4=', $billet['qr_code_image']);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/charge/one-step')
            && $request['payment']['banking_billet']['expire_at'] === '2026-10-20'
            && $request['payment']['banking_billet']['customer']['juridical_person']['cnpj'] === '11444777000161'
            && $request['items'][0]['value'] === 120000
            && str_contains($request['metadata']['notification_url'], '/api/webhooks/efi-charges/'));

        // Pix pedido depois: devolve o Pix do próprio Bolix, sem criar outra cobrança (sem pagamento em dobro).
        $pix = $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->assertOk()->json();
        $this->assertSame($billet['id'], $pix['id']);
        $this->assertSame(0, $this->cobRequests());
        $this->assertSame('pending', $charge->refresh()->status);
    }

    public function test_scheduler_generates_billet_and_invoice_carries_payment_data(): void
    {
        $charge = $this->contract();

        $payment = Payment::query()->where('tenant_subscription_charge_id', $charge->id)->sole();
        $this->assertSame(['efi', 'billet'], [$payment->gateway, $payment->method]);
        Mail::assertSent(SaasChargeInvoiceMail::class, fn ($mail) => str_contains($mail->paymentInstructions, '34191.79001 01043.510047')
            && str_contains($mail->paymentInstructions, '000201BOLIX'));

        // Reprocessar não cria outro boleto da mesma competência.
        $this->process();
        $this->assertSame(1, Payment::query()->where('tenant_subscription_charge_id', $charge->id)->count());
    }

    public function test_billet_paid_via_charges_notification_cancels_sibling_pix(): void
    {
        $charge = $this->contract();
        $billet = Payment::query()->where('tenant_subscription_charge_id', $charge->id)->sole();
        // Um Pix avulso gerado antes (o Bolix também já tinha Pix): fica como irmão pendente.
        $pix = Payment::query()->create([
            'tenant_id' => $this->tenant->id, 'tenant_subscription_charge_id' => $charge->id, 'gateway' => 'efi', 'method' => 'pix',
            'payment_id' => 'VOSTXIDIRMAO00000000000000001', 'reference' => 'VOSTXIDIRMAO00000000000000001', 'amount' => '1200.00',
            'status' => 'pending', 'idempotency_key' => 'efi-irmao', 'expires_at' => now()->addDay(),
        ]);
        $this->cobs[$pix->payment_id] = ['txid' => $pix->payment_id, 'status' => 'ATIVA', 'pix' => []];

        $this->efiCharges[$billet->payment_id]['status'] = 'paid';
        $this->chargesNotification('notif-1', [['id' => 1, 'type' => 'charge', 'custom_id' => $billet->reference, 'status' => ['current' => 'paid', 'previous' => 'waiting'], 'identifiers' => ['charge_id' => (int) $billet->payment_id]]])
            ->assertOk();
        // Reenvio da mesma notificação: não processa de novo.
        $this->chargesNotification('notif-1', [])->assertOk();

        $this->assertSame(['paid', 'efi_boleto'], [$charge->refresh()->status, $charge->payment_method]);
        $this->assertSame('cancelled', $pix->refresh()->status);
        $this->assertSame('REMOVIDA_PELO_USUARIO_RECEBEDOR', $this->cobs[$pix->payment_id]['status']);
        $this->assertSame(1, SaasPaymentEvent::query()->where('channel', 'charges')->count());
    }

    public function test_late_payment_reactivates_blocked_tenant(): void
    {
        $charge = $this->contract();
        $billet = Payment::query()->where('tenant_subscription_charge_id', $charge->id)->sole();

        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->assertSame(Tenant::SUBSCRIPTION_BLOCKED, $this->tenant->refresh()->subscriptionBucket());

        // Boleto vencido sem pagamento: expira; um novo sai com vencimento curto.
        $this->efiCharges[$billet->payment_id]['status'] = 'expired';
        $this->artisan('vetoros:reconcile-saas-payments')->assertSuccessful();
        $this->assertSame('expired', $billet->refresh()->status);

        $late = $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.billet'))->assertOk()->json();
        $this->assertSame('2026-11-01', Payment::query()->find($late['id'])->due_date->toDateString());

        $this->efiCharges[$late['payment_id']]['status'] = 'paid';
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->artisan('vetoros:reconcile-saas-payments')->assertSuccessful();

        $this->assertSame('paid', $charge->refresh()->status);
        $this->assertSame(Tenant::SUBSCRIPTION_ACTIVE, $this->tenant->refresh()->subscriptionBucket());
    }

    // ---- Cartão ----------------------------------------------------------------

    public function test_card_payment_is_confirmed_by_api_and_card_data_is_not_stored(): void
    {
        $charge = $this->contract();

        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.card'), $this->cardPayload())->assertOk()->assertJson(['status' => 'paid']);

        $this->assertSame(['paid', 'efi_cartao'], [$charge->refresh()->status, $charge->payment_method]);
        $payment = Payment::query()->where('method', 'card')->sole();
        $this->assertSame('XXXXXXXXXXXX1111', $payment->details['card_mask']);
        $stored = json_encode(Payment::query()->get()->toArray()).json_encode(FiscalAdminAudit::query()->get()->toArray());
        $this->assertStringNotContainsString('card-token-ok', json_encode($payment->details));
        $this->assertStringNotContainsString('4111111111111111', $stored);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/charge/one-step')
            && ($request['payment']['credit_card']['payment_token'] ?? null) === 'card-token-ok'
            && ($request['payment']['credit_card']['customer']['cpf'] ?? null) === '52998224725');
    }

    public function test_refused_card_keeps_charge_open(): void
    {
        $charge = $this->contract();

        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.card'), $this->cardPayload(['payment_token' => 'refused-token']))
            ->assertOk()->assertJson(['status' => 'failed']);

        $this->assertSame('pending', $charge->refresh()->status);
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'saas_payment.failed']);
        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.card'), $this->cardPayload(['holder' => ['name' => 'X']]))->assertUnprocessable();
    }

    public function test_recurring_card_requires_authorization_and_reconciles_each_competence(): void
    {
        $first = $this->contract();

        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.card'), $this->cardPayload(['recurring' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('authorize_recurring');

        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.card'), $this->cardPayload(['recurring' => true, 'authorize_recurring' => true]))
            ->assertOk()->assertJson(['status' => 'paid']);

        $authorization = SaasRecurringAuthorization::query()->sole();
        $this->assertSame([SaasRecurringAuthorization::STATUS_ACTIVE, 1, '1200.00'], [$authorization->status, $authorization->interval_months, (string) $authorization->amount]);
        $this->assertSame(['paid', 'efi_recorrente'], [$first->refresh()->status, $first->payment_method]);
        $this->assertSame('cancelled', Payment::query()->where('method', 'billet')->sole()->status);

        // Próxima competência: o agendador não cria cobrança externa (a Efí cobra no cartão).
        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->process();
        $second = TenantSubscriptionCharge::query()->where('tenant_id', $this->tenant->id)->orderByDesc('cycle_start')->first();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(0, Payment::query()->where('tenant_subscription_charge_id', $second->id)->count());
        Mail::assertSent(SaasChargeInvoiceMail::class, fn ($mail) => $mail->charge->is($second) && str_contains($mail->paymentInstructions, 'Cobrança automática no cartão'));

        // Renovação da Efí chega por notificação com uma cobrança nova da assinatura.
        $this->efiCharges[7001] = ['charge_id' => 7001, 'total' => 120000, 'status' => 'paid'];
        $this->chargesNotification('notif-sub-1', [['id' => 2, 'type' => 'subscription_charge', 'status' => ['current' => 'paid'],
            'identifiers' => ['charge_id' => 7001, 'subscription_id' => (int) $authorization->provider_subscription_id]]])->assertOk();

        $this->assertSame(['paid', 'efi_recorrente'], [$second->refresh()->status, $second->payment_method]);
        $this->assertSame((string) $authorization->provider_subscription_id, Payment::query()->where('payment_id', '7001')->value('provider_subscription_id'));

        // Plano criado uma vez na Efí e reaproveitado.
        $this->assertSame(1, count(Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/plan'))));
    }

    public function test_recurring_renewal_failure_and_cancellation(): void
    {
        $this->contract();
        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.card'), $this->cardPayload(['recurring' => true, 'authorize_recurring' => true]))->assertOk();
        $authorization = SaasRecurringAuthorization::query()->sole();

        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->process();
        $second = TenantSubscriptionCharge::query()->where('tenant_id', $this->tenant->id)->orderByDesc('cycle_start')->first();

        $this->efiCharges[7002] = ['charge_id' => 7002, 'total' => 120000, 'status' => 'unpaid'];
        $this->chargesNotification('notif-sub-fail', [['type' => 'subscription_charge', 'status' => ['current' => 'unpaid'],
            'identifiers' => ['charge_id' => 7002, 'subscription_id' => (int) $authorization->provider_subscription_id]]])->assertOk();

        $this->assertSame('pending', $second->refresh()->status);
        $this->assertNotNull($authorization->refresh()->last_failure_at);

        // O titular cancela: as próximas competências voltam a ter boleto.
        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.recurring.cancel'), ['confirm' => true])->assertOk();
        $this->assertSame(SaasRecurringAuthorization::STATUS_CANCELLED, $authorization->refresh()->status);
        $this->assertSame('canceled', $this->subscriptions[$authorization->provider_subscription_id]['status']);

        Carbon::setTestNow('2026-12-16 10:00:00');
        $this->process();
        $third = TenantSubscriptionCharge::query()->where('tenant_id', $this->tenant->id)->orderByDesc('cycle_start')->first();
        $this->assertSame('billet', Payment::query()->where('tenant_subscription_charge_id', $third->id)->sole()->method);
    }

    // ---- Estorno e divergência ---------------------------------------------------

    public function test_refund_reported_by_api_refunds_charge_and_returns_access_to_due_date(): void
    {
        $charge = $this->contract();
        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.card'), $this->cardPayload())->assertOk();
        $payment = Payment::query()->where('method', 'card')->sole();
        $this->assertSame('2026-11-20', $this->tenant->refresh()->expires_at->toDateString());

        $this->efiCharges[$payment->payment_id]['status'] = 'refunded';
        $this->chargesNotification('notif-refund', [['type' => 'charge', 'status' => ['current' => 'refunded'], 'identifiers' => ['charge_id' => (int) $payment->payment_id]]]);

        $this->assertSame('refunded', $charge->refresh()->status);
        $this->assertSame('refunded', $payment->refresh()->status);
        $this->assertSame('2026-10-20', $this->tenant->refresh()->expires_at->toDateString());
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'saas_payment.refunded']);
    }

    public function test_payment_for_already_paid_charge_becomes_audited_divergence_and_can_be_refunded(): void
    {
        $charge = $this->contract();
        $payment = Payment::query()->findOrFail($this->actingAs($this->tenantUser)->postJson(route('subscription.efi.billet'))->json('id'));
        $pix = Payment::query()->create([
            'tenant_id' => $this->tenant->id, 'tenant_subscription_charge_id' => $charge->id, 'gateway' => 'efi', 'method' => 'pix',
            'payment_id' => 'VOSTXIDDUPLO00000000000000001', 'reference' => 'VOSTXIDDUPLO00000000000000001', 'amount' => '1200.00',
            'status' => 'pending', 'idempotency_key' => 'efi-duplo', 'expires_at' => now()->addDay(),
        ]);
        $this->cobs[$pix->payment_id] = ['txid' => $pix->payment_id, 'status' => 'ATIVA', 'pix' => []];

        app(SaasContractService::class)->registerPayment($charge, '1200', 'manual');
        $this->payCob($pix->payment_id, '1200.00', 'E2EDUPLO');
        $this->pixWebhook($pix->payment_id, 'E2EDUPLO')->assertOk();

        $this->assertSame(['paid', 'manual'], [$charge->refresh()->status, $charge->payment_method]);
        $this->assertStringStartsWith('Divergência', $pix->refresh()->error);
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'saas_payment.divergence', 'subject_id' => $pix->id]);
        $this->assertSame($payment->id, Payment::query()->find($payment->id)->id);

        $this->actingAs($this->root)->post(route('admin.finance.efi.payments.refund', $pix), ['password' => 'password'])->assertSessionHas('success');
        $this->assertSame('refunded', $pix->refresh()->status);
        $this->assertSame('paid', $charge->refresh()->status);
    }

    // ---- Plano tradicional, transição e isolamento --------------------------------

    public function test_traditional_plan_is_renewed_through_the_same_processing(): void
    {
        $this->tenant->update(['expires_at' => '2026-10-10']);

        $response = $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->plan->id])->assertOk()->json();
        $payment = Payment::query()->findOrFail($response['id']);
        $this->assertSame([$this->plan->id, null], [$payment->plan_id, $payment->tenant_subscription_charge_id]);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v2/cob/') && $request->method() === 'PUT' && $request['valor']['original'] === '89.90');

        $this->payCob($payment->payment_id, '89.90');
        $this->pixWebhook($payment->payment_id)->assertOk();
        $this->pixWebhook($payment->payment_id, 'E-repetido')->assertOk();

        $this->tenant->refresh();
        $this->assertSame('2026-11-15', $this->tenant->expires_at->toDateString());
        $this->assertSame($payment->payment_id, $this->tenant->last_payment_id);
    }

    /**
     * Transição (VETOR-SAAS-EFI-03): pagamento do provedor anterior ainda pendente e válido bloqueia
     * novas cobranças até expirar; não é recriado na Efí nem alterado (histórico preservado).
     */
    public function test_pending_legacy_payment_blocks_new_charges_until_it_expires(): void
    {
        $this->onlyPix();
        SaasPaymentSetting::current()->forceFill(['efi_methods' => ['pix', 'billet']])->save();
        $legacy = Payment::query()->create([
            'tenant_id' => $this->tenant->id, 'gateway' => 'mercadopago', 'payment_id' => 'legado-1', 'amount' => 1200, 'status' => 'pending',
            'idempotency_key' => 'legado-key', 'expires_at' => now()->addHour(), 'raw_response' => ['external_reference' => '{"tenant_id":1}'],
        ]);
        $charge = $this->contract();

        // O agendador não cria boleto enquanto o pagamento anterior vale; a tela mostra o aviso.
        $this->assertSame(0, Payment::query()->where('gateway', 'efi')->count());
        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->actingAs($this->tenantUser)->get(route('app.other-settings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscription.checkout.methods', [])->where('subscription.checkout.unavailable_message', fn ($message) => str_contains($message, 'pagamento anterior')));
        $this->assertSame(0, $this->cobRequests());
        $this->artisan('vetoros:legacy-payments-report')->assertFailed();

        // Vencido o pagamento anterior, a Efí volta a cobrar; o registro antigo fica intacto.
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->assertOk();
        $this->assertSame(['mercadopago', 'pending', 'legado-1'], [$legacy->refresh()->gateway, $legacy->status, $legacy->payment_id]);
        $this->artisan('vetoros:legacy-payments-report')->assertSuccessful();
        $this->assertSame('pending', $charge->refresh()->status);
    }

    public function test_without_homologation_new_charges_are_blocked_and_no_other_gateway_is_used(): void
    {
        SaasPaymentSetting::current()->forceFill(['efi_homologated_at' => null])->save();
        $charge = $this->contract();

        $this->assertSame(0, Payment::query()->count());
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'saas_payment.collection_blocked', 'subject_id' => $charge->id]);
        $this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->assertUnprocessable()
            ->assertJsonValidationErrors(['method' => 'Pagamento online indisponível no momento. Fale com o atendimento da ABrasil Sistemas.']);
        $this->actingAs($this->tenantUser)->get(route('app.other-settings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscription.checkout.provider', 'efi')->where('subscription.checkout.methods', [])->has('subscription.checkout.unavailable_message'));
        // Nenhuma chamada a outro provedor e nenhuma rota legada.
        Http::assertNothingSent();
        $this->postJson('/api/webhooks/mercadopago/qualquer')->assertNotFound();
        $this->assertSame('pending', $charge->refresh()->status);

        $this->actingAs($this->root)->get(route('admin.finance.efi'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.blocked_reason', fn ($reason) => str_contains($reason, 'não homologada')));
    }

    public function test_tenants_are_isolated(): void
    {
        $this->onlyPix();
        $mine = $this->contract();
        $other = $this->makeTenant('45.723.174/0001-10', 'outro@example.test');
        $otherUser = User::factory()->forTenant($other->id)->create();
        $theirs = $this->contract($other, '700.00');

        $myPix = Payment::query()->findOrFail($this->actingAs($this->tenantUser)->postJson(route('subscription.efi.pix'))->json('id'));
        $this->actingAs($otherUser)->getJson(route('subscription.efi.status', $myPix))->assertNotFound();

        $this->payCob($myPix->payment_id);
        $this->pixWebhook($myPix->payment_id)->assertOk();

        $this->assertSame('paid', $mine->refresh()->status);
        $this->assertSame('pending', $theirs->refresh()->status);
        $this->assertSame(0, Payment::query()->where('tenant_id', $other->id)->count());
    }

    // ---- RootAdmin → Financeiro → Efí Bank ---------------------------------------

    public function test_only_root_admin_manages_settings_and_secrets_never_reach_the_browser(): void
    {
        $tenantAdmin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->actingAs($tenantAdmin)->get(route('admin.finance.efi'))->assertForbidden();

        $page = $this->actingAs($this->root)->get(route('admin.finance.efi'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/finance/efi')->where('settings.charges_configured', true));
        foreach (['Client_Secret_charges', 'Client_Secret_pix', 'Client_Id_pix', 'webhook-secret-123', 'PRIVATE KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $page->getContent());
        }

        // Guardado criptografado no banco.
        $this->assertStringNotContainsString('Client_Secret_pix', (string) \DB::table('saas_payment_settings')->value('efi_pix_client_secret'));

        $this->actingAs($this->root)->put(route('admin.finance.efi.update'), ['environment' => 'homologation', 'methods' => ['pix'], 'password' => 'errada'])
            ->assertSessionHasErrors('password');

        // Nova credencial: exige nova homologação; até lá novas cobranças ficam bloqueadas (sem outro gateway).
        $this->actingAs($this->root)->put(route('admin.finance.efi.update'), [
            'environment' => 'homologation', 'pix_client_secret' => 'novo-segredo', 'pix_key' => 'pix@abrasil.test', 'payee_code' => 'abc123payee',
            'methods' => ['pix', 'billet'], 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $settings = SaasPaymentSetting::current();
        $this->assertSame([null, 'novo-segredo', false], [$settings->efi_homologated_at, $settings->efi_pix_client_secret, $settings->usesEfi()]);
        $this->assertNotNull($settings->blockedReason());
        $audit = FiscalAdminAudit::query()->where('action', 'efi.settings_updated')->sole();
        $this->assertStringNotContainsString('novo-segredo', json_encode($audit->data));
    }

    public function test_connection_test_webhook_registration_and_homologation_gate(): void
    {
        SaasPaymentSetting::current()->forceFill(['efi_homologated_at' => null, 'last_connection_ok' => null])->save();

        $this->actingAs($this->root)->post(route('admin.finance.efi.homologation'), ['action' => 'homologate', 'confirm' => true, 'password' => 'password'])
            ->assertSessionHasErrors('action');
        // Não há outro provedor para escolher.
        $this->actingAs($this->root)->post(route('admin.finance.efi.homologation'), ['action' => 'mercadopago', 'confirm' => true, 'password' => 'password'])
            ->assertSessionHasErrors('action');

        $this->actingAs($this->root)->post(route('admin.finance.efi.test'))->assertSessionHas('success');
        $this->actingAs($this->root)->post(route('admin.finance.efi.webhook'))->assertSessionHas('success');
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v2/webhook/') && $request->hasHeader('x-skip-mtls-checking')
            && str_starts_with($request['webhookUrl'], 'https://') && str_ends_with($request['webhookUrl'], '/api/webhooks/efi/webhook-secret-123'));
        $this->assertStringNotContainsString('webhook-secret-123', (string) SaasPaymentSetting::current()->efi_pix_webhook_url);

        $this->actingAs($this->root)->post(route('admin.finance.efi.homologation'), ['action' => 'homologate', 'confirm' => true, 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(SaasPaymentSetting::current()->usesEfi());

        // Suspender bloqueia novas cobranças na hora; nada volta para outro gateway.
        $this->actingAs($this->root)->post(route('admin.finance.efi.homologation'), ['action' => 'suspend', 'confirm' => true, 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertFalse(SaasPaymentSetting::current()->usesEfi());
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'efi.suspended']);
    }

    public function test_pix_certificate_p12_is_converted_and_stored_encrypted(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'VetorOS Efi Teste'], $key), null, $key, 365);
        openssl_pkcs12_export($cert, $p12, $key, '');
        $path = tempnam(sys_get_temp_dir(), 'p12');
        file_put_contents($path, $p12);

        $this->actingAs($this->root)->post(route('admin.finance.efi.certificate'), [
            'certificate' => new UploadedFile($path, 'certificado.p12', 'application/x-pkcs12', null, true),
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        $settings = SaasPaymentSetting::current();
        $this->assertStringContainsString('BEGIN CERTIFICATE', $settings->efi_pix_certificate);
        $this->assertSame('VetorOS Efi Teste', $settings->efi_pix_certificate_subject);
        $this->assertStringNotContainsString('BEGIN', (string) \DB::table('saas_payment_settings')->value('efi_pix_certificate'));
        $this->assertNull($settings->efi_homologated_at);
    }
}
