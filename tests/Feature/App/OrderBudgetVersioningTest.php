<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderBudgetItem;
use App\Models\App\OrderEvent;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderActor;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class OrderBudgetVersioningTest extends TestCase
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
        Other::factory()->forTenant($this->tenant->id)->create(['enable_finance' => true]);
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create(['email' => null]);
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    public function test_v1_is_created_as_draft_and_updated_in_place_before_sending(): void
    {
        $order = $this->order();

        $this->save($order, ['budget_description' => 'Troca de tela', 'budget_value' => '300,00'])->assertSessionHasNoErrors();
        $this->save($order->fresh(), ['budget_description' => 'Troca de tela original', 'budget_value' => '350,00'])->assertSessionHasNoErrors();

        $budget = OrderBudget::query()->where('order_id', $order->id)->sole();
        $this->assertSame(1, (int) $budget->version);
        $this->assertSame(OrderBudget::STATUS_DRAFT, $budget->status);
        $this->assertSame('Troca de tela original', $budget->description);
        $this->assertMoney(350, $budget->quoted_amount);
        $this->assertNull($budget->sent_at);
        $this->assertSame(1, OrderEvent::query()->where('order_id', $order->id)->where('event_type', OrderEvent::TYPE_BUDGET_CREATED)->count());
    }

    public function test_status_budget_generated_sends_the_version(): void
    {
        $order = $this->order();

        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->generate($order, '300,00');
        Carbon::setTestNow();

        $budget = OrderBudget::query()->where('order_id', $order->id)->sole();
        $this->assertSame(OrderBudget::STATUS_SENT, $budget->status);
        $this->assertSame('2026-10-09 10:00:00', $budget->sent_at->toDateTimeString());
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id, 'event_type' => OrderEvent::TYPE_BUDGET_SENT, 'actor_type' => OrderActor::USER,
        ]);
    }

    public function test_change_after_sending_creates_v2_and_freezes_v1(): void
    {
        $order = $this->order();
        $this->generate($order, '300,00');

        $this->save($order->fresh(), ['budget_description' => 'Troca de tela e bateria', 'budget_value' => '420,00'])->assertSessionHasNoErrors();

        $versions = OrderBudget::query()->where('order_id', $order->id)->orderBy('version')->get();
        $this->assertCount(2, $versions);
        $this->assertSame(OrderBudget::STATUS_SUPERSEDED, $versions[0]->status);
        $this->assertMoney(300, $versions[0]->quoted_amount);
        $this->assertSame('Troca de tela', $versions[0]->description);
        $this->assertNotNull($versions[0]->superseded_at);
        // A OS segue em "Orçamento Gerado": a v2 já está diante do cliente.
        $this->assertSame(OrderBudget::STATUS_SENT, $versions[1]->status);
        $this->assertMoney(420, $versions[1]->quoted_amount);

        $this->expectException(LogicException::class);
        $versions[0]->forceFill(['quoted_amount' => 1])->save();
    }

    public function test_old_version_keeps_item_prices_after_part_price_changes(): void
    {
        $part = Part::factory()->forTenant($this->tenant->id)->create(['cost_price' => 40, 'sale_price' => 100, 'quantity' => 5]);
        $order = $this->order();
        $this->generate($order, '100,00', ['allparts' => [['part_id' => $part->id, 'quantity' => 1]]]);

        $part->forceFill(['sale_price' => 180, 'cost_price' => 90])->save();
        $this->save($order->fresh(), ['budget_description' => 'Troca de tela', 'budget_value' => '190,00', 'allparts' => [['part_id' => $part->id, 'quantity' => 1]]]);

        $v1 = OrderBudget::query()->where('order_id', $order->id)->where('version', 1)->sole();
        $item = OrderBudgetItem::query()->where('order_budget_id', $v1->id)->where('source_id', $part->id)->sole();
        $this->assertMoney(100, $item->unit_price);
        $this->assertMoney(40, $item->unit_cost);
    }

    public function test_public_approval_references_the_current_version(): void
    {
        $order = $this->order();
        $this->generate($order, '300,00');
        $this->save($order->fresh(), ['budget_description' => 'Troca de tela', 'budget_value' => '280,00']);
        Auth::logout();

        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED, 'budget_version' => 2])
            ->assertSessionHas('success');

        $v2 = OrderBudget::query()->where('order_id', $order->id)->where('version', 2)->sole();
        $this->assertSame(OrderBudget::STATUS_APPROVED, $v2->status);
        $this->assertSame(OrderActor::CUSTOMER, $v2->approved_by_type);
        $this->assertSame('public_tracking', $v2->response_channel);
        $this->assertNotNull($v2->approved_at);
        $this->assertSame($v2->id, (int) $order->fresh()->approved_budget_id);
        $this->assertSame(OrderStatus::BUDGET_APPROVED, (int) $order->fresh()->service_status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => OrderEvent::TYPE_BUDGET_APPROVED, 'actor_type' => OrderActor::CUSTOMER]);
    }

    public function test_public_rejection_references_version_and_reason(): void
    {
        $order = $this->order();
        $this->generate($order, '300,00');
        Auth::logout();

        $this->post(route('orders.budget.status', $order->tracking_token), [
            'status' => OrderStatus::BUDGET_REJECTED, 'budget_version' => 1, 'rejection_reason' => 'Achei caro',
        ])->assertSessionHas('success');

        $v1 = OrderBudget::query()->where('order_id', $order->id)->sole();
        $this->assertSame(OrderBudget::STATUS_REJECTED, $v1->status);
        $this->assertSame('Achei caro', $v1->rejection_reason);
        $this->assertNotNull($v1->rejected_at);
        $this->assertNull($order->fresh()->approved_budget_id);
    }

    public function test_superseded_or_missing_version_cannot_be_approved(): void
    {
        $order = $this->order();
        $this->generate($order, '300,00');
        $this->save($order->fresh(), ['budget_description' => 'Troca de tela', 'budget_value' => '280,00']);
        Auth::logout();

        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED, 'budget_version' => 1])
            ->assertSessionHasErrors('status');
        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, OrderBudget::query()->where('order_id', $order->id)->where('status', OrderBudget::STATUS_APPROVED)->count());
        $this->assertSame(OrderStatus::BUDGET_GENERATED, (int) $order->fresh()->service_status);
    }

    public function test_second_response_after_approval_is_refused(): void
    {
        $order = $this->order();
        $this->generate($order, '300,00');
        Auth::logout();

        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED, 'budget_version' => 1]);
        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_REJECTED, 'budget_version' => 1])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderBudget::STATUS_APPROVED, OrderBudget::query()->where('order_id', $order->id)->sole()->status);
    }

    public function test_expired_version_cannot_be_approved_and_expiration_is_persisted(): void
    {
        $order = $this->order();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->generate($order, '300,00', ['budget_valid_until' => '2026-10-05']);

        $budget = OrderBudget::query()->where('order_id', $order->id)->sole();
        $this->assertSame(OrderBudget::STATUS_SENT, $budget->effective_status);

        Carbon::setTestNow('2026-10-06 08:00:00');
        $this->assertSame(OrderBudget::STATUS_EXPIRED, $budget->fresh()->effective_status);
        $this->assertSame(1, OrderBudget::query()->effectivelyExpired()->count());
        Auth::logout();

        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED, 'budget_version' => 1])
            ->assertSessionHasErrors('status');
        Carbon::setTestNow();

        // A recusa da aprovação não desfaz o registro do vencimento.
        $budget->refresh();
        $this->assertSame(OrderBudget::STATUS_EXPIRED, $budget->status);
        $this->assertSame('2026-10-05 23:59:59', $budget->expired_at->toDateTimeString());
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => OrderEvent::TYPE_BUDGET_EXPIRED, 'actor_type' => OrderActor::SYSTEM]);
        $this->assertSame(OrderStatus::BUDGET_GENERATED, (int) $order->fresh()->service_status);
    }

    public function test_internal_approval_after_expiration_is_blocked_and_resend_creates_new_version(): void
    {
        $order = $this->order();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->generate($order, '300,00', ['budget_valid_until' => '2026-10-05']);
        Carbon::setTestNow('2026-10-07 09:00:00');

        $this->save($order->fresh(), ['budget_description' => 'Troca de tela', 'budget_value' => '300,00', 'budget_valid_until' => '2026-10-05', 'service_status' => OrderStatus::BUDGET_APPROVED])
            ->assertSessionHasErrors('service_status');

        // Nova validade = mudança relevante: v1 vira histórico (vencida) e v2 é enviada.
        $this->save($order->fresh(), ['budget_description' => 'Troca de tela', 'budget_value' => '300,00', 'budget_valid_until' => '2026-10-20'])
            ->assertSessionHasNoErrors();
        Carbon::setTestNow();

        $versions = OrderBudget::query()->where('order_id', $order->id)->orderBy('version')->get();
        $this->assertSame([OrderBudget::STATUS_EXPIRED, OrderBudget::STATUS_SENT], $versions->pluck('status')->all());
        $this->assertSame('2026-10-05 23:59:59', $versions[0]->expired_at->toDateTimeString());
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => OrderEvent::TYPE_BUDGET_EXPIRED]);
    }

    public function test_internal_approval_by_status_change_approves_current_version(): void
    {
        $order = $this->order();
        $this->generate($order, '300,00');

        $this->save($order->fresh(), ['budget_description' => 'Troca de tela', 'budget_value' => '300,00', 'service_status' => OrderStatus::BUDGET_APPROVED])
            ->assertSessionHasNoErrors();

        $budget = OrderBudget::query()->where('order_id', $order->id)->sole();
        $this->assertSame(OrderBudget::STATUS_APPROVED, $budget->status);
        $this->assertSame(OrderActor::USER, $budget->approved_by_type);
        $this->assertSame($this->user->id, (int) $budget->approved_by);
    }

    public function test_renegotiation_after_approval_keeps_approved_version_until_new_approval(): void
    {
        $order = $this->order();
        $this->generate($order, '300,00');
        $this->save($order->fresh(), ['budget_description' => 'Troca de tela', 'budget_value' => '300,00', 'service_status' => OrderStatus::BUDGET_APPROVED]);

        // Volta para "Orçamento Gerado" com novo valor (regressão com motivo): v2 enviada, v1 segue aprovada.
        $this->save($order->fresh(), [
            'budget_description' => 'Troca de tela + conector', 'budget_value' => '380,00',
            'service_status' => OrderStatus::BUDGET_GENERATED, 'status_reason' => 'Peça adicional encontrada',
        ])->assertSessionHasNoErrors();

        $versions = OrderBudget::query()->where('order_id', $order->id)->orderBy('version')->get();
        $this->assertSame([OrderBudget::STATUS_APPROVED, OrderBudget::STATUS_SENT], $versions->pluck('status')->all());
        $this->assertSame($versions[0]->id, (int) $order->fresh()->approved_budget_id);
        Auth::logout();

        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED, 'budget_version' => 2]);

        $versions = OrderBudget::query()->where('order_id', $order->id)->orderBy('version')->get();
        $this->assertSame([OrderBudget::STATUS_SUPERSEDED, OrderBudget::STATUS_APPROVED], $versions->pluck('status')->all());
        $this->assertSame($versions[1]->id, (int) $order->fresh()->approved_budget_id);
        $this->assertSame(1, OrderBudget::query()->where('order_id', $order->id)->where('status', OrderBudget::STATUS_APPROVED)->count());
    }

    public function test_legacy_budget_has_no_invented_dates(): void
    {
        $awaiting = $this->order(OrderStatus::BUDGET_GENERATED, ['budget_description' => 'Legado aguardando', 'budget_value' => 200]);
        $approved = $this->order(OrderStatus::BUDGET_APPROVED, ['budget_description' => 'Legado aprovado', 'budget_value' => 150]);
        $none = $this->order(OrderStatus::OPEN, ['budget_description' => null, 'budget_value' => 0]);

        $migration = require database_path('migrations/2026_10_09_100000_create_order_budgets_and_messages.php');
        (new \ReflectionMethod($migration, 'createLegacyBudgets'))->invoke($migration);

        $legacyAwaiting = OrderBudget::query()->where('order_id', $awaiting->id)->sole();
        $this->assertTrue($legacyAwaiting->is_legacy);
        $this->assertSame(OrderBudget::STATUS_SENT, $legacyAwaiting->status);
        $legacyApproved = OrderBudget::query()->where('order_id', $approved->id)->sole();
        $this->assertSame(OrderBudget::STATUS_LEGACY, $legacyApproved->status);
        $this->assertNull($approved->fresh()->approved_budget_id);
        $this->assertSame(0, OrderBudget::query()->where('order_id', $none->id)->count());

        foreach ([$legacyAwaiting, $legacyApproved] as $legacy) {
            foreach (['sent_at', 'responded_at', 'approved_at', 'rejected_at', 'expired_at', 'total_amount'] as $field) {
                $this->assertNull($legacy->{$field}, $field);
            }
            $this->assertSame(0, OrderBudgetItem::query()->where('order_budget_id', $legacy->id)->count());
        }

        // O cliente ainda pode responder o orçamento legado que está diante dele.
        Auth::logout();
        $this->post(route('orders.budget.status', $awaiting->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED, 'budget_version' => 1])
            ->assertSessionHas('success');
        $this->assertNotNull($legacyAwaiting->fresh()->approved_at);
    }

    public function test_budgets_are_isolated_by_tenant(): void
    {
        $order = $this->order();
        $this->generate($order, '300,00');

        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->forTenant($otherTenant->id)->create();
        $this->withSession(['tenant_id' => $otherTenant->id])->actingAs($otherUser);

        $this->assertSame(0, OrderBudget::query()->count());
        // Outro tenant não enxerga a OS (o app redireciona): nada é alterado.
        $this->put(route('app.orders.update', $order), $this->payload($order, ['budget_value' => '1,00']))->assertSessionMissing('success');
        $this->assertMoney(300, OrderBudget::withoutGlobalScopes()->where('order_id', $order->id)->sole()->quoted_amount);

        $this->expectException(LogicException::class);
        OrderBudget::create([
            'tenant_id' => $otherTenant->id, 'order_id' => $order->id, 'version' => 9, 'status' => OrderBudget::STATUS_DRAFT,
        ]);
    }

    public function test_model_supports_conversion_and_timing_metrics(): void
    {
        $order = $this->order();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->generate($order, '300,00');
        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->save($order->fresh(), ['budget_description' => 'Troca de tela', 'budget_value' => '260,00']);
        Auth::logout();
        Carbon::setTestNow('2026-10-03 16:00:00');
        $this->post(route('orders.budget.status', $order->tracking_token), ['status' => OrderStatus::BUDGET_APPROVED, 'budget_version' => 2]);
        Carbon::setTestNow();

        // Tempo envio → aprovação da versão aprovada, versões por OS e diferença entre 1ª e última versão.
        $approved = OrderBudget::withoutGlobalScopes()->where('order_id', $order->id)->where('status', OrderBudget::STATUS_APPROVED)->sole();
        $this->assertSame(30, (int) $approved->sent_at->diffInHours($approved->approved_at));

        $stats = DB::table('order_budgets')->where('order_id', $order->id)
            ->selectRaw('COUNT(*) as versions, MIN(quoted_amount) as min_amount, MAX(quoted_amount) as max_amount')
            ->first();
        $this->assertSame(2, (int) $stats->versions);
        $this->assertMoney(40, (float) $stats->max_amount - (float) $stats->min_amount);
        $this->assertSame(1, OrderBudget::withoutGlobalScopes()->whereNotNull('sent_at')->where('status', OrderBudget::STATUS_APPROVED)->count());
    }

    private function generate(Order $order, string $value, array $overrides = []): void
    {
        $this->save($order, [
            'budget_description' => 'Troca de tela',
            'budget_value' => $value,
            'service_status' => OrderStatus::BUDGET_GENERATED,
            ...$overrides,
        ])->assertSessionHasNoErrors();
    }

    private function assertMoney(float $expected, mixed $actual): void
    {
        $this->assertSame(round($expected, 2), round((float) $actual, 2));
    }

    private function order(int $status = OrderStatus::OPEN, array $attributes = []): Order
    {
        return Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => null,
            'service_status' => $status,
            'service_value' => 0,
            'parts_value' => 0,
            'service_cost' => 0,
            'budget_description' => null,
            'budget_value' => 0,
            'budget_link' => null,
            'delivery_date' => null,
            'warranty_days' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            ...$attributes,
        ]);
    }

    private function save(Order $order, array $overrides = [])
    {
        return $this->put(route('app.orders.update', $order), $this->payload($order, $overrides));
    }

    private function payload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'order_type' => Order::TYPE_EQUIPMENT,
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => $order->user_id,
            'model' => $order->model ?? 'Modelo',
            'password' => null,
            'defect' => 'Defeito',
            'state_conservation' => 'Usado',
            'accessories' => 'Nenhum',
            'budget_description' => $order->budget_description,
            'budget_value' => number_format((float) $order->budget_value, 2, ',', '.'),
            'services_performed' => null,
            'service_value' => number_format((float) $order->service_value, 2, ',', '.'),
            'manual_parts_value' => number_format((float) $order->manual_parts_value, 2, ',', '.'),
            'delivery_date' => null,
            'service_status' => (int) $order->service_status,
            'delivery_forecast' => $order->delivery_forecast,
            'observations' => null,
        ], $overrides);
    }
}
