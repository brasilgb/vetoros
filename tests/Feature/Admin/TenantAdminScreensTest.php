<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Feature;
use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** VETOR-UX-ADMIN-03: telas de Empresas do RootAdmin (listagem e edição em abas), sem mudar regras. */
class TenantAdminScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP]);
    }

    public function test_listing_keeps_search_filters_and_the_fields_shown_in_the_table(): void
    {
        $plan = Plan::factory()->create(['name' => 'Mensal']);
        Tenant::factory()->create(['company' => 'Alfa Assistência', 'plan_id' => $plan->id, 'expires_at' => now()->addDays(10)]);
        Tenant::factory()->create(['company' => 'Beta Reparos', 'plan_id' => $plan->id, 'expires_at' => now()->subDays(10)]);

        $this->actingAs($this->root)->get(route('admin.tenants.index', ['search' => 'Alfa']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/tenants/index')
                ->where('filters.search', 'Alfa')
                ->has('tenants.data', 1)
                ->has('tenants.data.0', fn (AssertableInertia $row) => $row
                    ->where('company', 'Alfa Assistência')
                    ->has('cnpj')
                    ->has('billing_mode')
                    ->where('plan.name', 'Mensal')
                    ->where('computed_subscription_bucket', 'active')
                    ->has('computed_subscription_label')
                    ->has('status_label')
                    ->etc()));

        $this->get(route('admin.tenants.index', ['subscription_filter' => 'blocked']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.result_count', 1)->where('tenants.data.0.company', 'Beta Reparos'));
    }

    public function test_edit_screen_exposes_plan_features_and_client_modules_read_only(): void
    {
        $plan = Plan::factory()->create(['name' => 'Completo']);
        $period = Period::factory()->create(['plan_id' => $plan->id, 'name' => 'Mensal']);
        Feature::factory()->create(['period_id' => $period->id, 'name' => 'Ordens de serviço ilimitadas']);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        // Sem configuração de módulos: a tela não cria registro em nome do cliente.
        $this->actingAs($this->root)->get(route('admin.tenants.show', $tenant))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/tenants/edit-tenant')
                ->where('modules', null)
                ->where('tenant.plan.periods.0.features.0.name', 'Ordens de serviço ilimitadas')
                ->has('commercial'));
        $this->assertSame(0, Other::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        Other::factory()->create(['tenant_id' => $tenant->id, 'enableparts' => true, 'enablesales' => false, 'enable_purchases' => true, 'enable_finance' => false]);

        $this->get(route('admin.tenants.show', $tenant))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('modules', [
                'parts' => true, 'sales' => false, 'purchases' => true, 'finance' => false,
            ]));
    }

    public function test_tenant_users_cannot_open_the_company_screens(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_ADMIN]);

        foreach ([route('admin.tenants.index'), route('admin.tenants.create'), route('admin.tenants.show', $tenant)] as $url) {
            $response = $this->actingAs($admin)->get($url);
            $this->assertNotSame(200, $response->getStatusCode(), $url);
        }
    }
}
