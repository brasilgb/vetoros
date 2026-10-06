<?php

namespace Tests\Feature\App;

use App\Mail\OrderBudgetFollowUpMail;
use App\Mail\OrderPaymentReminderMail;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderPayment;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\App\Schedule;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Ean13;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Test Tenant']);
        $this->user = User::factory()->forTenant($this->tenant->id)->create();
        Other::factory()->forTenant($this->tenant->id)->create([
            'enable_finance' => true,
            'enablesales' => true,
        ]);

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    public function test_it_finds_an_order_by_its_ean_13_label(): void
    {
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'order_number' => 123,
        ]);

        $response = $this->get(route('app.orders.index', [
            'search' => Ean13::fromNumber($order->order_number),
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('app/orders/index')
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $order->id)
        );
    }

    public function test_it_registers_initial_status_history_and_log_when_creating_an_order(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $response = $this->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'model' => 'Notebook Dell Inspiron',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertRedirect(route('app.orders.index'));

        $order = Order::query()->firstOrFail();

        $this->assertSame('Notebook Dell Inspiron', $order->model);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::OPEN,
            'changed_by' => $this->user->id,
            'note' => OrderStatus::label(OrderStatus::OPEN),
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_created',
        ]);
    }

    public function test_web_order_creation_does_not_store_customer_signature(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $response = $this->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'model' => 'Notebook Dell Inspiron',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            'customer_signature' => 'data:image/png;base64,'.base64_encode('ignored-web-signature'),
        ]);

        $response->assertRedirect(route('app.orders.index'));

        $order = Order::query()->firstOrFail();

        $this->assertNull($order->customer_signature_captured_at);
    }

    public function test_it_requires_delivery_forecast_when_creating_an_order(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $response = $this->from(route('app.orders.create'))->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'model' => 'Notebook Dell Inspiron',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => null,
        ]);

        $response
            ->assertRedirect(route('app.orders.create'))
            ->assertSessionHasErrors('delivery_forecast');
    }

    public function test_it_links_created_order_to_source_schedule(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $schedule = Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'user_id' => $this->user->id,
        ]);

        $response = $this->post(route('app.orders.store'), [
            'schedule_id' => $schedule->id,
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'model' => 'Notebook Dell Inspiron',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => $this->user->id,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ]);

        $order = Order::query()->firstOrFail();

        $response->assertRedirect(route('app.schedules.show', ['schedule' => $schedule->id]));
        $response->assertSessionHas('success', 'Ordem cadastrada com sucesso e vinculada ao agendamento.');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_it_creates_external_service_order_without_equipment_fields(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();

        $response = $this->post(route('app.orders.store'), [
            'order_type' => Order::TYPE_EXTERNAL_SERVICE,
            'customer_id' => $customer->id,
            'service_type' => 'Instalação solar',
            'service_details' => 'Instalação de inversor solar e vistoria do quadro elétrico',
            'materials_used' => 'Cabos, conectores MC4 e disjuntores',
            'service_status' => OrderStatus::OPEN,
            'service_value' => '350,00',
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertRedirect(route('app.orders.index'));

        $order = Order::query()->firstOrFail();

        $this->assertSame(Order::TYPE_EXTERNAL_SERVICE, $order->order_type);
        $this->assertSame('Instalação solar', $order->defect);
        $this->assertSame('Instalação solar', $order->service_type);
        $this->assertSame('Instalação de inversor solar e vistoria do quadro elétrico', $order->service_details);
        $this->assertSame('Cabos, conectores MC4 e disjuntores', $order->materials_used);
        $this->assertNull($order->equipment_id);
        $this->assertNull($order->model);
        $this->assertNull($order->password);
        $this->assertSame(OrderStatus::OPEN, (int) $order->service_status);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::OPEN,
        ]);
    }

    public function test_it_records_status_history_and_audit_log_when_order_status_changes(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::OPEN,
            'service_cost' => 0,
            'parts_value' => 0,
            'service_value' => 0,
        ]);

        $response = $this->put(route('app.orders.update', $order), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'model' => $order->model,
            'password' => $order->password,
            'defect' => $order->defect,
            'state_conservation' => $order->state_conservation,
            'accessories' => $order->accessories,
            'budget_description' => 'Troca de componente',
            'budget_value' => '150,00',
            'services_performed' => $order->services_performed ? mb_substr($order->services_performed, 0, 500) : null,
            'parts_value' => '0,00',
            'service_value' => '150,00',
            'service_cost' => '150,00',
            'delivery_date' => null,
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            'observations' => 'Aguardando aprovação',
        ]);

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::BUDGET_GENERATED,
            'changed_by' => $this->user->id,
            'note' => OrderStatus::label(OrderStatus::BUDGET_GENERATED),
        ]);
        $this->assertDatabaseHas('order_items', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'service',
            'source_type' => 'order_service',
            'quantity' => 1,
            'unit_price' => 150,
            'total_price' => 150,
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_status_changed',
        ]);
    }

    public function test_it_updates_order_model(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $budgetLink = 'https://produto.mercadolivre.com.br/MLB-123456789-produto-com-parametros?searchVariation=123&tracking_id=abc';
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'model' => 'Modelo antigo',
            'service_status' => OrderStatus::OPEN,
        ]);

        $response = $this->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
            'model' => 'Modelo novo',
            'budget_link' => $budgetLink,
        ]));

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $order->refresh();
        $this->assertSame('Modelo novo', $order->model);
        $this->assertSame($budgetLink, $order->budget_link);
    }

    public function test_root_app_can_create_and_update_an_order_assigned_to_another_user(): void
    {
        $rootApp = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_ROOT_APP,
        ]);
        $technician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $this->actingAs($rootApp);

        $createResponse = $this->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'model' => 'Modelo inicial',
            'defect' => 'Nao liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => $technician->id,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ]);

        $createResponse->assertRedirect(route('app.orders.index'));

        $order = Order::query()->latest('id')->firstOrFail();

        $updateResponse = $this->put(
            route('app.orders.update', $order),
            $this->orderUpdatePayload($order, $customer, $equipment, [
                'user_id' => $technician->id,
                'model' => 'Modelo atualizado pelo rootapp',
            ]),
        );

        $updateResponse
            ->assertRedirect(route('app.orders.show', ['order' => $order->id]))
            ->assertSessionMissing('authorization_error');

        $this->assertSame('Modelo atualizado pelo rootapp', $order->fresh()->model);
    }

    public function test_technician_can_update_owned_order_model_and_start_repair(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $technician->id,
            'model' => 'Modelo antigo',
            'service_status' => OrderStatus::OPEN,
        ]);

        $this->actingAs($technician);

        $response = $this->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
            'user_id' => $technician->id,
            'model' => 'Modelo novo',
            'service_status' => OrderStatus::REPAIR_IN_PROGRESS,
        ]));

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $order->refresh();
        $this->assertSame('Modelo novo', $order->model);
        $this->assertSame(OrderStatus::REPAIR_IN_PROGRESS, (int) $order->service_status);
    }

    public function test_technician_can_reassign_order_to_another_technician(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);
        $otherTechnician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $technician->id,
            'service_status' => OrderStatus::OPEN,
        ]);

        $this->actingAs($technician);

        $response = $this->put(
            route('app.orders.update', $order),
            $this->orderUpdatePayload($order, $customer, $equipment, [
                'user_id' => $otherTechnician->id,
                'model' => 'Atualizado pelo tecnico responsavel',
            ]),
        );

        $response
            ->assertRedirect(route('app.orders.index'))
            ->assertSessionHas('success', 'Ordem transferida e atualizada com sucesso')
            ->assertSessionMissing('authorization_error');

        $order->refresh();
        $this->assertSame($otherTechnician->id, $order->user_id);
        $this->assertSame('Atualizado pelo tecnico responsavel', $order->model);
    }

    public function test_operator_can_change_unassigned_order_status_without_responsible_technician(): void
    {
        $operator = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_OPERATOR,
        ]);
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => null,
            'service_status' => OrderStatus::OPEN,
        ]);

        $this->actingAs($operator);

        $response = $this->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
            'user_id' => null,
            'service_status' => OrderStatus::CANCELLED,
        ]));

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $order->refresh();
        $this->assertNull($order->user_id);
        $this->assertSame(OrderStatus::CANCELLED, (int) $order->service_status);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::CANCELLED,
            'changed_by' => $operator->id,
        ]);
    }

    public function test_it_does_not_use_system_mail_for_status_email_when_tenant_mail_is_not_configured(): void
    {
        Mail::fake();

        $customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'email' => 'cliente@example.com',
        ]);
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::OPEN,
        ]);

        $response = $this->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
            'service_status' => OrderStatus::REPAIR_IN_PROGRESS,
        ]));

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));
        Mail::assertNothingSent();
    }

    public function test_it_sends_status_email_immediately_with_tenant_mail_configuration(): void
    {
        Mail::fake();

        Other::query()->where('tenant_id', $this->tenant->id)->update([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => 587,
            'mail_username' => 'user@example.com',
            'mail_password' => Crypt::encryptString('secret'),
            'mail_encryption' => 'tls',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'VetorOS',
        ]);

        $customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'email' => 'cliente@example.com',
        ]);
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::OPEN,
        ]);

        $response = $this->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
            'service_status' => OrderStatus::REPAIR_IN_PROGRESS,
        ]));

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));
        Mail::assertSent(
            OrderStatusUpdatedMail::class,
            fn (OrderStatusUpdatedMail $mail) => $mail->hasTo('cliente@example.com'),
        );
    }

    public function test_it_logs_order_payment_registration(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_cost' => 200,
            'parts_value' => 0,
            'service_value' => 200,
        ]);

        CashSession::create([
            'tenant_id' => $this->tenant->id,
            'opened_by' => $this->user->id,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $response = $this->post(route('app.orders.payments.store', $order), [
            'amount' => '50,00',
            'payment_method' => 'pix',
            'paid_at' => now()->format('Y-m-d\TH:i'),
            'notes' => 'Entrada inicial',
        ]);

        $response->assertSessionHas('success', 'Pagamento registrado com sucesso.');

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'pix',
            'notes' => 'Entrada inicial',
        ]);

        $this->assertDatabaseHas('accounts_receivable', [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'total_amount' => 200,
            'paid_amount' => 50,
            'balance_amount' => 150,
            'status' => 'partial',
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_payment_registered',
        ]);
    }

    public function test_operator_confirms_mobile_payment_into_open_cash_session(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_cost' => 200,
            'parts_value' => 0,
            'service_value' => 200,
            'technician_local_payment_received' => true,
            'technician_local_payment_status' => 'pending',
            'technician_local_payment_amount' => 80,
            'technician_local_payment_method' => 'dinheiro',
            'technician_local_payment_notes' => 'Cliente informou pagamento no app.',
            'technician_local_payment_received_at' => now()->subMinutes(10),
            'technician_local_payment_user_id' => $this->user->id,
        ]);

        CashSession::create([
            'tenant_id' => $this->tenant->id,
            'opened_by' => $this->user->id,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $response = $this->post(route('app.orders.payments.mobile-confirm', $order));

        $response->assertSessionHas('success', 'Pagamento conferido e inserido no caixa.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'technician_local_payment_status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'amount' => 80,
            'payment_method' => 'dinheiro',
            'notes' => 'Cliente informou pagamento no app.',
        ]);

        $this->assertDatabaseHas('accounts_receivable', [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'paid_amount' => 80,
            'balance_amount' => 120,
            'status' => 'partial',
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_payment_registered',
        ]);
    }

    public function test_it_allows_flexible_order_status_transition(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::BUDGET_APPROVED,
        ]);

        $response = $this->from(route('app.orders.show', $order))->put(route('app.orders.update', $order), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'model' => $order->model,
            'password' => $order->password,
            'defect' => $order->defect,
            'state_conservation' => $order->state_conservation,
            'accessories' => $order->accessories,
            'budget_description' => null,
            'budget_value' => null,
            'services_performed' => $order->services_performed,
            'parts_value' => '0,00',
            'service_value' => '0,00',
            'service_cost' => '0,00',
            'delivery_date' => null,
            'service_status' => OrderStatus::OPEN,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            'observations' => null,
        ]);

        $response->assertRedirect(route('app.orders.show', $order));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::OPEN,
            'changed_by' => $this->user->id,
        ]);
    }

    public function test_it_blocks_removing_order_payment_from_closed_cash_session(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_cost' => 200,
        ]);

        $cashSession = CashSession::create([
            'tenant_id' => $this->tenant->id,
            'opened_by' => $this->user->id,
            'closed_by' => $this->user->id,
            'opened_at' => now()->subHours(3),
            'closed_at' => now()->subHour(),
            'opening_balance' => 0,
            'closing_balance' => 50,
            'expected_balance' => 50,
            'difference' => 0,
            'status' => 'closed',
        ]);

        $payment = OrderPayment::create([
            'order_id' => $order->id,
            'cash_session_id' => $cashSession->id,
            'amount' => 50,
            'payment_method' => 'pix',
            'paid_at' => now(),
            'notes' => 'Pagamento bloqueado',
        ]);

        $response = $this->delete(route('app.orders.payments.destroy', [$order, $payment]));

        $response->assertSessionHas('error', 'Não é possível remover pagamento vinculado a um caixa já fechado.');

        $this->assertDatabaseHas('order_payments', [
            'id' => $payment->id,
        ]);
    }

    public function test_it_removes_order_payment_and_logs_audit_entry(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_cost' => 200,
        ]);

        $cashSession = CashSession::create([
            'tenant_id' => $this->tenant->id,
            'opened_by' => $this->user->id,
            'opened_at' => now()->subHour(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $payment = OrderPayment::create([
            'order_id' => $order->id,
            'cash_session_id' => $cashSession->id,
            'amount' => 50,
            'payment_method' => 'pix',
            'paid_at' => now(),
            'notes' => 'Pagamento removível',
        ]);

        $response = $this->delete(route('app.orders.payments.destroy', [$order, $payment]));

        $response->assertSessionHas('success', 'Pagamento removido com sucesso.');

        $this->assertDatabaseMissing('order_payments', [
            'id' => $payment->id,
        ]);

        $this->assertDatabaseHas('accounts_receivable', [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'total_amount' => 200,
            'paid_amount' => 0,
            'balance_amount' => 200,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_payment_removed',
        ]);
    }

    public function test_it_handles_main_order_flow_until_customer_feedback(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $createResponse = $this->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'defect' => 'Não carrega',
            'service_status' => OrderStatus::OPEN,
            'user_id' => $this->user->id,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ]);

        $createResponse->assertRedirect(route('app.orders.index'));

        $order = Order::query()->latest('id')->firstOrFail();

        $budgetResponse = $this->put(route('app.orders.update', $order), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'model' => $order->model,
            'password' => $order->password,
            'defect' => $order->defect,
            'state_conservation' => $order->state_conservation,
            'accessories' => $order->accessories,
            'budget_description' => 'Troca do conector de carga',
            'budget_value' => '150,00',
            'services_performed' => $order->services_performed,
            'parts_value' => '50,00',
            'service_value' => '100,00',
            'service_cost' => '150,00',
            'delivery_date' => null,
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            'observations' => 'Aguardando resposta do cliente',
        ]);

        $budgetResponse->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $approveResponse = $this->post(route('orders.budget.status', $order->tracking_token), [
            'status' => OrderStatus::BUDGET_APPROVED,
        ]);

        $approveResponse->assertSessionHas('success', 'Status do orçamento atualizado com sucesso.');

        $serviceCompletedResponse = $this->put(route('app.orders.update', $order->fresh()), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'model' => $order->model,
            'password' => $order->password,
            'defect' => $order->defect,
            'state_conservation' => $order->state_conservation,
            'accessories' => $order->accessories,
            'budget_description' => 'Troca do conector de carga',
            'budget_value' => '150,00',
            'services_performed' => 'Troca realizada com sucesso',
            'parts_value' => '50,00',
            'service_value' => '100,00',
            'service_cost' => '150,00',
            'delivery_date' => null,
            'service_status' => OrderStatus::SERVICE_COMPLETED,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            'observations' => 'Pronto para retirada',
        ]);

        $serviceCompletedResponse->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        CashSession::create([
            'tenant_id' => $this->tenant->id,
            'opened_by' => $this->user->id,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $paymentResponse = $this->post(route('app.orders.payments.store', $order->fresh()), [
            'amount' => '150,00',
            'payment_method' => 'pix',
            'paid_at' => now()->format('Y-m-d\TH:i'),
            'notes' => 'Pagamento total',
        ]);

        $paymentResponse->assertSessionHas('success', 'Pagamento registrado com sucesso.');

        $notificationResponse = $this->post(route('orders.notification.acknowledge', $order->fresh()->tracking_token));

        $notificationResponse->assertSessionHas('success', 'Confirmação de aviso registrada com sucesso.');

        $pickupResponse = $this->post(route('orders.pickup.acknowledge', $order->fresh()->tracking_token));

        $pickupResponse->assertSessionHas('success', 'Confirmação de retirada registrada com sucesso.');

        $feedbackResponse = $this->post(route('os.feedback.submit', $order->fresh()->tracking_token), [
            'rating' => 5,
            'comment' => 'Atendimento excelente',
        ]);

        $feedbackResponse->assertSessionHas('success', 'Obrigado! Seu feedback foi enviado com sucesso.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'service_status' => OrderStatus::DELIVERED,
            'customer_feedback_rating' => 5,
        ]);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::BUDGET_GENERATED,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::BUDGET_APPROVED,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::SERVICE_COMPLETED,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::CUSTOMER_NOTIFIED,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::DELIVERED,
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_payment_registered',
        ]);
        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_customer_notification_acknowledged',
        ]);
        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_customer_pickup_acknowledged',
        ]);
        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $this->tenant->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_customer_feedback_submitted',
        ]);
    }

    public function test_it_calculates_warranty_expiration_when_updating_order(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
        ]);

        $deliveryDate = now()->setTime(10, 0, 0);

        $response = $this->put(route('app.orders.update', $order), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'model' => $order->model,
            'password' => $order->password,
            'defect' => $order->defect,
            'state_conservation' => $order->state_conservation,
            'accessories' => $order->accessories,
            'budget_description' => $order->budget_description,
            'budget_value' => '0,00',
            'services_performed' => $order->services_performed,
            'parts_value' => '0,00',
            'service_value' => '100,00',
            'service_cost' => '100,00',
            'delivery_date' => $deliveryDate->format('Y-m-d H:i:s'),
            'warranty_days' => 90,
            'service_status' => OrderStatus::DELIVERED,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            'observations' => null,
        ]);

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'warranty_days' => 90,
        ]);

        $this->assertEquals(
            $deliveryDate->copy()->addDays(90)->toDateTimeString(),
            $order->fresh()->warranty_expires_at?->toDateTimeString()
        );
    }

    public function test_it_clears_delivery_date_when_status_is_not_delivered(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDay(),
            'warranty_days' => 90,
            'warranty_expires_at' => now()->addDays(89),
        ]);

        $response = $this->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
            'delivery_date' => now()->toDateTimeString(),
            'warranty_days' => 90,
            'service_status' => OrderStatus::SERVICE_COMPLETED,
        ]));

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $freshOrder = $order->fresh();

        $this->assertNull($freshOrder->delivery_date);
        $this->assertNull($freshOrder->warranty_expires_at);
        $this->assertSame(OrderStatus::SERVICE_COMPLETED, (int) $freshOrder->service_status);
    }

    public function test_it_decrements_stock_when_parts_are_added_to_order(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $part = Part::factory()->forTenant($this->tenant->id)->create([
            'quantity' => 5,
            'sale_price' => 80,
            'name' => 'Conector USB-C',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::OPEN,
        ]);

        $response = $this->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
            'parts_value' => '160,00',
            'service_cost' => '160,00',
            'allparts' => [
                ['part_id' => $part->id, 'quantity' => 2],
            ],
        ]));

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $this->assertSame(3, (int) $part->fresh()->quantity);
        $this->assertDatabaseHas('order_parts', [
            'order_id' => $order->id,
            'part_id' => $part->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('order_items', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'source_type' => 'part',
            'source_id' => $part->id,
            'description' => 'Conector USB-C',
            'quantity' => 2,
            'unit_price' => 80,
            'total_price' => 160,
        ]);
        $this->assertDatabaseHas('part_movements', [
            'part_id' => $part->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'movement_type' => 'uso_os',
            'quantity' => 2,
            'reason' => 'Uso na OS '.$order->order_number,
        ]);
    }

    public function test_it_returns_stock_when_order_part_quantity_is_reduced(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $part = Part::factory()->forTenant($this->tenant->id)->create([
            'quantity' => 2,
            'sale_price' => 80,
            'name' => 'Conector USB-C',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::OPEN,
            'parts_value' => 240,
            'service_cost' => 240,
        ]);
        $order->orderParts()->attach($part->id, ['quantity' => 3]);

        $response = $this->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
            'parts_value' => '80,00',
            'service_cost' => '80,00',
            'allparts' => [
                ['part_id' => $part->id, 'quantity' => 1],
            ],
        ]));

        $response->assertRedirect(route('app.orders.show', ['order' => $order->id]));

        $this->assertSame(4, (int) $part->fresh()->quantity);
        $this->assertDatabaseHas('order_parts', [
            'order_id' => $order->id,
            'part_id' => $part->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('part_movements', [
            'part_id' => $part->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'movement_type' => 'devolucao',
            'quantity' => 2,
            'reason' => 'Devolução de peça da OS '.$order->order_number,
        ]);
    }

    public function test_it_blocks_adding_order_part_when_stock_is_insufficient(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $part = Part::factory()->forTenant($this->tenant->id)->create([
            'quantity' => 1,
            'sale_price' => 80,
            'name' => 'Conector USB-C',
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::OPEN,
        ]);

        $response = $this->from(route('app.orders.show', $order))
            ->put(route('app.orders.update', $order), $this->orderUpdatePayload($order, $customer, $equipment, [
                'parts_value' => '160,00',
                'service_cost' => '160,00',
                'allparts' => [
                    ['part_id' => $part->id, 'quantity' => 2],
                ],
            ]));

        $response
            ->assertRedirect(route('app.orders.show', $order))
            ->assertSessionHasErrors('allparts');

        $this->assertSame(1, (int) $part->fresh()->quantity);
        $this->assertDatabaseMissing('order_parts', [
            'order_id' => $order->id,
            'part_id' => $part->id,
        ]);
    }

    public function test_it_returns_stock_when_order_part_is_removed(): void
    {
        $part = Part::factory()->forTenant($this->tenant->id)->create([
            'quantity' => 3,
            'sale_price' => 80,
        ]);
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'user_id' => $this->user->id,
            'parts_value' => 160,
            'service_value' => 40,
            'service_cost' => 200,
        ]);
        $order->orderParts()->attach($part->id, ['quantity' => 2]);

        $response = $this->post(route('app.orders.removePart'), [
            'order_id' => $order->id,
            'part_id' => $part->id,
        ]);

        $response->assertRedirect(route('app.orders.show', $order));

        $this->assertSame(5, (int) $part->fresh()->quantity);
        $this->assertDatabaseMissing('order_parts', [
            'order_id' => $order->id,
            'part_id' => $part->id,
        ]);
        $this->assertDatabaseMissing('order_items', [
            'order_id' => $order->id,
            'item_type' => 'product',
            'source_type' => 'part',
            'source_id' => $part->id,
        ]);
        $this->assertDatabaseHas('order_items', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'service',
            'source_type' => 'order_service',
            'total_price' => 40,
        ]);
        $this->assertDatabaseHas('part_movements', [
            'part_id' => $part->id,
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'movement_type' => 'devolucao',
            'quantity' => 2,
            'reason' => 'Devolução de peça removida da OS '.$order->order_number,
        ]);
    }

    public function test_it_marks_new_order_as_warranty_return_when_the_source_order_is_selected(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $coveredOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'model' => 'Notebook Dell',
            'delivery_date' => now()->subDays(10),
            'warranty_days' => 30,
            'warranty_expires_at' => now()->addDays(20),
        ]);

        $response = $this->post(route('app.orders.store'), [
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'model' => 'Notebook Dell',
            'defect' => 'Não liga novamente',
            'service_status' => OrderStatus::OPEN,
            'user_id' => $this->user->id,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            'is_warranty_return' => true,
            'warranty_source_order_id' => $coveredOrder->id,
        ]);

        $response->assertRedirect(route('app.orders.index'));

        $newOrder = Order::query()->whereKeyNot($coveredOrder->id)->latest('id')->firstOrFail();

        $this->assertTrue((bool) $newOrder->is_warranty_return);
        $this->assertSame($coveredOrder->id, $newOrder->warranty_source_order_id);

        $this->get(route('app.orders.show', $newOrder))
            ->assertOk()
            ->assertViewHas('page.props.order.is_warranty_return', true)
            ->assertViewHas('page.props.order.warranty_source_order_id', $coveredOrder->id);

        $this->put(route('app.orders.update', $newOrder), $this->orderUpdatePayload($newOrder, $customer, $equipment, [
            'is_warranty_return' => false,
            'warranty_source_order_id' => null,
        ]))->assertRedirect();

        $newOrder->refresh();
        $this->assertTrue($newOrder->is_warranty_return);
        $this->assertSame($coveredOrder->id, $newOrder->warranty_source_order_id);
    }

    public function test_it_filters_orders_by_warranty_return(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $warrantyReturnOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'is_warranty_return' => true,
        ]);

        $regularOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'is_warranty_return' => false,
        ]);

        $response = $this->get(route('app.orders.index', ['filter' => 'warranty_return']));

        $response
            ->assertOk()
            ->assertViewHas('page.props.filter', 'warranty_return')
            ->assertViewHas('page.props.orders.data', function (array $orders) use ($warrantyReturnOrder, $regularOrder) {
                $orderIds = collect($orders)->pluck('id');

                return $orderIds->contains($warrantyReturnOrder->id)
                    && ! $orderIds->contains($regularOrder->id);
            });
    }

    public function test_it_filters_orders_with_active_warranty(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $activeWarrantyOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'delivery_date' => now()->subDay(),
            'warranty_expires_at' => now()->addDays(30),
        ]);

        $expiredWarrantyOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'delivery_date' => now()->subDays(60),
            'warranty_expires_at' => now()->subDays(30),
        ]);

        $response = $this->get(route('app.orders.index', ['filter' => 'active_warranty']));

        $response
            ->assertOk()
            ->assertViewHas('page.props.filter', 'active_warranty')
            ->assertViewHas('page.props.orders.data', function (array $orders) use ($activeWarrantyOrder, $expiredWarrantyOrder) {
                $orderIds = collect($orders)->pluck('id');

                return $orderIds->contains($activeWarrantyOrder->id)
                    && ! $orderIds->contains($expiredWarrantyOrder->id);
            });
    }

    public function test_it_filters_orders_for_budget_follow_up(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $stalledBudgetOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'updated_at' => now()->subDays(3),
        ]);

        $recentBudgetOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->get(route('app.orders.index', ['filter' => 'budget_follow_up']));

        $response
            ->assertOk()
            ->assertViewHas('page.props.filter', 'budget_follow_up')
            ->assertViewHas('page.props.orders.data', function (array $orders) use ($stalledBudgetOrder, $recentBudgetOrder) {
                $orderIds = collect($orders)->pluck('id');

                return $orderIds->contains($stalledBudgetOrder->id)
                    && ! $orderIds->contains($recentBudgetOrder->id);
            });
    }

    public function test_it_filters_orders_for_pending_payment_follow_up(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $chargeOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::DELIVERED,
            'service_cost' => 300,
            'delivery_date' => now()->subDays(4),
        ]);

        OrderPayment::create([
            'order_id' => $chargeOrder->id,
            'amount' => 100,
            'payment_method' => 'pix',
            'paid_at' => now()->subDays(3),
        ]);

        $settledOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::DELIVERED,
            'service_cost' => 300,
            'delivery_date' => now()->subDays(4),
        ]);

        OrderPayment::create([
            'order_id' => $settledOrder->id,
            'amount' => 300,
            'payment_method' => 'pix',
            'paid_at' => now()->subDays(3),
        ]);

        $response = $this->get(route('app.orders.index', ['filter' => 'pending_payment_follow_up']));

        $response
            ->assertOk()
            ->assertViewHas('page.props.filter', 'pending_payment_follow_up')
            ->assertViewHas('page.props.orders.data', function (array $orders) use ($chargeOrder, $settledOrder) {
                $orderIds = collect($orders)->pluck('id');

                return $orderIds->contains($chargeOrder->id)
                    && ! $orderIds->contains($settledOrder->id);
            });
    }

    public function test_it_logs_payment_reminder_sent(): void
    {
        Queue::fake();
        Mail::fake();

        $customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'email' => 'cliente@example.com',
        ]);
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::DELIVERED,
            'service_cost' => 300,
            'delivery_date' => now()->subDays(8),
        ]);

        Other::query()->create([
            'tenant_id' => $this->tenant->id,
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => 587,
            'mail_username' => 'user@example.com',
            'mail_password' => Crypt::encryptString('secret'),
            'mail_encryption' => 'tls',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'VetorOS',
        ]);

        $response = $this->post(route('app.orders.payments.reminder', $order));

        $response->assertSessionHas('success', 'E-mail de cobrança/lembrete enviado com sucesso.');

        Mail::assertSent(OrderPaymentReminderMail::class, 1);

        $this->assertDatabaseHas('order_logs', [
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'action' => 'payment_reminder_sent',
        ]);
    }

    public function test_it_logs_budget_follow_up_sent(): void
    {
        Queue::fake();
        Mail::fake();

        $customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'email' => 'cliente@example.com',
        ]);
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'updated_at' => now()->subDays(3),
        ]);

        Other::query()->create([
            'tenant_id' => $this->tenant->id,
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => 587,
            'mail_username' => 'user@example.com',
            'mail_password' => Crypt::encryptString('secret'),
            'mail_encryption' => 'tls',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'VetorOS',
        ]);

        $response = $this->post(route('app.orders.budget-follow-up', $order));

        $response->assertSessionHas('success', 'Acompanhamento de orçamento enviado com sucesso.');

        Mail::assertSent(OrderBudgetFollowUpMail::class, 1);

        $this->assertDatabaseHas('order_logs', [
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'action' => 'budget_follow_up_sent',
        ]);
    }

    public function test_it_exposes_last_communication_in_orders_listing(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
        ]);

        $order->logs()->create([
            'user_id' => $this->user->id,
            'action' => 'payment_reminder_sent',
            'data' => [
                'channel' => 'email',
                'recipient' => 'cliente@example.com',
                'trigger' => 'manual',
            ],
            'created_at' => now()->subHour(),
        ]);

        $response = $this->get(route('app.orders.index'));

        $response
            ->assertOk()
            ->assertViewHas('page.props.orders.data', function (array $orders) use ($order) {
                $matched = collect($orders)->firstWhere('id', $order->id);

                return is_array($matched)
                    && ($matched['last_communication']['trigger'] ?? null) === 'manual'
                    && ($matched['last_communication']['channel'] ?? null) === 'email';
            });
    }

    private function orderUpdatePayload(Order $order, Customer $customer, Equipment $equipment, array $overrides = []): array
    {
        return array_merge([
            'order_type' => $order->order_type ?? Order::TYPE_EQUIPMENT,
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->user->id,
            'model' => $order->model ?? 'Modelo teste',
            'password' => $order->password,
            'defect' => mb_substr($order->defect ?? 'Defeito teste', 0, 500),
            'service_type' => $order->service_type,
            'service_details' => $order->service_details,
            'materials_used' => $order->materials_used,
            'state_conservation' => mb_substr($order->state_conservation ?? 'Usado', 0, 500),
            'accessories' => mb_substr($order->accessories ?? 'Sem acessórios', 0, 500),
            'budget_description' => $order->budget_description ? mb_substr($order->budget_description, 0, 500) : null,
            'budget_value' => '0,00',
            'budget_link' => $order->budget_link,
            'services_performed' => $order->services_performed ? mb_substr($order->services_performed, 0, 500) : null,
            'parts_value' => '0,00',
            'service_value' => '0,00',
            'service_cost' => '0,00',
            'delivery_date' => null,
            'service_status' => $order->service_status ?? OrderStatus::OPEN,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
            'observations' => $order->observations ? mb_substr($order->observations, 0, 500) : null,
        ], $overrides);
    }
}
