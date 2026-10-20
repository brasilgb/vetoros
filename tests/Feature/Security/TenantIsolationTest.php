<?php

namespace Tests\Feature\Security;

use App\Models\App\Budget;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantWriteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VETOROS-SECURITY-FIX-01 / SEC-01: nenhum usuário de empresa grava, move ou exclui
 * registros de outra empresa, mesmo enviando `tenant_id` na requisição.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $victim;

    private Tenant $attackerTenant;

    private User $attacker;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->victim = Tenant::factory()->create();
        $this->attackerTenant = Tenant::factory()->create();
        $this->attacker = User::factory()->create(['tenant_id' => $this->attackerTenant->id, 'roles' => User::ROLE_ADMIN]);
        $this->equipment = Equipment::withoutGlobalScopes()->create([
            'tenant_id' => $this->attackerTenant->id, 'equipment' => 'Notebook', 'equipment_number' => 1, 'chart' => 0,
        ]);
    }

    private function budgetPayload(array $overrides = []): array
    {
        return array_merge([
            'equipment_id' => $this->equipment->id,
            'model' => 'Dell',
            'service' => 'Troca de tela',
            'description' => 'Tela nova',
            'estimated_time' => '1 dia',
            'part_value' => '100.00',
            'labor_value' => '50.00',
            'total_value' => '150.00',
            'warranty' => '90 dias',
            'validity' => '7',
        ], $overrides);
    }

    public function test_budget_store_ignores_foreign_tenant_id(): void
    {
        $this->actingAs($this->attacker)
            ->post(route('app.budgets.store'), $this->budgetPayload(['tenant_id' => $this->victim->id, 'service' => 'probe']))
            ->assertRedirect(route('app.budgets.index'));

        $budget = Budget::withoutGlobalScopes()->where('service', 'probe')->firstOrFail();

        $this->assertSame($this->attackerTenant->id, (int) $budget->tenant_id);
        $this->assertSame(0, Budget::withoutGlobalScopes()->where('tenant_id', $this->victim->id)->count());
        $this->assertSame('100.00', number_format((float) $budget->part_value, 2, '.', ''));
    }

    public function test_budget_update_cannot_move_record_to_another_tenant(): void
    {
        $own = Budget::factory()->create(['tenant_id' => $this->attackerTenant->id, 'equipment_id' => $this->equipment->id]);

        $this->actingAs($this->attacker)
            ->patch(route('app.budgets.update', $own->id), $this->budgetPayload(['tenant_id' => $this->victim->id, 'budget_number' => 999]))
            ->assertRedirect(route('app.budgets.index'));

        $own = Budget::withoutGlobalScopes()->findOrFail($own->id);
        $this->assertSame($this->attackerTenant->id, (int) $own->tenant_id);
        $this->assertNotSame(999, (int) $own->budget_number);
    }

    public function test_budget_rejects_equipment_of_another_tenant(): void
    {
        $foreignEquipment = Equipment::withoutGlobalScopes()->create([
            'tenant_id' => $this->victim->id, 'equipment' => 'Alheio', 'equipment_number' => 1, 'chart' => 0,
        ]);

        $this->actingAs($this->attacker)
            ->post(route('app.budgets.store'), $this->budgetPayload(['equipment_id' => $foreignEquipment->id, 'service' => 'probe']))
            ->assertSessionHasErrors('equipment_id');

        $this->assertFalse(Budget::withoutGlobalScopes()->where('service', 'probe')->exists());
    }

    public function test_budget_of_another_tenant_is_not_reachable(): void
    {
        $victimEquipment = Equipment::withoutGlobalScopes()->create([
            'tenant_id' => $this->victim->id, 'equipment' => 'Da vítima', 'equipment_number' => 1, 'chart' => 0,
        ]);
        $foreign = Budget::factory()->create(['tenant_id' => $this->victim->id, 'equipment_id' => $victimEquipment->id, 'model' => 'Original']);

        $this->actingAs($this->attacker)->get(route('app.budgets.show', $foreign->id))->assertNotFound();
        $this->actingAs($this->attacker)->patch(route('app.budgets.update', $foreign->id), $this->budgetPayload());
        $this->actingAs($this->attacker)->delete(route('app.budgets.destroy', $foreign->id));

        $foreign = Budget::withoutGlobalScopes()->find($foreign->id);
        $this->assertNotNull($foreign);
        $this->assertSame('Original', $foreign->model);
    }

    public function test_technician_cannot_manage_budgets(): void
    {
        $technician = User::factory()->create(['tenant_id' => $this->attackerTenant->id, 'roles' => User::ROLE_TECHNICIAN]);
        $budget = Budget::factory()->create(['tenant_id' => $this->attackerTenant->id, 'equipment_id' => $this->equipment->id]);

        $this->actingAs($technician)->delete(route('app.budgets.destroy', $budget->id));
        $this->actingAs($technician)->post(route('app.budgets.store'), $this->budgetPayload(['service' => 'tech-probe']));

        $this->assertNotNull(Budget::withoutGlobalScopes()->find($budget->id));
        $this->assertFalse(Budget::withoutGlobalScopes()->where('service', 'tech-probe')->exists());
    }

    public function test_tenantable_forces_current_tenant_on_create_and_blocks_tenant_change(): void
    {
        $this->actingAs($this->attacker);

        // Mesmo ciclo do middleware EnforceTenantWrites (grupos web e api).
        TenantWriteGuard::enforce(function (): void {
            $customer = Customer::create(['tenant_id' => $this->victim->id, 'name' => 'Forjado', 'customer_number' => 1]);
            $this->assertSame($this->attackerTenant->id, (int) Customer::withoutGlobalScopes()->find($customer->id)->tenant_id);

            $customer->update(['tenant_id' => $this->victim->id]);
            $this->assertSame($this->attackerTenant->id, (int) Customer::withoutGlobalScopes()->find($customer->id)->tenant_id);
        });

        $this->assertFalse(TenantWriteGuard::active());
    }

    public function test_records_without_authenticated_tenant_keep_explicit_tenant(): void
    {
        // Jobs, webhooks e comandos não têm usuário: o tenant explícito continua valendo.
        $customer = Customer::create(['tenant_id' => $this->victim->id, 'name' => 'Sistema', 'customer_number' => 1]);

        $this->assertSame($this->victim->id, (int) $customer->tenant_id);
    }
}
