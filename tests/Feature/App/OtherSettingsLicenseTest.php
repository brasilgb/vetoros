<?php

namespace Tests\Feature\App;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** A tela Sistema e módulos lia auth.user.tenant.plan.name, que não é carregado para administradores. */
class OtherSettingsLicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_license_plan_name_is_provided_for_administrator(): void
    {
        $this->withoutVite();
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_ADMIN]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('app.other-settings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('app/others/index')
                ->where('licensePlanName', $tenant->plan->name));
    }

    public function test_license_plan_name_is_null_when_tenant_has_no_plan(): void
    {
        $this->withoutVite();
        $tenant = Tenant::factory()->create(['plan_id' => null]);
        $user = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_ADMIN]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('app.other-settings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('licensePlanName', null));
    }
}
