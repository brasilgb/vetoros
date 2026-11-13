<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsappMessageTemplateService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * VETOROS-PERFORMANCE-OS-04: remoção da consulta pública de OS (portal /os/{token}, chave de
 * acesso com bcrypt/HMAC e links de acompanhamento), sem afetar as funções administrativas.
 */
class OrderPerformanceOs04Test extends TestCase
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
        $this->user = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        Other::factory()->forTenant($this->tenant->id)->create(['enable_finance' => true]);
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create(['name' => 'Cliente Sigiloso']);
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
    }

    private function order(): Order
    {
        return Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'model' => 'Modelo Confidencial',
            'defect' => 'Defeito Confidencial',
        ]);
    }

    public function test_old_public_links_answer_410_without_any_order_data(): void
    {
        $order = $this->order();
        $token = $order->tracking_token;

        foreach (['', '/receipt/orentrega', '/payment-proof', '/fiscal-proof', '/fiscal-proof/pdf'] as $suffix) {
            $response = $this->get("/os/{$token}{$suffix}");

            $response->assertStatus(410)
                ->assertSee('Consulta de ordem de serviço indisponível')
                ->assertDontSee('Cliente Sigiloso')
                ->assertDontSee('Modelo Confidencial')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_customer_actions_through_old_links_change_nothing(): void
    {
        $order = $this->order();
        $order->forceFill(['service_status' => OrderStatus::BUDGET_GENERATED])->save();
        $token = $order->tracking_token;

        $this->post("/os/{$token}/access", ['key' => 'ABCDEFGH'])->assertStatus(410);
        $this->post("/os/{$token}/budget-status", ['status' => OrderStatus::BUDGET_APPROVED])->assertStatus(410);
        $this->post("/os/{$token}/acknowledge-notification")->assertStatus(410);
        $this->post("/os/{$token}/acknowledge-pickup")->assertStatus(410);
        $this->post("/os/{$token}/feedback", ['rating' => 5])->assertStatus(410);

        $order->refresh();
        $this->assertSame(OrderStatus::BUDGET_GENERATED, (int) $order->service_status);
        $this->assertNull($order->customer_notification_acknowledged_at);
        $this->assertNull($order->customer_pickup_acknowledged_at);
        $this->assertNull($order->customer_feedback_submitted_at);
    }

    public function test_no_public_order_route_remains_registered(): void
    {
        foreach (['os.token', 'os.access', 'orders.budget.status', 'orders.notification.acknowledge', 'orders.pickup.acknowledge',
            'os.receipt', 'os.payment-proof', 'os.fiscal-proof', 'os.fiscal-proof.pdf', 'os.feedback.submit',
            'app.orders.public-access-key.regenerate'] as $name) {
            $this->assertFalse(Route::has($name), "Rota {$name} ainda registrada.");
        }

        $osRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'os/'));
        $this->assertCount(1, $osRoutes);
        $this->assertSame('os.unavailable', $osRoutes->first()->getName());
    }

    public function test_creating_an_order_generates_no_public_access_key(): void
    {
        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);

        $this->post(route('app.orders.store'), [
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'model' => 'Notebook',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ])->assertRedirect()->assertSessionHas('success', 'Ordem cadastrada com sucesso');

        $row = DB::table('orders')->latest('id')->first();
        $this->assertNull($row->public_access_key);
        $this->assertNull($row->public_access_key_hash);
        $this->assertNotEmpty($row->tracking_token);
    }

    public function test_admin_order_pages_keep_working_and_do_not_expose_public_access_data(): void
    {
        $order = $this->order();
        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);

        $this->get(route('app.orders.index'))->assertOk()
            ->assertDontSee($order->tracking_token)
            ->assertDontSee('public_access_key_value');

        $this->get(route('app.orders.show', $order))->assertOk()
            ->assertDontSee($order->tracking_token)
            ->assertDontSee('publicAccessKey');

        $this->assertArrayNotHasKey('tracking_token', $order->toArray());
    }

    public function test_link_os_lines_are_dropped_and_other_variables_are_kept(): void
    {
        $template = "{{ saudacao }}, {{ cliente }}!\nSua OS {{ ordem }} tem saldo de {{ saldo }}.\nAcompanhe pelo link: {{ link_os }}\nObrigado.";

        $rendered = app(WhatsappMessageTemplateService::class)->render($template, [
            'saudacao' => 'Bom dia', 'cliente' => 'Ana', 'ordem' => '15', 'saldo' => 'R$ 10,00', 'link_os' => 'https://x/os/abc',
        ]);

        $this->assertSame("Bom dia, Ana!\nSua OS 15 tem saldo de R$ 10,00.\nObrigado.", $rendered);

        foreach (app(WhatsappMessageTemplateService::class)->defaultMessages() as $default) {
            $this->assertStringNotContainsString('link_os', $default);
        }
    }
}
