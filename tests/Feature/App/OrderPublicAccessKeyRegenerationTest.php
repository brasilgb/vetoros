<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\OperationalAudit;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderPublicAccessKeyRegenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_individually_replace_an_unrecoverable_key_and_revoke_the_previous_key(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant->id)->create();
        Other::factory()->forTenant($tenant->id)->create(['public_order_access_key_required' => true]);
        $oldKey = 'ABCD1234';
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => Customer::factory()->forTenant($tenant->id)->create()->id,
            'equipment_id' => Equipment::factory()->forTenant($tenant->id)->create()->id,
            'public_access_key' => $oldKey,
            'public_access_key_hash' => Hash::make($oldKey),
        ]);
        $foreignEncrypter = new Encrypter(random_bytes(32), 'aes-256-cbc');
        DB::table('orders')->where('id', $order->id)->update([
            'public_access_key' => $foreignEncrypter->encryptString($oldKey),
        ]);

        $this->withSession(['tenant_id' => $tenant->id])
            ->actingAs($admin)
            ->from(route('app.orders.show', $order))
            ->post(route('app.orders.public-access-key.regenerate', $order), [
                'reason' => 'Cliente informou chave ABCD1234 perdida.',
            ])
            ->assertRedirect(route('app.orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();
        $newKey = $order->public_access_key;
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $newKey);
        $this->assertNotSame($newKey, DB::table('orders')->where('id', $order->id)->value('public_access_key'));
        $this->assertTrue(Hash::check($newKey, $order->public_access_key_hash));
        $this->assertFalse(Hash::check($oldKey, $order->public_access_key_hash));

        $this->post(route('os.access', $order->tracking_token), ['key' => $oldKey])->assertSessionHasErrors('key');
        $this->post(route('os.access', $order->tracking_token), ['key' => $newKey])
            ->assertRedirect(route('os.token', $order->tracking_token));

        $audit = OperationalAudit::query()
            ->where('entity_type', 'order')
            ->where('entity_id', $order->id)
            ->where('action', 'public_access_key_regenerated')
            ->firstOrFail();

        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('Cliente informou chave [redigido] perdida.', $audit->data['reason']);
        $this->assertTrue($audit->data['previous_key_revoked']);
        $this->assertStringNotContainsString($newKey, json_encode($audit->data));
        $this->assertNotNull($audit->created_at);
    }

    public function test_operator_cannot_regenerate_a_key(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_OPERATOR]);
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => Customer::factory()->forTenant($tenant->id)->create()->id,
            'equipment_id' => Equipment::factory()->forTenant($tenant->id)->create()->id,
        ]);
        $foreignEncrypter = new Encrypter(random_bytes(32), 'aes-256-cbc');
        DB::table('orders')->where('id', $order->id)->update([
            'public_access_key' => $foreignEncrypter->encryptString('ABCD1234'),
        ]);

        $this->withSession(['tenant_id' => $tenant->id])
            ->actingAs($operator)
            ->postJson(route('app.orders.public-access-key.regenerate', $order), ['reason' => 'Recuperação autorizada.'])
            ->assertForbidden();

        $this->assertDatabaseMissing('operational_audits', [
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'public_access_key_regenerated',
        ]);
    }

    public function test_order_binding_prevents_regeneration_across_tenants(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant->id)->create();
        $foreignOrder = Order::factory()->forTenant($otherTenant->id)->create([
            'customer_id' => Customer::factory()->forTenant($otherTenant->id)->create()->id,
            'equipment_id' => Equipment::factory()->forTenant($otherTenant->id)->create()->id,
        ]);

        $this->withSession(['tenant_id' => $tenant->id])
            ->actingAs($admin)
            ->postJson(route('app.orders.public-access-key.regenerate', $foreignOrder), ['reason' => 'Recuperação autorizada.'])
            ->assertNotFound();
    }

    public function test_regeneration_requires_a_reason_and_does_not_replace_a_recoverable_key(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant->id)->create();
        $key = 'ZXCV9876';
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => Customer::factory()->forTenant($tenant->id)->create()->id,
            'equipment_id' => Equipment::factory()->forTenant($tenant->id)->create()->id,
            'public_access_key' => $key,
            'public_access_key_hash' => Hash::make($key),
        ]);

        $this->withSession(['tenant_id' => $tenant->id])
            ->actingAs($admin)
            ->post(route('app.orders.public-access-key.regenerate', $order), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->post(route('app.orders.public-access-key.regenerate', $order), ['reason' => 'Tentativa repetida autorizada.'])
            ->assertStatus(409);

        $this->assertSame($key, $order->fresh()->public_access_key);
        $this->assertDatabaseMissing('operational_audits', [
            'entity_type' => 'order',
            'entity_id' => $order->id,
            'action' => 'public_access_key_regenerated',
        ]);
    }

    public function test_replayed_stale_regeneration_request_cannot_replace_the_new_key_twice(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant->id)->create();
        $order = Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => Customer::factory()->forTenant($tenant->id)->create()->id,
            'equipment_id' => Equipment::factory()->forTenant($tenant->id)->create()->id,
        ]);
        $foreignEncrypter = new Encrypter(random_bytes(32), 'aes-256-cbc');
        DB::table('orders')->where('id', $order->id)->update([
            'public_access_key' => $foreignEncrypter->encryptString('ABCD1234'),
        ]);
        $this->withSession(['tenant_id' => $tenant->id])->actingAs($admin);
        $route = route('app.orders.public-access-key.regenerate', $order);
        $payload = ['reason' => 'Recuperação autorizada pelo suporte.'];

        $this->post($route, $payload)->assertRedirect();
        $firstNewKey = $order->fresh()->public_access_key;
        $this->post($route, $payload)->assertStatus(409);

        $this->assertSame($firstNewKey, $order->fresh()->public_access_key);
        $this->assertSame(1, OperationalAudit::query()->where('action', 'public_access_key_regenerated')->count());
    }
}
