<?php

namespace Tests\Feature\Security;

use App\Models\App\Image;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderFiles;
use App\Support\OrderSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * VETOROS-SECURITY-FIX-01 / SEC-02: fotos e assinaturas de OS isoladas por empresa e por OS,
 * em armazenamento privado, entregues só por URL assinada emitida após autorização.
 */
class OrderFilesIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_A = 'conteudo-assinatura-empresa-A';

    private const PNG_B = 'conteudo-assinatura-empresa-B';

    private string $legacyRoot;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $userA;

    private User $userB;

    private Order $orderA;

    private Order $orderB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(OrderFiles::DISK);
        $this->legacyRoot = sys_get_temp_dir().'/vetoros-legacy-orders-'.uniqid();
        File::ensureDirectoryExists($this->legacyRoot);
        config(['order_files.legacy_root' => $this->legacyRoot]);

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
        Other::factory()->forTenant($this->tenantA->id)->create();
        Other::factory()->forTenant($this->tenantB->id)->create();
        $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id, 'roles' => User::ROLE_ADMIN]);
        $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id, 'roles' => User::ROLE_ADMIN]);

        // Mesmo número de OS nas duas empresas: era o que fazia as pastas colidirem.
        $this->orderA = Order::factory()->create(['tenant_id' => $this->tenantA->id, 'order_number' => 15, 'user_id' => null]);
        $this->orderB = Order::factory()->create(['tenant_id' => $this->tenantB->id, 'order_number' => 15, 'user_id' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->legacyRoot);

        parent::tearDown();
    }

    public function test_signatures_of_orders_with_same_number_do_not_collide(): void
    {
        $this->assertTrue(OrderSignature::store($this->orderA, base64_encode(self::PNG_A)));
        $this->assertFalse(OrderSignature::exists($this->orderB), 'A OS de mesmo número da outra empresa não pode herdar a assinatura.');
        $this->assertNull(OrderSignature::url($this->orderB));

        $this->assertTrue(OrderSignature::store($this->orderB, base64_encode(self::PNG_B)));

        $this->assertSame(self::PNG_A, OrderSignature::contents($this->orderA));
        $this->assertSame(self::PNG_B, OrderSignature::contents($this->orderB));
        Storage::disk(OrderFiles::DISK)->assertExists("{$this->tenantA->id}/{$this->orderA->id}/signature.png");
        Storage::disk(OrderFiles::DISK)->assertExists("{$this->tenantB->id}/{$this->orderB->id}/signature.png");
    }

    public function test_signature_is_served_only_by_valid_signed_url(): void
    {
        OrderSignature::store($this->orderA, base64_encode(self::PNG_A));
        $url = OrderSignature::url($this->orderA);

        $this->assertStringNotContainsString('/storage/orders/', $url);
        $this->assertSame(self::PNG_A, $this->get($url)->assertOk()->getContent());

        // Sem assinatura válida, ou trocando a OS na URL, não entrega nada.
        $this->assertDenied($this->get(route('order-files.signature', ['order' => $this->orderA->id])));
        $this->assertDenied($this->get(str_replace('/files/orders/'.$this->orderA->id.'/', '/files/orders/'.$this->orderB->id.'/', $url)));
    }

    public function test_web_upload_goes_to_private_tenant_scoped_storage(): void
    {
        $this->actingAs($this->userA)
            ->post(route('app.images.store'), [
                'order_id' => $this->orderA->id,
                'images' => [UploadedFile::fake()->image('foto.jpg')],
            ])
            ->assertRedirect();

        $image = Image::withoutGlobalScopes()->where('order_id', $this->orderA->id)->firstOrFail();

        $this->assertSame($this->tenantA->id, (int) $image->tenant_id);
        Storage::disk(OrderFiles::DISK)->assertExists("{$this->tenantA->id}/{$this->orderA->id}/images/{$image->filename}");
        $this->assertFileDoesNotExist(public_path("storage/orders/{$this->orderA->order_number}/{$image->filename}"));
        $this->assertStringEndsWith('.jpg', $image->filename);
    }

    public function test_api_uploads_of_two_tenants_never_overwrite_each_other(): void
    {
        $pngA = $this->png(255, 0, 0);
        $pngB = $this->png(0, 0, 255);

        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/upload', ['order_number' => 15, 'filename' => base64_encode($pngA)])
            ->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->userB, 'sanctum')
            ->postJson('/api/upload', ['order_number' => 15, 'filename' => base64_encode($pngB)])
            ->assertOk();

        $imageA = Image::withoutGlobalScopes()->where('order_id', $this->orderA->id)->firstOrFail();
        $imageB = Image::withoutGlobalScopes()->where('order_id', $this->orderB->id)->firstOrFail();

        $this->assertSame($pngA, OrderFiles::imageContents($imageA));
        $this->assertSame($pngB, OrderFiles::imageContents($imageB));
    }

    public function test_api_upload_rejects_content_that_is_not_an_image(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/upload', ['order_number' => 15, 'filename' => base64_encode('<?php echo 1;')])
            ->assertUnprocessable();

        $this->assertFalse(Image::withoutGlobalScopes()->where('order_id', $this->orderA->id)->exists());
    }

    private function assertDenied(TestResponse $response): void
    {
        // 403 do middleware `signed` (o tratador global devolve redirecionamento para navegador).
        $this->assertContains($response->status(), [302, 403]);
        $this->assertStringNotContainsString(self::PNG_A, (string) $response->getContent());
        $this->assertStringNotContainsString(self::PNG_B, (string) $response->getContent());
    }

    private function png(int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_image_url_is_signed_and_cannot_be_reused_for_another_image(): void
    {
        $image = $this->storedImage($this->orderA, self::PNG_A);
        $other = $this->storedImage($this->orderB, self::PNG_B);

        $this->assertSame(self::PNG_A, $this->get($image->url)->assertOk()->getContent());
        $this->assertDenied($this->get(route('order-files.image', ['image' => $image->id])));
        $this->assertDenied($this->get(str_replace('/images/'.$image->id.'?', '/images/'.$other->id.'?', $image->url)));
    }

    public function test_api_image_list_returns_signed_urls_only_for_own_order(): void
    {
        $image = $this->storedImage($this->orderA, self::PNG_A);
        $this->storedImage($this->orderB, self::PNG_B);

        $response = $this->actingAs($this->userA, 'sanctum')->getJson('/api/images/15')->assertOk();

        $this->assertCount(1, $response->json('result'));
        $this->assertSame($image->id, $response->json('result.0.id'));
        $this->assertSame(self::PNG_A, $this->get($response->json('result.0.url'))->assertOk()->getContent());
    }

    public function test_legacy_image_is_still_served_to_its_owner_until_migrated(): void
    {
        File::ensureDirectoryExists($this->legacyRoot.'/15');
        File::put($this->legacyRoot.'/15/legado-a.png', self::PNG_A);
        $image = Image::withoutGlobalScopes()->create(['tenant_id' => $this->tenantA->id, 'order_id' => $this->orderA->id, 'filename' => 'legado-a.png']);

        $this->assertSame(self::PNG_A, $this->get($image->url)->assertOk()->getContent());
    }

    public function test_legacy_signature_is_never_read_from_shared_folder(): void
    {
        File::ensureDirectoryExists($this->legacyRoot.'/15');
        File::put($this->legacyRoot.'/15/signature.png', self::PNG_A);

        // Pasta antiga é ambígua (OS 15 existe nas duas empresas): só o comando de migração decide.
        $this->assertFalse(OrderSignature::exists($this->orderA));
        $this->assertFalse(OrderSignature::exists($this->orderB));
    }

    public function test_deleting_image_removes_only_its_own_file(): void
    {
        $imageA = $this->storedImage($this->orderA, self::PNG_A, 'mesmo-nome.png');
        $imageB = $this->storedImage($this->orderB, self::PNG_B, 'mesmo-nome.png');

        $this->actingAs($this->userA)->delete(route('app.images.destroy', $imageA->id))->assertRedirect();

        $this->assertNull(Image::withoutGlobalScopes()->find($imageA->id));
        $this->assertSame(self::PNG_B, OrderFiles::imageContents($imageB->fresh()));
    }

    public function test_user_cannot_delete_image_of_another_tenant(): void
    {
        $imageB = $this->storedImage($this->orderB, self::PNG_B);

        $this->actingAs($this->userA)->delete(route('app.images.destroy', $imageB->id));

        $this->assertNotNull(Image::withoutGlobalScopes()->find($imageB->id));
        $this->assertSame(self::PNG_B, OrderFiles::imageContents($imageB));
    }

    private function storedImage(Order $order, string $contents, ?string $filename = null): Image
    {
        $filename ??= uniqid('img_').'.png';
        Storage::disk(OrderFiles::DISK)->put(OrderFiles::imagePath($order, $filename), $contents);

        return Image::withoutGlobalScopes()->create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'filename' => $filename,
        ]);
    }
}
