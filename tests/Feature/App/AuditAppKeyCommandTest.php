<?php

namespace Tests\Feature\App;

use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\Tenant;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * VETOR-HML-01.1: auditoria da APP_KEY somente leitura, sem exibir segredos.
 */
class AuditAppKeyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_succeeds_when_current_key_reads_everything(): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->forTenant($tenant->id)->create();
        DB::table('orders')->where('id', $order->id)->update([
            'public_access_key' => Crypt::encryptString('ABCD1234'),
            'public_access_key_hash' => Hash::make('ABCD1234'),
        ]);
        Other::factory()->forTenant($tenant->id)->create(['mail_password' => Crypt::encryptString('smtp-secret')]);

        $this->artisan('security:audit-app-key', ['--verify-hash' => true])
            ->expectsOutputToContain('1 de 1 conferem')
            ->doesntExpectOutputToContain('ABCD1234')
            ->doesntExpectOutputToContain('smtp-secret')
            ->assertSuccessful();
    }

    public function test_fails_and_names_tenant_without_showing_values_when_key_differs(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignKey = new Encrypter(random_bytes(32), 'aes-256-cbc');
        $order = Order::factory()->forTenant($tenant->id)->create();
        $cipher = $foreignKey->encryptString('ZZZZ9999');
        DB::table('orders')->where('id', $order->id)->update(['public_access_key' => $cipher]);
        Other::factory()->forTenant($tenant->id)->create(['mail_password' => $foreignKey->encryptString('smtp-secret')]);
        $before = DB::table('orders')->where('id', $order->id)->value('public_access_key');

        $this->artisan('security:audit-app-key')
            ->expectsOutputToContain('não decifram')
            ->doesntExpectOutputToContain('ZZZZ9999')
            ->doesntExpectOutputToContain('smtp-secret')
            ->doesntExpectOutputToContain($cipher)
            ->assertFailed();

        // Somente leitura: nada foi recifrado ou apagado.
        $this->assertSame($before, DB::table('orders')->where('id', $order->id)->value('public_access_key'));
    }
}
