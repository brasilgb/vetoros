<?php

namespace Tests\Feature\App;

use App\Mail\OrderStatusUpdatedMail;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\OperationalAudit;
use App\Models\App\Order;
use App\Models\App\OrderBudget;
use App\Models\App\OrderEvent;
use App\Models\App\OrderMessage;
use App\Models\App\Other;
use App\Models\App\WhatsappConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderMessageService;
use App\Services\WahaService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

class OrderCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'waha-hmac-secret';

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.waha.base_url' => 'http://waha.test',
            'services.waha.webhook_secret' => self::SECRET,
        ]);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant->id)->create();
        Other::factory()->forTenant($this->tenant->id)->create(['enable_finance' => true]);
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create(['whatsapp' => '(51) 99999-9999', 'email' => null]);
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        WhatsappConnection::create([
            'tenant_id' => $this->tenant->id,
            'session_name' => "vetoros1-{$this->tenant->id}",
            'status' => WhatsappConnection::STATUS_CONNECTED,
        ]);

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    public function test_whatsapp_message_is_recorded_with_provider_id_and_linked_to_sent_budget(): void
    {
        $this->fakeWaha();
        $order = $this->order(OrderStatus::OPEN);
        $this->generateBudget($order);
        $budget = OrderBudget::query()->where('order_id', $order->id)->sole();

        $this->post(route('app.orders.whatsapp.send', $order), ['message' => 'Seu orçamento está pronto', 'template' => 'generatedbudget'])
            ->assertSessionHas('success');

        $message = OrderMessage::query()->where('order_id', $order->id)->sole();
        $this->assertSame(OrderMessage::CHANNEL_WHATSAPP, $message->channel);
        $this->assertSame('generatedbudget', $message->template);
        $this->assertSame('waha', $message->provider);
        $this->assertSame('true_5551999999999@c.us_ABC', $message->provider_message_id);
        $this->assertSame(OrderMessage::STATUS_SENT, $message->status);
        $this->assertSame($budget->id, (int) $message->order_budget_id);
        $this->assertSame('5551999999999@c.us', $message->recipient);
        $this->assertSame($this->user->id, (int) $message->created_by);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => OrderEvent::TYPE_MESSAGE_SENT]);
    }

    public function test_message_without_budget_template_is_only_linked_to_the_order(): void
    {
        $this->fakeWaha();
        $order = $this->order(OrderStatus::SERVICE_COMPLETED);

        $this->post(route('app.orders.whatsapp.send', $order), ['message' => 'Pronto para retirada', 'template' => 'servicecompleted']);

        $message = OrderMessage::query()->where('order_id', $order->id)->sole();
        $this->assertNull($message->order_budget_id);
    }

    public function test_failed_send_is_recorded_without_provider_details(): void
    {
        Http::fake([
            'waha.test/api/sessions/*' => Http::response(['status' => 'WORKING']),
            'waha.test/api/contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '5551999999999@c.us']),
            'waha.test/api/sendText' => Http::response(['message' => 'internal error apiKey=XYZ'], 500),
        ]);
        $order = $this->order(OrderStatus::OPEN);

        $this->post(route('app.orders.whatsapp.send', $order), ['message' => 'Olá', 'template' => 'defaultmessage'])
            ->assertSessionHas('error');

        $message = OrderMessage::query()->where('order_id', $order->id)->sole();
        $this->assertSame(OrderMessage::STATUS_FAILED, $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertStringNotContainsString('apiKey', (string) $message->error_message);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => OrderEvent::TYPE_MESSAGE_FAILED]);
    }

    public function test_waha_ack_updates_status_forward_only(): void
    {
        $message = $this->sentMessage();

        $this->ack($message->provider_message_id, 2)->assertOk()->assertJson(['updated' => true]);
        $this->assertSame(OrderMessage::STATUS_DELIVERED, $message->fresh()->status);
        $this->assertNotNull($message->fresh()->delivered_at);

        $this->ack($message->provider_message_id, 3);
        $this->assertSame(OrderMessage::STATUS_READ, $message->fresh()->status);
        $this->assertNotNull($message->fresh()->read_at);

        // ACK atrasado de "entregue" não faz a mensagem regredir.
        $this->ack($message->provider_message_id, 2);
        $this->assertSame(OrderMessage::STATUS_READ, $message->fresh()->status);
    }

    public function test_waha_webhook_requires_valid_hmac(): void
    {
        $message = $this->sentMessage();
        $body = json_encode(['event' => 'message.ack', 'session' => "vetoros1-{$this->tenant->id}", 'payload' => ['id' => $message->provider_message_id, 'ack' => 3]]);

        $this->call('POST', route('webhook.waha'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_HMAC' => 'invalida'], $body)
            ->assertStatus(401);

        config(['services.waha.webhook_secret' => null]);
        $this->ack($message->provider_message_id, 3)->assertStatus(401);

        $this->assertSame(OrderMessage::STATUS_SENT, $message->fresh()->status);
    }

    public function test_ack_from_another_tenant_session_is_ignored(): void
    {
        $message = $this->sentMessage();
        $otherTenant = Tenant::factory()->create();
        WhatsappConnection::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'session_name' => "vetoros1-{$otherTenant->id}",
            'status' => WhatsappConnection::STATUS_CONNECTED,
        ]);

        $this->ack($message->provider_message_id, 3, "vetoros1-{$otherTenant->id}")->assertJson(['updated' => false]);

        $this->assertSame(OrderMessage::STATUS_SENT, $message->fresh()->status);
    }

    public function test_message_cannot_be_linked_to_other_order_or_tenant(): void
    {
        $order = $this->order(OrderStatus::OPEN);
        $other = $this->order(OrderStatus::OPEN);
        $this->generateBudget($other);
        $otherBudget = OrderBudget::query()->where('order_id', $other->id)->sole();

        try {
            OrderMessage::create([
                'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'order_budget_id' => $otherBudget->id,
                'channel' => 'whatsapp', 'status' => 'sent',
            ]);
            $this->fail('Mensagem não pode apontar orçamento de outra OS.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        OrderMessage::create([
            'tenant_id' => Tenant::factory()->create()->id, 'order_id' => $order->id, 'channel' => 'email', 'status' => 'sent',
        ]);
    }

    public function test_messages_of_one_tenant_are_not_visible_to_another(): void
    {
        $this->sentMessage();

        $otherTenant = Tenant::factory()->create();
        $this->withSession(['tenant_id' => $otherTenant->id])->actingAs(User::factory()->forTenant($otherTenant->id)->create());

        $this->assertSame(0, OrderMessage::query()->count());
    }

    public function test_customer_email_is_recorded_and_linked_to_budget(): void
    {
        Mail::fake();
        Other::query()->where('tenant_id', $this->tenant->id)->update([
            'mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.com', 'mail_port' => 587,
            'mail_username' => 'user@example.com', 'mail_password' => Crypt::encryptString('secret'),
            'mail_encryption' => 'tls', 'mail_from_address' => 'noreply@example.com', 'mail_from_name' => 'VetorOS',
        ]);
        $this->customer->forceFill(['email' => 'cliente@example.com'])->save();
        $order = $this->order(OrderStatus::OPEN);

        $this->generateBudget($order);

        Mail::assertSent(OrderStatusUpdatedMail::class);
        $message = OrderMessage::query()->where('order_id', $order->id)->sole();
        $this->assertSame(OrderMessage::CHANNEL_EMAIL, $message->channel);
        $this->assertSame('budget_generated', $message->template);
        $this->assertSame('cliente@example.com', $message->recipient);
        $this->assertSame(OrderBudget::query()->where('order_id', $order->id)->sole()->id, (int) $message->order_budget_id);
    }

    public function test_sensitive_data_never_reaches_events_audits_or_messages(): void
    {
        $this->fakeWaha();
        $order = $this->order(OrderStatus::OPEN);

        $this->post(route('app.orders.whatsapp.send', $order), ['message' => 'Código secreto 9876, senha do aparelho 1234', 'template' => 'defaultmessage']);

        $events = OrderEvent::query()->where('order_id', $order->id)->get()->toJson();
        $this->assertStringNotContainsString('9876', $events);
        $this->assertStringNotContainsString('5551999999999', $events, 'destinatário não vai para o evento');
        $this->assertStringNotContainsString('9876', OrderMessage::query()->get()->toJson(), 'corpo da mensagem não é armazenado');
        $this->assertStringNotContainsString('9876', OperationalAudit::query()->get()->toJson());
    }

    public function test_waha_session_webhook_config_includes_ack_and_hmac(): void
    {
        config(['services.waha.webhook_url' => 'https://app.test/api/webhooks/waha']);
        Http::fake(['waha.test/api/sessions' => Http::response(['name' => 's', 'status' => 'STARTING'])]);

        app(WahaService::class)->createSession('s');

        Http::assertSent(fn ($request) => in_array('message.ack', $request['config']['webhooks'][0]['events'] ?? [], true)
            && ($request['config']['webhooks'][0]['hmac']['key'] ?? null) === self::SECRET);
    }

    public function test_provider_message_id_extraction_supports_waha_engines(): void
    {
        $this->assertSame('true_1@c.us_A', OrderMessageService::providerMessageId(['id' => 'true_1@c.us_A']));
        $this->assertSame('true_1@c.us_B', OrderMessageService::providerMessageId(['id' => ['_serialized' => 'true_1@c.us_B']]));
        $this->assertSame('C', OrderMessageService::providerMessageId(['key' => ['id' => 'C']]));
        $this->assertNull(OrderMessageService::providerMessageId([]));
    }

    private function sentMessage(): OrderMessage
    {
        $this->fakeWaha();
        $order = $this->order(OrderStatus::OPEN);
        $this->post(route('app.orders.whatsapp.send', $order), ['message' => 'Olá', 'template' => 'defaultmessage']);

        return OrderMessage::query()->where('order_id', $order->id)->sole();
    }

    private function ack(string $id, int $ack, ?string $session = null)
    {
        $body = json_encode([
            'event' => 'message.ack',
            'session' => $session ?? "vetoros1-{$this->tenant->id}",
            'payload' => ['id' => $id, 'ack' => $ack],
        ]);
        $secret = (string) config('services.waha.webhook_secret');

        return $this->call('POST', route('webhook.waha'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, $secret ?: 'x'),
        ], $body);
    }

    private function fakeWaha(): void
    {
        Http::fake([
            'waha.test/api/sessions/*' => Http::response(['status' => 'WORKING']),
            'waha.test/api/contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '5551999999999@c.us']),
            'waha.test/api/sendText' => Http::response(['id' => 'true_5551999999999@c.us_ABC']),
        ]);
    }

    private function generateBudget(Order $order): void
    {
        $this->put(route('app.orders.update', $order), [
            'order_type' => Order::TYPE_EQUIPMENT,
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'user_id' => null,
            'model' => 'Modelo',
            'password' => null,
            'defect' => 'Defeito',
            'state_conservation' => 'Usado',
            'accessories' => 'Nenhum',
            'budget_description' => 'Troca de tela',
            'budget_value' => '300,00',
            'service_value' => '0,00',
            'manual_parts_value' => '0,00',
            'delivery_date' => null,
            'service_status' => OrderStatus::BUDGET_GENERATED,
            'delivery_forecast' => $order->delivery_forecast,
            'services_performed' => null,
            'observations' => null,
        ])->assertSessionHasNoErrors();
    }

    private function order(int $status): Order
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
            'delivery_date' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ]);
    }
}
