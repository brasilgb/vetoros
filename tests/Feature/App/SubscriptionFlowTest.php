<?php

namespace Tests\Feature\App;

use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\Admin\SaasPaymentSetting;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commercial\SaasContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesEfi;
use Tests\TestCase;

/**
 * VETOR-SAAS-EFI-04: do aviso de vencimento à reativação depois do pagamento confirmado pela Efí.
 * Carência de 3 dias preservada; telas de assinatura sempre alcançáveis, sem ciclo de redirecionamento.
 */
class SubscriptionFlowTest extends TestCase
{
    use FakesEfi, RefreshDatabase;

    private User $root;

    private Plan $plan;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
        $this->plan = Plan::factory()->create(['name' => 'Mensal', 'slug' => 'mensal', 'value' => 89.90, 'billing_months' => 1]);
        Period::factory()->create(['plan_id' => $this->plan->id, 'name' => 'Mensal', 'interval' => 'month', 'interval_count' => 1, 'price' => 89.90]);
        $this->tenant = $this->makeTenant('11.444.777/0001-61', 'cliente@example.test', '2026-11-30');
        $this->user = User::factory()->forTenant($this->tenant->id)->create();

        $this->configureEfi(['pix', 'billet', 'card']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeTenant(string $cnpj, string $email, ?string $expiresAt): Tenant
    {
        return Tenant::factory()->create([
            'plan_id' => $this->plan->id, 'company' => 'Assistência '.$email, 'name' => 'Maria', 'cnpj' => $cnpj, 'email' => $email,
            'phone' => '51999998888', 'street' => 'Rua A', 'number' => '1', 'district' => 'Centro', 'zip_code' => '90000-000',
            'city' => 'Porto Alegre', 'state' => 'RS', 'expires_at' => $expiresAt, 'subscription_status' => 'active',
        ]);
    }

    private function expiresIn(int $days): void
    {
        $this->tenant->forceFill(['expires_at' => now()->startOfDay()->addDays($days)])->save();
        // O usuário do teste guarda a relação carregada; em produção cada requisição relê o tenant.
        $this->user->unsetRelation('tenant');
    }

    private function contract(string $nextDue = '2026-10-20'): TenantSubscriptionCharge
    {
        // Sem período de plano já pago: o contrato começa a cobrar no vencimento informado.
        $this->tenant->forceFill(['expires_at' => null])->save();
        $this->actingAs($this->root)->put(route('admin.tenants.commercial.update', $this->tenant), [
            'custom_contract' => true, 'saas_invoice_enabled' => false, 'amount' => '1200.00', 'billing_months' => 1,
            'start_date' => min('2026-10-01', $nextDue), 'next_due_date' => $nextDue,
        ])->assertSessionHasNoErrors();
        $this->artisan('vetoros:process-saas-contracts')->assertSuccessful();

        return TenantSubscriptionCharge::query()->where('tenant_id', $this->tenant->id)->orderBy('cycle_start')->firstOrFail();
    }

    private function alert(): ?array
    {
        $alert = null;
        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$alert) {
                $alert = $page->toArray()['props']['subscription_alert'] ?? null;
            });

        return $alert;
    }

    // ---- Estados da assinatura ------------------------------------------------

    public function test_active_subscription_has_normal_access_and_can_be_consulted(): void
    {
        $this->assertNull($this->alert());

        $this->actingAs($this->user)->get(route('app.other-settings.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('app/others/index')
                ->where('subscription.status.state', 'active')->where('subscription.status.expires_at', '2026-11-30')->where('subscription.status.billing_mode', 'plan'));

        // Fora do bloqueio, o endereço de bloqueio e o antigo "Minha assinatura" levam ao bloco da licença.
        $license = route('app.other-settings.index').'#licenca';
        $this->actingAs($this->user)->get(route('subscription.blocked'))->assertRedirect($license);
        $this->actingAs($this->user)->get(route('subscription.show'))->assertRedirect($license);
    }

    public function test_license_section_shows_only_filled_holder_data_and_signed_days(): void
    {
        $this->expiresIn(-2);
        $this->tenant->update(['company' => 'Assistência Licenciada', 'street' => null, 'number' => null, 'district' => null, 'city' => 'Porto Alegre', 'state' => 'RS']);

        $this->actingAs($this->user)->get(route('app.other-settings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('app/others/index')
                ->where('license.days_remaining', -2)
                ->where('license.address', 'Porto Alegre - RS')
                ->where('license.holder', fn ($holder) => $holder !== '' && ! str_contains((string) $holder, 'null'))
                ->where('subscription.status.state', 'grace')
                ->has('subscription.checkout'));
    }

    public function test_near_due_shows_discreet_notice_with_due_date(): void
    {
        $this->expiresIn(2);

        $this->assertSame(['due_soon', '2026-10-17', 2], array_values(array_intersect_key($this->alert(), array_flip(['status', 'expires_at', 'days_until_due']))));
        $this->actingAs($this->user)->get(route('app.other-settings.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('subscription.status.state', 'due_soon'));

        // Antes da janela de aviso, nada aparece.
        $this->expiresIn(4);
        $this->assertNull($this->alert());
    }

    public function test_due_today_shows_highlighted_notice_and_access_stays(): void
    {
        $this->expiresIn(0);

        $this->assertSame('due_today', $this->alert()['status']);
        $this->actingAs($this->user)->get(route('app.other-settings.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('subscription.status.state', 'due_today'));
    }

    public function test_grace_period_keeps_operational_access_with_overdue_notice(): void
    {
        $this->expiresIn(-2);

        $alert = $this->alert();
        $this->assertSame(['grace_period', 2, 1], [$alert['status'], $alert['days_overdue'], $alert['grace_days_left']]);
        $this->actingAs($this->user)->get(route('app.other-settings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscription.status.state', 'grace')->where('subscription.status.grace_until', '2026-10-15'));
    }

    public function test_after_grace_access_is_blocked_but_payment_routes_stay_reachable(): void
    {
        $this->expiresIn(-4);

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertRedirect(route('subscription.blocked'));
        $this->actingAs($this->user)->get(route('subscription.blocked'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('status.state', 'blocked')
                ->where('status.reason', fn ($reason) => str_contains($reason, 'carência de 3 dias terminou')));
        // Sistema e módulos fica atrás do bloqueio: o pagamento segue pela tela de bloqueio.
        $this->actingAs($this->user)->get(route('app.other-settings.index'))->assertRedirect(route('subscription.blocked'));
        $this->actingAs($this->user)->getJson(route('subscription.check_status'))->assertOk()->assertJson(['access' => 'blocked']);
        $this->actingAs($this->user)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->plan->id])->assertOk();
        $this->actingAs($this->user)->post(route('logout'))->assertRedirect();
    }

    // ---- Plano tradicional x contrato personalizado ---------------------------------

    public function test_traditional_plan_shows_current_plan_and_periodicity(): void
    {
        $this->expiresIn(-4);

        $this->actingAs($this->user)->get(route('subscription.blocked'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('charge', null)
                ->where('plans', fn ($plans) => collect($plans)->contains(fn ($plan) => $plan['id'] === $this->plan->id && $plan['current'] === true
                    && $plan['billing_months'] === 1 && $plan['value'] === '89.90')));
    }

    public function test_custom_contract_shows_only_the_contract_charge_and_ignores_client_values(): void
    {
        $charge = $this->contract();

        $this->actingAs($this->user)->get(route('app.other-settings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscription.plans', [])->where('subscription.status.billing_mode', 'custom')
                ->where('subscription.charge.amount', '1200.00')->where('subscription.charge.due_date', '2026-10-20')->where('subscription.charge.periodicity', 'Mensal'));

        // Nem plano nem valor vindos do navegador mudam a cobrança.
        $this->actingAs($this->user)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->plan->id])->assertUnprocessable();
        $pix = $this->actingAs($this->user)->postJson(route('subscription.efi.pix'), ['amount' => '1.00', 'charge_id' => 999])->assertOk()->json();
        $this->assertEquals(1200.0, (float) Payment::query()->find($pix['id'])->amount);
        $this->assertSame($charge->id, Payment::query()->find($pix['id'])->tenant_subscription_charge_id);
    }

    public function test_removed_legacy_routes_do_not_exist(): void
    {
        $this->actingAs($this->user)->postJson('/subscription/pay')->assertNotFound();
        $this->actingAs($this->user)->postJson('/subscription/generate-pix')->assertNotFound();
        $this->postJson('/api/webhooks/mercadopago/qualquer')->assertNotFound();
    }

    // ---- Pagamento e reativação ------------------------------------------------------

    public function test_pix_confirmed_reactivates_blocked_plan_tenant(): void
    {
        $this->expiresIn(-4);
        $pix = $this->actingAs($this->user)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->plan->id])->assertOk()->json();

        // Em processamento: nada é liberado antes da confirmação.
        $this->actingAs($this->user)->getJson(route('subscription.check_status', ['payment' => $pix['id']]))->assertJson(['status' => 'pending', 'access' => 'blocked']);
        $this->actingAs($this->user)->get(route('subscription.blocked'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pending_payment.id', $pix['id'])->where('pending_payment.status', 'pending'));

        $this->payCob($pix['payment_id'], '89.90');
        $this->pixWebhook($pix['payment_id'])->assertOk();
        $this->pixWebhook($pix['payment_id'])->assertOk()->assertJson(['queued' => 0]);

        $this->actingAs($this->user)->getJson(route('subscription.check_status', ['payment' => $pix['id']]))->assertJson(['status' => 'active', 'access' => 'active']);
        $this->assertSame('2026-11-15', $this->tenant->refresh()->expires_at->toDateString());
        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();
        $this->assertNull($this->alert());
    }

    public function test_billet_awaiting_clearance_is_shown_after_reload_and_can_be_rechecked(): void
    {
        $charge = $this->contract();
        $billet = Payment::query()->where('tenant_subscription_charge_id', $charge->id)->sole();

        $this->actingAs($this->user)->get(route('app.other-settings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('subscription.pending_payment.method', 'billet')->where('subscription.pending_payment.barcode', '34191.79001 01043.510047'));
        $this->actingAs($this->user)->postJson(route('subscription.efi.refresh', $billet))->assertOk()->assertJson(['status' => 'pending']);

        // Compensado: a consulta sob demanda aplica no servidor.
        $this->efiCharges[$billet->payment_id]['status'] = 'paid';
        $this->actingAs($this->user)->postJson(route('subscription.efi.refresh', $billet))->assertOk()->assertJson(['status' => 'paid']);
        $this->assertSame('paid', $charge->refresh()->status);
    }

    public function test_refused_card_allows_a_new_valid_attempt(): void
    {
        $this->expiresIn(-4);
        $card = [
            'plan_id' => $this->plan->id, 'payment_token' => 'refused-token', 'card_mask' => 'XXXX1111',
            'holder' => ['name' => 'Maria Silva', 'cpf' => '529.982.247-25', 'birth' => '1985-05-10', 'email' => 'maria@example.test', 'phone_number' => '51999998888'],
            'billing' => ['street' => 'Rua A', 'number' => '1', 'neighborhood' => 'Centro', 'zipcode' => '90000-000', 'city' => 'Porto Alegre', 'state' => 'RS'],
        ];

        $refused = $this->actingAs($this->user)->postJson(route('subscription.efi.card'), $card)->assertOk()->assertJson(['status' => 'failed'])->json();
        $this->actingAs($this->user)->getJson(route('subscription.check_status', ['payment' => $refused['id']]))->assertJson(['status' => 'failed', 'access' => 'blocked']);

        $this->actingAs($this->user)->postJson(route('subscription.efi.card'), [...$card, 'payment_token' => 'card-token-ok'])->assertOk()->assertJson(['status' => 'paid']);
        $this->assertSame(Tenant::SUBSCRIPTION_ACTIVE, $this->tenant->refresh()->subscriptionBucket());
    }

    public function test_expired_charge_is_reported_and_a_new_one_can_be_generated(): void
    {
        $this->expiresIn(-4);
        $first = $this->actingAs($this->user)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->plan->id])->assertOk()->json();

        Carbon::setTestNow('2026-10-16 11:00:00');
        $this->actingAs($this->user)->postJson(route('subscription.efi.refresh', $first['id']))->assertOk()->assertJson(['status' => 'expired']);
        $this->actingAs($this->user)->getJson(route('subscription.check_status', ['payment' => $first['id']]))->assertJson(['status' => 'expired']);

        $second = $this->actingAs($this->user)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->plan->id])->assertOk()->json();
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(1, Payment::query()->where('tenant_id', $this->tenant->id)->where('status', 'pending')->count());
    }

    public function test_late_payment_with_older_overdue_charge_does_not_release_access_early(): void
    {
        $first = $this->contract('2026-09-01');
        $this->assertSame(Tenant::SUBSCRIPTION_BLOCKED, $this->tenant->refresh()->subscriptionBucket());
        $second = TenantSubscriptionCharge::query()->where('tenant_id', $this->tenant->id)->orderByDesc('cycle_start')->first();
        $this->assertNotSame($first->id, $second->id);

        // Paga a competência mais nova fora de ordem (registro manual): a mais antiga ainda bloqueia.
        $payment = Payment::query()->create([
            'tenant_id' => $this->tenant->id, 'tenant_subscription_charge_id' => $second->id, 'gateway' => 'efi', 'method' => 'pix',
            'payment_id' => 'VOSTXIDNOVA00000000000000001', 'reference' => 'VOSTXIDNOVA00000000000000001', 'amount' => '1200.00',
            'status' => 'paid', 'paid_at' => now(), 'idempotency_key' => 'efi-nova',
        ]);
        app(SaasContractService::class)->registerPayment($second, '1200.00', 'efi_pix', $payment);

        $this->actingAs($this->user)->getJson(route('subscription.check_status', ['payment' => $payment->id]))
            ->assertJson(['status' => 'paid_pending_obligations', 'access' => 'blocked', 'has_open_charge' => true]);
        $this->actingAs($this->user)->get(route('app.dashboard'))->assertRedirect(route('subscription.blocked'));

        // Quitada a mais antiga (atrasada) pelo Bolix dela, o acesso volta.
        $pix = $this->actingAs($this->user)->postJson(route('subscription.efi.pix'))->assertOk()->json();
        $this->assertSame(['billet', $first->id], [$pix['method'], Payment::query()->find($pix['id'])->tenant_subscription_charge_id]);
        $this->efiCharges[$pix['payment_id']]['status'] = 'paid';
        $this->actingAs($this->user)->postJson(route('subscription.efi.refresh', $pix['id']))->assertOk();
        $this->actingAs($this->user)->getJson(route('subscription.check_status', ['payment' => $pix['id']]))->assertJson(['status' => 'active']);
        $this->actingAs($this->user)->get(route('app.dashboard'))->assertOk();
    }

    public function test_efi_unavailable_shows_friendly_message_without_broken_redirect(): void
    {
        SaasPaymentSetting::current()->forceFill(['efi_homologated_at' => null])->save();
        $this->expiresIn(-4);

        $this->actingAs($this->user)->get(route('app.dashboard'))->assertRedirect(route('subscription.blocked'));
        $this->actingAs($this->user)->get(route('subscription.blocked'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('checkout.methods', [])
                ->where('checkout.unavailable_message', fn ($message) => str_contains($message, 'indisponível')));
        $this->actingAs($this->user)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->plan->id])->assertUnprocessable();
    }

    // ---- Isolamento e usuários sem empresa --------------------------------------------

    public function test_payment_routes_are_isolated_between_tenants(): void
    {
        $other = $this->makeTenant('45.723.174/0001-10', 'outra@example.test', '2026-10-01');
        $otherUser = User::factory()->forTenant($other->id)->create();
        $mine = $this->actingAs($this->user)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->plan->id])->assertOk()->json();

        $this->actingAs($otherUser)->postJson(route('subscription.efi.refresh', $mine['id']))->assertNotFound();
        $this->actingAs($otherUser)->getJson(route('subscription.efi.status', $mine['id']))->assertNotFound();
        $this->actingAs($otherUser)->getJson(route('subscription.check_status', ['payment' => $mine['id']]))->assertJson(['status' => 'pending', 'payment_status' => null]);
    }

    public function test_user_without_company_is_sent_to_admin_without_errors(): void
    {
        $this->actingAs($this->root)->get(route('subscription.blocked'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->root)->get(route('subscription.show'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->root)->getJson(route('subscription.check_status'))->assertNotFound();
    }
}
