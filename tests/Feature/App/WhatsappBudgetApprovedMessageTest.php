<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderBudgetItem;
use App\Models\App\OrderItem;
use App\Models\App\WhatsappMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderWhatsappBudgetDetails;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappBudgetApprovedMessageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create();
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    /**
     * OS aprovada com uma versão de orçamento aprovada: 2 peças de estoque, 1 serviço.
     *
     * @return array{0: Order, 1: OrderBudget}
     */
    private function approvedOrder(): array
    {
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::BUDGET_APPROVED,
        ]);

        $budget = OrderBudget::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'version' => 1,
            'status' => OrderBudget::STATUS_APPROVED,
            'description' => 'Troca de bomba d’água',
            'quoted_amount' => 500,
            'subtotal_services' => 90,
            'subtotal_parts' => 410,
            'manual_parts_value' => 0,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total_amount' => 500,
            'approved_at' => now(),
        ]);

        $order->forceFill(['approved_budget_id' => $budget->id, 'service_cost' => 500])->save();

        $items = [
            ['item_type' => OrderItem::TYPE_PRODUCT, 'description' => 'Bomba d’água', 'quantity' => 1, 'total' => 320, 'sort' => 1],
            ['item_type' => OrderItem::TYPE_SERVICE, 'description' => 'Mão de obra', 'quantity' => 1, 'total' => 90, 'sort' => 0],
            ['item_type' => OrderItem::TYPE_PRODUCT, 'description' => 'Correia', 'quantity' => 2, 'total' => 90, 'sort' => 2],
        ];

        foreach ($items as $item) {
            OrderBudgetItem::create([
                'tenant_id' => $this->tenant->id,
                'order_budget_id' => $budget->id,
                'item_type' => $item['item_type'],
                'source_type' => $item['item_type'] === OrderItem::TYPE_PRODUCT ? OrderItem::SOURCE_PART : OrderItem::SOURCE_ORDER_SERVICE,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['total'] / $item['quantity'],
                'total_price' => $item['total'],
                'sort_order' => $item['sort'],
            ]);
        }

        return [$order->fresh(), $budget];
    }

    public function test_budget_details_come_from_the_approved_version_and_only_parts(): void
    {
        [$order] = $this->approvedOrder();

        $details = app(OrderWhatsappBudgetDetails::class)->forOrder($order);

        $this->assertSame('Troca de bomba d’água', $details['description']);
        $this->assertSame(410.0, $details['parts_value']);
        $this->assertSame(
            [['quantity' => 1.0, 'description' => 'Bomba d’água', 'total' => 320.0], ['quantity' => 2.0, 'description' => 'Correia', 'total' => 90.0]],
            $details['parts'],
        );
    }

    public function test_budget_details_are_null_when_the_order_has_no_budget(): void
    {
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertNull(app(OrderWhatsappBudgetDetails::class)->forOrder($order));
    }

    public function test_budget_approved_template_is_saved_per_tenant(): void
    {
        $other = Tenant::factory()->create();
        $otherSettings = WhatsappMessage::withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'budgetapproved' => 'modelo do outro tenant',
        ]);

        $settings = WhatsappMessage::create([
            'tenant_id' => $this->tenant->id,
            'budgetapproved' => 'modelo antigo',
        ]);

        $this->patch(route('app.whatsapp-message.update', $settings), [
            'budgetapproved' => 'Obrigado pela aprovação, {{ cliente }}! Peças: {{ peças }}',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('whatsapp_messages', [
            'id' => $settings->id,
            'tenant_id' => $this->tenant->id,
            'budgetapproved' => 'Obrigado pela aprovação, {{ cliente }}! Peças: {{ peças }}',
        ]);
        $this->assertDatabaseHas('whatsapp_messages', [
            'id' => $otherSettings->id,
            'budgetapproved' => 'modelo do outro tenant',
        ]);
    }

    public function test_customer_select_offers_the_approved_budget_option_with_its_data(): void
    {
        $this->approvedOrder();

        $this->get(route('app.customers.index'))
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.whatsapp_options.0.context', 'budget_approved')
                ->where('customers.data.0.whatsapp_options.0.status', OrderStatus::BUDGET_APPROVED)
                ->where('customers.data.0.whatsapp_options.0.budget.parts_value', 410)
                ->where('customers.data.0.whatsapp_options.0.budget.description', 'Troca de bomba d’água')
            );
    }

    public function test_orders_list_exposes_the_budget_details_for_the_whatsapp_button(): void
    {
        [$order] = $this->approvedOrder();

        $this->get(route('app.orders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.whatsapp_budget.parts_value', 410)
                ->where('orders.data.0.whatsapp_budget.parts.0.description', 'Bomba d’água')
            );
    }
}
