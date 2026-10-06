<?php

namespace Tests\Feature;

use App\Models\App\Company;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderPayment;
use App\Models\App\Other;
use App\Models\App\Receipt;
use App\Models\Tenant;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_order_can_require_and_validate_an_access_key(): void
    {
        $tenant = Tenant::factory()->create();
        Other::factory()->forTenant($tenant->id)->create(['public_order_access_key_required' => true]);
        $order = Order::factory()->forTenant($tenant->id)->create([
            'public_access_key' => 'ABCD1234',
            'public_access_key_hash' => Hash::make('ABCD1234'),
        ]);

        $this->get(route('os.token', $order->tracking_token))
            ->assertOk()
            ->assertViewHas('page.component', 'app/serviceorders/access');

        $this->post(route('os.access', $order->tracking_token), ['key' => 'INVALIDA'])
            ->assertSessionHasErrors('key');

        $this->post(route('os.access', $order->tracking_token), ['key' => 'abcd1234'])
            ->assertRedirect(route('os.token', $order->tracking_token));

        $this->get(route('os.token', $order->tracking_token))
            ->assertOk()
            ->assertViewHas('page.component', 'app/serviceorders/index');
    }

    public function test_public_order_page_uses_order_meta_for_social_preview(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->forTenant($tenant->id)->create([
            'shortname' => 'Assist Tec',
            'companyname' => 'Assistencia Tecnica Modelo',
        ]);
        $customer = Customer::factory()->forTenant($tenant->id)->create([
            'name' => 'Joao Martinez',
        ]);
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'order_number' => 1,
        ]);

        $response = $this->get(route('os.token', ['token' => $order->tracking_token, 'preview' => 'whatsapp']));

        $response->assertOk();
        $response->assertSee('<meta property="og:site_name" content="Assist Tec">', false);
        $response->assertSee('<meta property="og:title" content="Assist Tec">', false);
        $response->assertSee('<meta property="og:description" content="Acompanhe o andamento da ordem de serviço">', false);
        $response->assertSee('preview=whatsapp', false);
        $response->assertSee('<meta name="robots" content="noindex, nofollow, max-image-preview:large">', false);
    }

    public function test_customer_can_approve_budget_only_from_budget_generated_status(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::BUDGET_GENERATED,
        ]);

        $response = $this->post(route('orders.budget.status', $order->tracking_token), [
            'status' => OrderStatus::BUDGET_APPROVED,
        ]);

        $response->assertSessionHas('success', 'Status do orçamento atualizado com sucesso.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'service_status' => OrderStatus::BUDGET_APPROVED,
        ]);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::BUDGET_APPROVED,
            'note' => OrderStatus::label(OrderStatus::BUDGET_APPROVED),
        ]);
    }

    public function test_customer_cannot_jump_budget_status_after_it_is_already_processed(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::BUDGET_APPROVED,
        ]);

        $response = $this->from(route('os.token', $order->tracking_token))->post(route('orders.budget.status', $order->tracking_token), [
            'status' => OrderStatus::BUDGET_REJECTED,
        ]);

        $response->assertRedirect(route('os.token', $order->tracking_token));
        $response->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::BUDGET_REJECTED,
        ]);
    }

    public function test_customer_can_acknowledge_completion_notice_from_public_page(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::SERVICE_COMPLETED,
        ]);

        $response = $this->post(route('orders.notification.acknowledge', $order->tracking_token));

        $response->assertSessionHas('success', 'Confirmação de aviso registrada com sucesso.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
        ]);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::CUSTOMER_NOTIFIED,
            'note' => 'Cliente confirmou que recebeu o aviso de conclusão.',
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $tenant->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_customer_notification_acknowledged',
        ]);
    }

    public function test_customer_can_acknowledge_pickup_when_order_is_ready_and_paid(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
            'service_cost' => 150,
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'amount' => 150,
            'payment_method' => 'pix',
            'paid_at' => now(),
        ]);

        $response = $this->post(route('orders.pickup.acknowledge', $order->tracking_token));

        $response->assertSessionHas('success', 'Confirmação de retirada registrada com sucesso.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'service_status' => OrderStatus::DELIVERED,
        ]);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => OrderStatus::DELIVERED,
            'note' => 'Cliente confirmou a retirada do equipamento pela área pública.',
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $tenant->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_customer_pickup_acknowledged',
        ]);
    }

    public function test_customer_can_open_public_delivery_receipt(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
        ]);

        Receipt::create([
            'tenant_id' => $tenant->id,
            'equipmentdelivery' => 'Recibo público de entrega.',
        ]);

        $response = $this->get(route('os.receipt', ['token' => $order->tracking_token, 'type' => 'orentrega']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('app/receipts/print-receipt')
            ->where('type', 'orentrega')
            ->where('copies', 1)
            ->where('backUrl', route('os.token', $order->tracking_token)));
    }

    public function test_customer_can_open_public_payment_proof_when_order_has_payments(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::CUSTOMER_NOTIFIED,
            'service_cost' => 200,
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'amount' => 80,
            'payment_method' => 'pix',
            'paid_at' => now(),
            'notes' => 'Entrada registrada.',
        ]);

        $response = $this->get(route('os.payment-proof', ['token' => $order->tracking_token]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('app/serviceorders/payment-proof')
            ->where('backUrl', route('os.token', $order->tracking_token)));
    }

    public function test_customer_can_open_public_fiscal_proof_when_order_has_fiscal_document(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::DELIVERED,
            'fiscal_document_number' => 'NFSe-2026-0001',
            'fiscal_document_url' => 'https://example.com/nfse/1',
            'fiscal_issued_at' => now(),
            'fiscal_notes' => 'Documento fiscal emitido e registrado.',
        ]);

        $response = $this->get(route('os.fiscal-proof', ['token' => $order->tracking_token]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('app/serviceorders/fiscal-proof')
            ->where('backUrl', route('os.token', $order->tracking_token))
            ->where('order.fiscal_document_number', 'NFSe-2026-0001'));
    }

    public function test_customer_can_submit_feedback_from_public_page_after_delivery(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDay(),
            'feedback' => 0,
        ]);

        $response = $this->post(route('os.feedback.submit', $order->tracking_token), [
            'rating' => 5,
            'comment' => 'Atendimento rápido e claro.',
        ]);

        $response->assertSessionHas('success', 'Obrigado! Seu feedback foi enviado com sucesso.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'feedback' => 1,
            'customer_feedback_rating' => 5,
            'customer_feedback_comment' => 'Atendimento rápido e claro.',
        ]);

        $this->assertDatabaseHas('operational_audits', [
            'tenant_id' => $tenant->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'order_customer_feedback_submitted',
        ]);
    }

    public function test_low_customer_feedback_starts_recovery_queue_automatically(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->forTenant($tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDay(),
            'feedback' => 0,
        ]);

        $this->post(route('os.feedback.submit', $order->tracking_token), [
            'rating' => 2,
            'comment' => 'Não gostei do prazo.',
        ])->assertSessionHas('success', 'Obrigado! Seu feedback foi enviado com sucesso.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_feedback_rating' => 2,
            'customer_feedback_recovery_status' => 'pending',
        ]);
    }
}
