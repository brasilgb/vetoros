<?php

namespace Tests\Feature\App;

use App\Models\App\AccountReceivable;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    public function test_it_blocks_customer_deletion_when_customer_has_linked_orders(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->delete(route('app.customers.destroy', $customer));

        $response->assertSessionHas(
            'error',
            'Não é possível excluir este cliente porque existem ordens, vendas, agendamentos, contratos de manutenção ou equipamentos vinculados.'
        );

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
        ]);
    }

    public function test_it_stores_customer_with_non_numeric_street_number(): void
    {
        // "número" do endereço é validado como texto livre (CustomerRequest) porque
        // endereços brasileiros usam valores como "S/N", "123A" etc. A coluna do
        // banco já foi ajustada para VARCHAR; este teste evita regressão.
        $response = $this->post(route('app.customers.store'), [
            'name' => 'Cliente sem número',
            'cpfcnpj' => '98765432100',
            'phone' => '51999999999',
            'street' => 'Rua Teste',
            'number' => 'S/N',
        ]);

        $response->assertRedirect(route('app.customers.index'));
        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente sem número',
            'number' => 'S/N',
        ]);
    }

    public function test_it_updates_customer_even_when_payload_includes_equipments_relation_data(): void
    {
        // Reproduz o bug real: o form de edição é inicializado com o Customer inteiro,
        // então se a relação "equipments" acabar anexada ao objeto antes de ir pro
        // Inertia, o PUT manda esse array junto e quebrava o UPDATE (SQLSTATE 42S22,
        // "Unknown column 'equipments' in SET") porque não existe essa coluna.
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();

        $response = $this->put(route('app.customers.update', $customer), [
            'name' => 'Cliente Atualizado',
            'cpfcnpj' => '97755150097',
            'phone' => '51999999999',
            'whatsapp' => '51998931325',
            'equipments' => [
                ['id' => 1, 'customer_id' => $customer->id, 'brand' => 'ok', 'model' => 'dsdsd'],
            ],
        ]);

        $response->assertRedirect(route('app.customers.show', $customer));
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Cliente Atualizado',
        ]);
    }

    public function test_it_rejects_whatsapp_that_is_not_a_full_phone_number(): void
    {
        $response = $this->post(route('app.customers.store'), [
            'name' => 'Cliente Sem WhatsApp',
            'cpfcnpj' => '97755150097',
            'phone' => '51999999999',
            'whatsapp' => '0000000',
        ]);

        $response->assertSessionHasErrors('whatsapp');
        $this->assertDatabaseMissing('customers', ['name' => 'Cliente Sem WhatsApp']);
    }

    public function test_it_accepts_customer_without_whatsapp(): void
    {
        $response = $this->post(route('app.customers.store'), [
            'name' => 'Cliente Só Telefone',
            'cpfcnpj' => '97755150097',
            'phone' => '51999999999',
            'whatsapp' => null,
        ]);

        $response->assertSessionDoesntHaveErrors('whatsapp');
        $this->assertDatabaseHas('customers', ['name' => 'Cliente Só Telefone']);
    }

    public function test_show_exposes_equipments_as_top_level_prop_not_nested_in_customer(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();

        $response = $this->get(route('app.customers.show', $customer));

        $response->assertOk()->assertInertia(function ($page) {
            $page->has('equipments')->missing('customer.equipments');
        });
    }

    public function test_it_counts_pending_balance_from_open_receivables(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();

        AccountReceivable::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'source_type' => AccountReceivable::SOURCE_ORDER,
            'source_id' => 10,
            'description' => 'OS 10',
            'total_amount' => 300,
            'paid_amount' => 100,
            'balance_amount' => 200,
            'status' => AccountReceivable::STATUS_PARTIAL,
        ]);

        AccountReceivable::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'source_type' => AccountReceivable::SOURCE_SALE,
            'source_id' => 20,
            'description' => 'Venda 20',
            'total_amount' => 80,
            'paid_amount' => 80,
            'balance_amount' => 0,
            'status' => AccountReceivable::STATUS_PAID,
        ]);

        $response = $this->get(route('app.customers.index'));

        $response
            ->assertOk()
            ->assertViewHas('page.props.customers.data', function (array $customers) use ($customer) {
                $row = collect($customers)->firstWhere('id', $customer->id);

                return $row && (float) $row['pending_amount'] === 200.0;
            });
    }

    public function test_pending_filter_uses_open_receivables(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();

        AccountReceivable::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'source_type' => AccountReceivable::SOURCE_ORDER,
            'source_id' => 10,
            'description' => 'OS 10',
            'total_amount' => 500,
            'paid_amount' => 500,
            'balance_amount' => 0,
            'status' => AccountReceivable::STATUS_PAID,
        ]);

        $response = $this->get(route('app.customers.index', ['pending' => 1]));

        $response
            ->assertOk()
            ->assertViewHas('page.props.customers.data', function (array $customers) use ($customer) {
                return ! collect($customers)->pluck('id')->contains($customer->id);
            });
    }
}
