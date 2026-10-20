<?php

namespace Tests\Feature\Security;

use App\Models\App\Image;
use App\Models\App\Order;
use App\Models\Tenant;
use App\Support\OrderFiles;
use App\Support\OrderSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * VETOROS-SECURITY-FIX-01 / SEC-02: migração dos arquivos antigos (public/storage/orders)
 * sem sobrescrever, sem apagar originais e sem atribuir arquivo ambíguo a uma empresa.
 */
class MigrateOrderFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $legacyRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake(OrderFiles::DISK);
        $this->legacyRoot = sys_get_temp_dir().'/vetoros-legacy-migrate-'.uniqid();
        File::ensureDirectoryExists($this->legacyRoot);
        config(['order_files.legacy_root' => $this->legacyRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->legacyRoot);

        parent::tearDown();
    }

    private function legacy(string $relative, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->legacyRoot.'/'.$relative));
        File::put($this->legacyRoot.'/'.$relative, $contents);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id, 'order_number' => 7, 'customer_signature_captured_at' => now()]);
        $this->legacy('7/signature.png', 'assinatura');
        $this->legacy('7/foto.png', 'foto');
        Image::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'filename' => 'foto.png']);

        $this->artisan('vetoros:migrate-order-files')->assertSuccessful();

        $this->assertSame([], Storage::disk(OrderFiles::DISK)->allFiles());
        $this->assertFileExists($this->legacyRoot.'/7/signature.png');
    }

    public function test_unambiguous_files_are_copied_and_originals_kept(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id, 'order_number' => 7, 'customer_signature_captured_at' => now()]);
        // Mesma numeração em outra empresa, mas sem assinatura colhida: não disputa o arquivo.
        Order::factory()->create(['tenant_id' => $other->id, 'order_number' => 7, 'customer_signature_captured_at' => null]);
        $this->legacy('7/signature.png', 'assinatura');
        $this->legacy('7/foto.png', 'foto');
        $image = Image::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'filename' => 'foto.png']);

        $this->artisan('vetoros:migrate-order-files', ['--execute' => true])->assertSuccessful();

        $this->assertSame('assinatura', OrderSignature::contents($order));
        $this->assertSame('foto', Storage::disk(OrderFiles::DISK)->get(OrderFiles::imagePath($order, $image->filename)));
        $this->assertFileExists($this->legacyRoot.'/7/signature.png');
        $this->assertFileExists($this->legacyRoot.'/7/foto.png');
    }

    public function test_ambiguous_signature_is_not_assigned(): void
    {
        $a = Order::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'order_number' => 9, 'customer_signature_captured_at' => now()]);
        $b = Order::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'order_number' => 9, 'customer_signature_captured_at' => now()]);
        $this->legacy('9/signature.png', 'de quem?');

        $this->artisan('vetoros:migrate-order-files', ['--execute' => true])
            ->expectsOutputToContain('ambígu')
            ->assertSuccessful();

        $this->assertFalse(OrderSignature::exists($a));
        $this->assertFalse(OrderSignature::exists($b));
    }

    public function test_ambiguous_image_filename_is_not_copied(): void
    {
        $a = Order::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'order_number' => 4]);
        $b = Order::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'order_number' => 4]);
        $this->legacy('4/1700000000.png', 'colidiu');
        Image::withoutGlobalScopes()->create(['tenant_id' => $a->tenant_id, 'order_id' => $a->id, 'filename' => '1700000000.png']);
        Image::withoutGlobalScopes()->create(['tenant_id' => $b->tenant_id, 'order_id' => $b->id, 'filename' => '1700000000.png']);

        $this->artisan('vetoros:migrate-order-files', ['--execute' => true])->assertSuccessful();

        $this->assertSame([], Storage::disk(OrderFiles::DISK)->allFiles());
    }

    public function test_existing_private_file_is_never_overwritten(): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id, 'order_number' => 3, 'customer_signature_captured_at' => now()]);
        Storage::disk(OrderFiles::DISK)->put(OrderFiles::signaturePath($order), 'nova');
        $this->legacy('3/signature.png', 'antiga');

        $this->artisan('vetoros:migrate-order-files', ['--execute' => true])->assertSuccessful();

        $this->assertSame('nova', OrderSignature::contents($order));
    }
}
