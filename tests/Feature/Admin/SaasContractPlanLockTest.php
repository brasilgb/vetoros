<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\Admin\TenantCommercialContract;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commercial\SaasContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesEfi;
use Tests\TestCase;

/** VETOR-SAAS-CONTRATO-02: trava de planos, data efetiva da conversão e recorrência com dia contratual. */
class SaasContractPlanLockTest extends TestCase
{
    use FakesEfi, RefreshDatabase;

    private User $root;

    private Plan $plan;

    private Plan $otherPlan;

    private Period $period;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
        $this->plan = Plan::factory()->create(['name' => 'Mensal', 'slug' => 'mensal', 'value' => 89.90, 'billing_months' => 1]);
        $this->period = Period::factory()->create(['plan_id' => $this->plan->id, 'name' => 'Mensal', 'interval' => 'month', 'interval_count' => 1, 'price' => 89.90]);
        $this->otherPlan = Plan::factory()->create(['name' => 'Anual', 'slug' => 'anual', 'value' => 899.00, 'billing_months' => 12]);
        Period::factory()->create(['plan_id' => $this->otherPlan->id, 'name' => 'Anual', 'interval' => 'year', 'interval_count' => 1, 'price' => 899.00]);
        $this->tenant = Tenant::factory()->create([
            'plan_id' => $this->plan->id, 'period_id' => $this->period->id, 'company' => 'Cliente Contratante LTDA', 'name' => 'Maria',
            'cnpj' => '11.444.777/0001-61', 'email' => 'cliente@example.test', 'expires_at' => null, 'subscription_status' => 'active',
        ]);

        // Efí (único gateway) homologada só com Pix, no simulador controlado (VETOR-SAAS-EFI-03).
        $this->configureEfi(['pix']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function activate(array $overrides = [])
    {
        return $this->actingAs($this->root)->put(route('admin.tenants.commercial.update', $this->tenant), [
            'custom_contract' => true,
            'saas_invoice_enabled' => false,
            'amount' => '1200.00',
            'billing_months' => 1,
            'start_date' => '2026-10-01',
            'next_due_date' => '2026-10-20',
            ...$overrides,
        ]);
    }

    private function revert(array $overrides = [])
    {
        return $this->actingAs($this->root)->post(route('admin.tenants.commercial.revert', $this->tenant), [
            'plan_id' => $this->otherPlan->id,
            'effective_date' => '2026-11-20',
            'confirm' => true,
            'password' => 'password',
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

    // ---- Recorrência com dia contratual (29, 30 e 31) ------------------------

    /** @return array<string, array{int, string, list<string>}> */
    public static function anchoredCycles(): array
    {
        return [
            'mensal dia 31' => [1, '2027-01-31', ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31']],
            'mensal dia 30' => [1, '2027-01-30', ['2027-01-30', '2027-02-28', '2027-03-30', '2027-04-30', '2027-05-30']],
            'mensal dia 29 (bissexto)' => [1, '2028-01-29', ['2028-01-29', '2028-02-29', '2028-03-29', '2028-04-29', '2028-05-29']],
            'semestral dia 31' => [6, '2026-08-31', ['2026-08-31', '2027-02-28', '2027-08-31', '2028-02-29', '2028-08-31']],
            'anual dia 29/02' => [12, '2028-02-29', ['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29']],
        ];
    }

    #[DataProvider('anchoredCycles')]
    public function test_due_dates_keep_the_contractual_day(int $months, string $firstDue, array $expected): void
    {
        $contract = new TenantCommercialContract(['billing_months' => $months, 'anchor_day' => Carbon::parse($firstDue)->day]);

        $due = Carbon::parse($firstDue);
        $dates = [$due->toDateString()];
        for ($i = 1; $i < count($expected); $i++) {
            $due = $contract->nextDueAfter($due);
            $dates[] = $due->toDateString();
        }

        $this->assertSame($expected, $dates);
        // O ciclo termina na véspera do vencimento seguinte, sem buraco nem sobreposição.
        [$start, $end] = $contract->cycleFor(Carbon::parse($expected[0]));
        $this->assertSame(Carbon::parse($expected[1])->subDay()->toDateString(), $end->toDateString());
    }

    public function test_generated_charges_keep_day_31_through_short_months(): void
    {
        Carbon::setTestNow('2027-01-20 10:00:00');
        $this->activate(['start_date' => '2027-01-01', 'next_due_date' => '2027-01-31'])->assertSessionHasNoErrors();
        $this->assertSame(31, $this->tenant->refresh()->commercialContract->anchor_day);

        // Recuperação após parada: a mesma execução gera os ciclos atrasados, um por competência.
        Carbon::setTestNow('2027-03-28 10:00:00');
        $this->process();
        $this->process();

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $this->charges()->map(fn ($c) => $c->due_date->toDateString())->all());
        $this->assertSame(['2027-02-27', '2027-03-30', '2027-04-29'], $this->charges()->map(fn ($c) => $c->cycle_end->toDateString())->all());
        $this->assertSame('2027-04-30', $this->tenant->commercialContract->refresh()->next_due_date->toDateString());
    }

    public function test_periodicity_change_applies_from_next_cycle(): void
    {
        $this->activate();
        $this->process();

        $this->activate(['billing_months' => 6, 'amount' => '6000.00', 'next_due_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $first = $this->charges()->sole();
        $this->assertSame(['1200.00', '2026-11-19'], [(string) $first->amount, $first->cycle_end->toDateString()]);

        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->process();

        $second = $this->charges()->last();
        $this->assertSame(['6000.00', '2026-11-20', '2027-05-19'], [(string) $second->amount, $second->due_date->toDateString(), $second->cycle_end->toDateString()]);
    }

    // ---- Trava de planos ------------------------------------------------------

    public function test_admin_cannot_change_plan_of_custom_contract_tenant(): void
    {
        $this->activate()->assertSessionHasNoErrors();
        $payload = [...$this->tenant->refresh()->only(['name', 'company', 'cnpj', 'email', 'phone', 'status'])];

        $this->actingAs($this->root)->patch(route('admin.tenants.update', $this->tenant), [...$payload, 'plan_id' => $this->otherPlan->id])
            ->assertSessionHasErrors('plan_id');
        $this->assertSame($this->plan->id, $this->tenant->refresh()->plan_id);

        // Mesmo plano: o restante do cadastro salva e o período (base de funcionalidades) é preservado.
        $this->actingAs($this->root)->patch(route('admin.tenants.update', $this->tenant), [...$payload, 'phone' => '51988887777', 'plan_id' => $this->plan->id])
            ->assertSessionHasNoErrors();
        $this->tenant->refresh();
        $this->assertSame([$this->plan->id, $this->period->id, '51988887777'], [$this->tenant->plan_id, $this->tenant->period_id, $this->tenant->phone]);

        $this->actingAs($this->root)->get(route('admin.tenants.show', $this->tenant))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('commercial.billing_mode', 'custom'));
    }

    public function test_tenant_area_hides_plans_and_refuses_plan_payment(): void
    {
        $this->activate();
        $this->process();
        $user = User::factory()->forTenant($this->tenant->id)->create();

        $this->actingAs($user)->get(route('subscription.show'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('custom_contract', true)->where('plans', [])->has('charge'));

        $this->actingAs($user)->postJson(route('subscription.efi.pix'), ['plan_id' => $this->otherPlan->id])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
        $this->assertSame(0, $this->cobRequests());
        $this->assertSame([Tenant::BILLING_CUSTOM, $this->plan->id], [$this->tenant->refresh()->billing_mode, $this->tenant->plan_id]);
    }

    public function test_plan_tenant_still_sees_plans(): void
    {
        $this->tenant->update(['expires_at' => now()->subDays(10)]);
        $user = User::factory()->forTenant($this->tenant->id)->create();

        $this->actingAs($user)->get(route('subscription.blocked'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('custom_contract', false)->where('plans', fn ($plans) => count($plans) > 0));
    }

    public function test_same_open_charge_reuses_valid_pix(): void
    {
        $this->activate();
        $this->process();
        $user = User::factory()->forTenant($this->tenant->id)->create();

        $first = $this->actingAs($user)->postJson(route('subscription.efi.pix'))->assertOk()->json();
        $second = $this->actingAs($user)->postJson(route('subscription.efi.pix'))->assertOk()->json();

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['qr_code_copy_paste'], $second['qr_code_copy_paste']);
        $this->assertSame(1, $this->cobRequests());
    }

    public function test_second_approved_pix_for_paid_charge_is_not_applied_and_is_audited(): void
    {
        $this->activate();
        $this->process();
        $charge = $this->charges()->sole();
        $user = User::factory()->forTenant($this->tenant->id)->create();
        $first = Payment::query()->findOrFail($this->actingAs($user)->postJson(route('subscription.efi.pix'))->json('id'));
        // Um segundo Pix da mesma competência (gerado fora do reaproveitamento, ex.: corrida entre telas).
        $second = Payment::query()->create([
            'tenant_id' => $this->tenant->id, 'tenant_subscription_charge_id' => $charge->id, 'gateway' => 'efi', 'method' => 'pix',
            'payment_id' => 'VOSTXIDSEGUNDO000000000000001', 'reference' => 'VOSTXIDSEGUNDO000000000000001', 'amount' => '1200.00',
            'status' => 'pending', 'idempotency_key' => 'efi-segundo', 'expires_at' => now()->addDay(),
        ]);
        $this->cobs[$second->payment_id] = ['txid' => $second->payment_id, 'status' => 'ATIVA', 'pix' => []];

        $this->payCob($first->payment_id, '1200.00', 'E2EPRIMEIRO');
        $this->pixWebhook($first->payment_id, 'E2EPRIMEIRO')->assertOk();
        $this->payCob($second->payment_id, '1200.00', 'E2ESEGUNDO');
        $this->pixWebhook($second->payment_id, 'E2ESEGUNDO')->assertOk();

        $charge->refresh();
        $this->assertSame('paid', $charge->status);
        $this->assertSame($first->id, (int) $charge->payment_id);
        $this->assertSame(1, FiscalAdminAudit::query()->where('action', 'commercial.charge_paid')->count());
        $this->assertSame($second->payment_id, FiscalAdminAudit::query()->where('action', 'saas_payment.divergence')->sole()->data['payment_id']);
    }

    // ---- Volta ao plano com data efetiva --------------------------------------

    public function test_revert_requires_effective_date_not_in_the_past(): void
    {
        $this->activate();

        $this->revert(['effective_date' => null])->assertSessionHasErrors('effective_date');
        $this->revert(['effective_date' => '2026-10-14'])->assertSessionHasErrors('effective_date');
        $this->revert(['confirm' => false])->assertSessionHasErrors('confirm');

        $this->assertSame(Tenant::BILLING_CUSTOM, $this->tenant->refresh()->billing_mode);
        $this->assertNull($this->tenant->commercialContract->revert_scheduled_for);
    }

    public function test_scheduled_revert_keeps_contract_until_effective_date_without_overlap(): void
    {
        $this->activate();
        $this->process();
        app(SaasContractService::class)->registerPayment($this->charges()->sole(), '1200', 'manual');

        $this->revert()->assertSessionHasNoErrors();

        // Até a data efetiva: contrato ativo, plano (funcionalidades) intacto.
        $this->tenant->refresh();
        $contract = $this->tenant->commercialContract;
        $this->assertSame([Tenant::BILLING_CUSTOM, $this->plan->id, true], [$this->tenant->billing_mode, $this->tenant->plan_id, $contract->active]);
        $this->assertSame('2026-11-20', $contract->revert_scheduled_for->toDateString());
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'commercial.revert_scheduled', 'user_id' => $this->root->id]);

        // O ciclo que começaria na data efetiva não é cobrado.
        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->process();
        $this->assertCount(1, $this->charges());

        Carbon::setTestNow('2026-11-20 07:00:00');
        $this->process();

        $this->tenant->refresh();
        $this->assertSame([Tenant::BILLING_PLAN, $this->otherPlan->id], [$this->tenant->billing_mode, $this->tenant->plan_id]);
        $this->assertFalse($this->tenant->commercialContract->active);
        $this->assertNull($this->tenant->commercialContract->revert_scheduled_for);
        $this->assertSame('2026-11-20', $this->tenant->expires_at->toDateString());
        $this->assertSame('paid', $this->charges()->sole()->status);

        Carbon::setTestNow('2026-12-20 07:00:00');
        $this->process();
        $this->assertCount(1, $this->charges());
    }

    public function test_scheduled_revert_can_be_cancelled_only_by_root(): void
    {
        $this->activate();
        $this->revert()->assertSessionHasNoErrors();

        $tenantAdmin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->actingAs($tenantAdmin)->delete(route('admin.tenants.commercial.revert.cancel', $this->tenant))->assertRedirect();
        $this->assertNotNull($this->tenant->commercialContract->refresh()->revert_scheduled_for);

        $this->actingAs($this->root)->delete(route('admin.tenants.commercial.revert.cancel', $this->tenant))->assertSessionHasNoErrors();
        $this->assertNull($this->tenant->commercialContract->refresh()->revert_scheduled_for);
        $this->assertDatabaseHas('fiscal_admin_audits', ['action' => 'commercial.revert_cancelled']);

        // Desagendado, o ciclo seguinte volta a ser cobrado normalmente.
        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->process();
        $this->assertSame(['2026-10-20', '2026-11-20'], $this->charges()->map(fn ($c) => $c->due_date->toDateString())->all());
    }

    public function test_revert_effective_today_applies_immediately(): void
    {
        $this->activate();

        $this->revert(['effective_date' => '2026-10-15'])->assertSessionHasNoErrors();

        $this->tenant->refresh();
        $this->assertSame([Tenant::BILLING_PLAN, $this->otherPlan->id], [$this->tenant->billing_mode, $this->tenant->plan_id]);
        $this->assertFalse($this->tenant->commercialContract->active);
    }
}
