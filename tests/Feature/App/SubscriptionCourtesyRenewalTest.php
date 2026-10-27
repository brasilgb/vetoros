<?php

namespace Tests\Feature\App;

use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesEfi;
use Tests\TestCase;

/**
 * VETOROS-EFI-RENEWAL-02.4: empresa com cortesia expirada e bloqueada precisa ver os planos para renovar.
 * Os planos só aparecem quando têm preço e período; sem isso a tela de bloqueio não oferece renovação.
 */
class SubscriptionCourtesyRenewalTest extends TestCase
{
    use FakesEfi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-10-27 10:00:00');
        // As migrations já cadastram o catálogo comercial: cada teste monta só os planos que precisa.
        Period::query()->delete();
        Plan::query()->delete();
        $this->configureEfi(['pix', 'billet', 'card']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function courtesyExpiredAdmin(): User
    {
        $courtesy = Plan::factory()->create(['name' => 'Cortesia', 'slug' => 'cortesia', 'value' => 0, 'billing_months' => 12]);
        $tenant = Tenant::factory()->create([
            'plan_id' => $courtesy->id, 'company' => 'Sigma Operating Software', 'name' => 'Administrador',
            'expires_at' => '2026-10-23', 'subscription_status' => 'active',
        ]);

        return User::factory()->forTenant($tenant->id)->create();
    }

    public function test_courtesy_expired_tenant_sees_priced_plan_to_renew(): void
    {
        $mensal = Plan::factory()->create(['name' => 'Mensal', 'slug' => 'mensal', 'value' => 89.90, 'billing_months' => 1]);
        Period::factory()->create(['plan_id' => $mensal->id, 'name' => 'Mensal', 'interval' => 'month', 'interval_count' => 1, 'price' => 89.90]);

        $this->actingAs($this->courtesyExpiredAdmin())
            ->get(route('subscription.blocked'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Subscription/Blocked')
                ->where('status.state', 'blocked')
                ->where('courtesy_expired', true)
                ->where('can_manage_subscription', true)
                ->has('plans', 1)
                ->where('plans.0.name', 'Mensal')
                ->where('plans.0.value', '89.90')
                ->where('checkout.methods', ['pix', 'billet', 'card'])
            );
    }

    public function test_root_and_administrator_can_proceed_to_payment_but_technician_cannot(): void
    {
        $mensal = Plan::factory()->create(['name' => 'Mensal', 'slug' => 'mensal', 'value' => 89.90, 'billing_months' => 1]);
        Period::factory()->create(['plan_id' => $mensal->id, 'name' => 'Mensal', 'interval' => 'month', 'interval_count' => 1, 'price' => 89.90]);
        $admin = $this->courtesyExpiredAdmin();
        $tenantId = $admin->tenant_id;

        foreach ([User::ROLE_ROOT_APP, User::ROLE_ROOT_SYSTEM, User::ROLE_ADMIN] as $role) {
            $user = User::factory()->forTenant($tenantId)->create(['roles' => $role]);

            $this->actingAs($user)->get(route('subscription.blocked'))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('can_manage_subscription', true));
        }

        $technician = User::factory()->forTenant($tenantId)->create(['roles' => User::ROLE_TECHNICIAN]);

        $this->actingAs($technician)->get(route('subscription.blocked'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_manage_subscription', false));
    }

    public function test_unpriced_public_plans_leave_courtesy_expired_tenant_without_renewal_options(): void
    {
        // Estado observado em produção (2026-10-27): planos públicos cadastrados com valor 0,00 e sem período.
        Plan::factory()->create(['name' => 'Mensal', 'slug' => 'mensal', 'value' => 0, 'billing_months' => 1]);

        $this->actingAs($this->courtesyExpiredAdmin())
            ->get(route('subscription.blocked'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Subscription/Blocked')
                ->where('status.state', 'blocked')
                ->has('plans', 0)
            );
    }
}
