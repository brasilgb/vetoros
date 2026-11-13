<?php

namespace Tests\Feature\App;

use App\Jobs\SendOrderCreatedNotification;
use App\Jobs\SendOrderStatusUpdatedNotification;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\OrderMessage;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * VETOROS-PERFORMANCE-OS-03: OS sem e-mail automático na criação e na mudança de status.
 * (Os testes da verificação rápida da chave pública saíram com a remoção da consulta pública na OS-04.)
 */
class OrderPerformanceOs03Test extends TestCase
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
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create(['email' => 'cliente@example.com']);
        $this->equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])->actingAs($this->user);
    }

    private function configureTenantSmtp(): void
    {
        Other::query()->where('tenant_id', $this->tenant->id)->update([
            'mail_mailer' => 'smtp', 'mail_host' => 'smtp.example.com', 'mail_port' => 587,
            'mail_username' => 'user@example.com', 'mail_password' => Crypt::encryptString('secret'),
            'mail_encryption' => 'tls', 'mail_from_address' => 'noreply@example.com', 'mail_from_name' => 'VetorOS',
        ]);
    }

    public function test_creating_an_order_sends_no_automatic_email(): void
    {
        Mail::fake();
        $this->configureTenantSmtp();

        $this->post(route('app.orders.store'), [
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
            'model' => 'Notebook',
            'defect' => 'Não liga',
            'service_status' => OrderStatus::OPEN,
            'user_id' => null,
            'delivery_forecast' => now()->addDays(7)->toDateString(),
        ])->assertRedirect()->assertSessionHas('success', 'Ordem cadastrada com sucesso');

        Mail::assertNothingSent();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(0, OrderMessage::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'status' => OrderStatus::OPEN]);
    }

    public function test_legacy_queued_email_jobs_are_discarded_without_sending(): void
    {
        Mail::fake();
        $this->configureTenantSmtp();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $this->customer->id,
            'equipment_id' => $this->equipment->id,
        ]);

        (new SendOrderCreatedNotification($order->id))->handle();
        (new SendOrderStatusUpdatedNotification($order->id, 'Em andamento'))->handle();

        Mail::assertNothingSent();
        $this->assertSame(0, OrderMessage::query()->where('order_id', $order->id)->count());
    }
}
