<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Order;
use App\Models\App\WhatsappConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSendControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.base_url' => 'http://waha.test']);
        Http::preventStrayRequests();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    private function connectedSession(): void
    {
        WhatsappConnection::factory()->for($this->tenant)->create([
            'session_name' => "vetoros1-{$this->tenant->id}",
            'status' => WhatsappConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_order_whatsapp_send_succeeds_when_connected_and_logs_order_action(): void
    {
        $this->connectedSession();

        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['whatsapp' => '51999999999']);
        $order = Order::factory()->forTenant($this->tenant->id)->create(['customer_id' => $customer->id]);

        Http::fake([
            // O envio resolve o chatId real antes (nono dígito); ver WahaService::resolveChatId.
            'waha.test/api/contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '5551999999999@c.us']),
            'waha.test/api/sendText' => Http::response(['id' => 'true_5551999999999@c.us_ABC']),
        ]);

        $response = $this->post(route('app.orders.whatsapp.send', $order), [
            'message' => 'Seu equipamento está pronto para retirada.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://waha.test/api/sendText'
                && $request['chatId'] === '5551999999999@c.us'
                && $request['text'] === 'Seu equipamento está pronto para retirada.';
        });

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/contacts/check-exists')
            && $request['phone'] === '5551999999999');
    }

    public function test_order_whatsapp_send_fails_with_friendly_error_when_disconnected(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['whatsapp' => '51999999999']);
        $order = Order::factory()->forTenant($this->tenant->id)->create(['customer_id' => $customer->id]);

        Http::fake();

        $response = $this->post(route('app.orders.whatsapp.send', $order), [
            'message' => 'Olá!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'O WhatsApp desta empresa está desconectado. Reconecte-o nas configurações.');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('order_logs', [
            'order_id' => $order->id,
            'action' => 'whatsapp_sent',
        ]);
    }

    public function test_order_whatsapp_send_fails_when_customer_has_no_valid_whatsapp_number(): void
    {
        $this->connectedSession();

        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['whatsapp' => null]);
        $order = Order::factory()->forTenant($this->tenant->id)->create(['customer_id' => $customer->id]);

        Http::fake();

        $response = $this->post(route('app.orders.whatsapp.send', $order), [
            'message' => 'Olá!',
        ]);

        $response->assertSessionHas('error', 'Número de WhatsApp inválido.');
        Http::assertNothingSent();
    }

    public function test_order_whatsapp_send_is_rejected_for_order_of_another_tenant(): void
    {
        $this->connectedSession();

        $otherTenant = Tenant::factory()->create();
        $otherCustomer = Customer::factory()->forTenant($otherTenant->id)->create(['whatsapp' => '51999999999']);
        $otherOrder = Order::factory()->forTenant($otherTenant->id)->create(['customer_id' => $otherCustomer->id]);

        $response = $this->post(route('app.orders.whatsapp.send', $otherOrder), [
            'message' => 'Olá!',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('order_logs', ['order_id' => $otherOrder->id, 'action' => 'whatsapp_sent']);
    }

    public function test_customer_whatsapp_send_succeeds_when_connected(): void
    {
        $this->connectedSession();

        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['whatsapp' => '51999999999']);

        Http::fake([
            // O envio resolve o chatId real antes (nono dígito); ver WahaService::resolveChatId.
            'waha.test/api/contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '5551999999999@c.us']),
            'waha.test/api/sendText' => Http::response(['id' => 'true_5551999999999@c.us_ABC']),
        ]);

        $response = $this->post(route('app.customers.whatsapp.send', $customer), [
            'message' => 'Bom dia, João, como está?',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/sendText' && $request['chatId'] === '5551999999999@c.us');
    }

    public function test_customer_whatsapp_send_fails_with_friendly_error_when_disconnected(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['whatsapp' => '51999999999']);

        Http::fake();

        $response = $this->post(route('app.customers.whatsapp.send', $customer), [
            'message' => 'Bom dia!',
        ]);

        $response->assertSessionHas('error', 'O WhatsApp desta empresa está desconectado. Reconecte-o nas configurações.');
        Http::assertNothingSent();
    }
}
