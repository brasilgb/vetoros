<?php

namespace Tests\Feature\App;

use App\Models\App\Other;
use App\Models\Tenant;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * VETOR-HML-01.1: auditoria da APP_KEY somente leitura, sem exibir segredos.
 * (A chave pública das OS deixou de ser auditada na VETOROS-PERFORMANCE-OS-04.)
 */
class AuditAppKeyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_succeeds_when_current_key_reads_everything(): void
    {
        $tenant = Tenant::factory()->create();
        Other::factory()->forTenant($tenant->id)->create(['mail_password' => Crypt::encryptString('smtp-secret')]);

        $this->artisan('security:audit-app-key')
            ->doesntExpectOutputToContain('smtp-secret')
            ->doesntExpectOutputToContain('orders.public_access_key')
            ->assertSuccessful();
    }

    public function test_fails_and_names_tenant_without_showing_values_when_key_differs(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignKey = new Encrypter(random_bytes(32), 'aes-256-cbc');
        $cipher = $foreignKey->encryptString('smtp-secret');
        Other::factory()->forTenant($tenant->id)->create(['mail_password' => $cipher]);
        $before = DB::table('others')->where('tenant_id', $tenant->id)->value('mail_password');

        $this->artisan('security:audit-app-key')
            ->expectsOutputToContain('não decifram')
            ->doesntExpectOutputToContain('smtp-secret')
            ->doesntExpectOutputToContain($cipher)
            ->assertFailed();

        // Somente leitura: nada foi recifrado ou apagado.
        $this->assertSame($before, DB::table('others')->where('tenant_id', $tenant->id)->value('mail_password'));
    }
}
