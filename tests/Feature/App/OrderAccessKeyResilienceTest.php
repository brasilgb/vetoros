<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regressão: chave de acesso público criptografada com outra APP_KEY (banco vindo de outro
 * ambiente ou chave regenerada) derrubava a listagem e a tela da OS com "The MAC is invalid".
 */
class OrderAccessKeyResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_open_even_when_access_key_was_encrypted_with_another_app_key(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant->id)->create();
        Other::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => Customer::factory()->forTenant($tenant->id)->create()->id,
            'equipment_id' => Equipment::factory()->forTenant($tenant->id)->create()->id,
            'service_status' => OrderStatus::OPEN,
        ]);

        $foreignKey = new Encrypter(random_bytes(32), 'aes-256-cbc');
        DB::table('orders')->where('id', $order->id)->update(['public_access_key' => $foreignKey->encryptString('ABCD1234')]);

        $this->withSession(['tenant_id' => $tenant->id])->actingAs($user);

        $this->get(route('app.orders.index'))->assertOk();
        $this->get(route('app.orders.show', $order))->assertOk();
        $this->assertNull($order->fresh()->public_access_key);
    }

    public function test_access_key_written_now_is_encrypted_and_readable(): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->forTenant($tenant->id)->create(['public_access_key' => 'ZXCV9876']);

        $this->assertNotSame('ZXCV9876', DB::table('orders')->where('id', $order->id)->value('public_access_key'));
        $this->assertSame('ZXCV9876', $order->fresh()->public_access_key);
    }
}
