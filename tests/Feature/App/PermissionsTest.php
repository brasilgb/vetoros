<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Image;
use App\Models\App\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $technician;

    private User $otherTechnician;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->technician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);
        $this->otherTechnician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);
        $this->operator = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_OPERATOR,
        ]);

        DB::table('others')->insert([
            'tenant_id' => $this->tenant->id,
            'navigation' => false,
            'enableparts' => false,
            'enable_finance' => true,
            'enablesales' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_technician_can_only_access_images_from_owned_orders(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();

        $ownedOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->technician->id,
        ]);

        $foreignOrder = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->otherTechnician->id,
        ]);

        Image::factory()->forTenant($this->tenant->id)->create(['order_id' => $ownedOrder->id]);
        Image::factory()->forTenant($this->tenant->id)->create(['order_id' => $foreignOrder->id]);

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->technician);

        $this->get(route('app.images.index', ['or' => $ownedOrder->id]))
            ->assertOk();

        $this->get(route('app.images.index', ['or' => $foreignOrder->id]))
            ->assertRedirect()
            ->assertSessionHas('authorization_error', 'Esta ação não é autorizada.');
    }

    public function test_api_images_returns_order_images_from_route_parameter(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->technician->id,
        ]);

        $image = Image::factory()->forTenant($this->tenant->id)->create([
            'order_id' => $order->id,
            'filename' => 'order-image.jpg',
        ]);

        $this->actingAs($this->technician, 'sanctum')
            ->getJson(route('images', $order->order_number))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'result' => [
                    [
                        'id' => $image->id,
                        'order_id' => $order->id,
                        'filename' => 'order-image.jpg',
                    ],
                ],
            ]);
    }

    public function test_api_upload_stores_base64_image_for_order(): void
    {
        $this->app->usePublicPath(storage_path('framework/testing/public'));
        File::deleteDirectory(public_path());

        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => null,
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson(route('upload'), [
                'order_number' => $order->order_number,
                'filename' => base64_encode('image-content'),
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Imagem salva com sucesso',
            ]);

        $image = Image::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame($this->tenant->id, $image->tenant_id);
        $this->assertFileExists(public_path('storage/orders/'.$order->order_number.'/'.$image->filename));
    }

    public function test_api_delete_image_removes_database_record_and_file(): void
    {
        $this->app->usePublicPath(storage_path('framework/testing/public'));
        File::deleteDirectory(public_path());

        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => null,
        ]);
        $image = Image::factory()->forTenant($this->tenant->id)->create([
            'order_id' => $order->id,
            'filename' => 'delete-me.png',
        ]);
        $path = public_path('storage/orders/'.$order->order_number.'/'.$image->filename);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'image-content');

        $this->actingAs($this->operator, 'sanctum')
            ->deleteJson(route('deleteimage', $image))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Imagem deletada com sucesso!',
            ]);

        $this->assertDatabaseMissing('images', ['id' => $image->id]);
        $this->assertFileDoesNotExist($path);
    }

    public function test_technician_cannot_access_receipts_whatsapp_labels_sales_cashier_or_expenses(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => $this->technician->id,
        ]);

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->technician);

        $this->get(route('app.receipts.printing', ['or' => $order->id, 'tp' => 'receivingequipment']))
            ->assertRedirect()
            ->assertSessionHas('authorization_error', 'Esta ação não é autorizada.');

        $this->get(route('app.whatsapp-message.index'))
            ->assertRedirect()
            ->assertSessionHas('authorization_error', 'Esta ação não é autorizada.');

        $this->get(route('app.label-printing.index'))
            ->assertRedirect()
            ->assertSessionHas('authorization_error', 'Esta ação não é autorizada.');

        $this->get(route('app.sales.index'))
            ->assertRedirect(route('app.dashboard'));

        $this->get(route('app.cashier.index'))
            ->assertRedirect(route('app.dashboard'));

        $this->get(route('app.expenses.index'))
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_operator_can_access_sales_cashier_expenses_receipts_whatsapp_and_labels_when_enabled(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create();
        $equipment = Equipment::factory()->forTenant($this->tenant->id)->create();
        $order = Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'user_id' => null,
        ]);

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->operator);

        $this->get(route('app.sales.index'))
            ->assertOk();

        $this->get(route('app.cashier.index'))
            ->assertOk();

        $this->get(route('app.expenses.index'))
            ->assertOk();

        $this->get(route('app.receipts.printing', ['or' => $order->id, 'tp' => 'receivingequipment']))
            ->assertOk();

        $this->get(route('app.whatsapp-message.index'))
            ->assertOk();

        $this->get(route('app.label-printing.index'))
            ->assertOk();
    }
}
