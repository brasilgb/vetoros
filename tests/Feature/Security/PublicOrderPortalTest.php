<?php

namespace Tests\Feature\Security;

use App\Models\App\Company;
use App\Models\App\Customer;
use App\Models\App\Order;
use App\Models\App\OrderPayment;
use App\Models\App\Other;
use App\Models\App\Part;
use App\Models\Tenant;
use App\Support\OrderFiles;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VETOROS-SECURITY-FIX-01 / SEC-03: o portal público da OS só recebe campos expressamente
 * permitidos; senha do aparelho, custos internos e anotações internas nunca saem.
 */
class PublicOrderPortalTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_ORDER_KEYS = [
        'password',
        'manual_parts_cost',
        'technician_observations',
        'technician_diagnosis',
        'technician_local_payment_notes',
        'customer_feedback_recovery_notes',
        'customer_feedback_recovery_status',
        'budget_follow_up_pause_reason',
        'payment_follow_up_pause_reason',
        'observations',
        'public_access_key',
        'public_access_key_hash',
        'fiscal_registered_by',
        'tenant_id',
        'user_id',
    ];

    private Tenant $tenant;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(OrderFiles::DISK);
        $this->tenant = Tenant::factory()->create();
        Other::factory()->forTenant($this->tenant->id)->create(['public_order_access_key_required' => false]);
        Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Final',
            'cpfcnpj' => '12345678909',
            'email' => 'cliente@example.test',
            'observations' => 'Cliente difícil (anotação interna)',
        ]);

        $this->order = Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'tracking_token' => (string) Str::uuid(),
            'password' => 'SENHA-DO-APARELHO-123',
            'manual_parts_cost' => 37.5,
            'technician_observations' => 'Observação interna do técnico',
            'customer_feedback_recovery_notes' => 'Nota interna de recuperação',
            'observations' => 'Observação interna',
            'service_status' => OrderStatus::SERVICE_COMPLETED,
            'service_cost' => 250,
            'budget_value' => 250,
            'budget_description' => 'Troca de tela',
        ]);
    }

    private function props(string $uri): array
    {
        $response = $this->get($uri)->assertOk();

        return $response->viewData('page')['props'];
    }

    private function assertNoForbiddenData(array $order): void
    {
        foreach (self::FORBIDDEN_ORDER_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $order, "Campo interno exposto no portal: {$key}");
        }

        $json = json_encode($order);
        $this->assertStringNotContainsString('SENHA-DO-APARELHO-123', $json);
        $this->assertStringNotContainsString('interna', $json);
    }

    public function test_tracking_page_exposes_only_allowed_fields(): void
    {
        $props = $this->props('/os/'.$this->order->tracking_token);
        $order = $props['order'];

        $this->assertNoForbiddenData($order);
        $this->assertSame(['name'], array_keys($order['customer']));
        $this->assertSame((int) $this->order->order_number, (int) $order['order_number']);
        $this->assertSame(250.0, (float) $order['service_cost'], 'O total cobrado do cliente continua visível.');
        $this->assertSame('Troca de tela', $order['budget_description']);
        $this->assertEqualsCanonicalizing(['cnpj', 'companyname', 'shortname', 'logo', 'whatsapp'], array_keys($props['company']));
    }

    public function test_tracking_page_images_use_signed_urls(): void
    {
        Storage::disk(OrderFiles::DISK)->put(OrderFiles::imagePath($this->order, 'foto.png'), 'png');
        DB::table('images')->insert(['tenant_id' => $this->tenant->id, 'order_id' => $this->order->id, 'filename' => 'foto.png', 'created_at' => now(), 'updated_at' => now()]);

        $images = $this->props('/os/'.$this->order->tracking_token)['order']['images'];

        $this->assertCount(1, $images);
        $this->assertStringContainsString('signature=', $images[0]['url']);
        $this->get($images[0]['url'])->assertOk();
    }

    public function test_receipt_page_hides_internal_and_part_cost_data(): void
    {
        $part = Part::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Tela', 'sale_price' => 120, 'cost_price' => 55]);
        DB::table('order_parts')->insert(['order_id' => $this->order->id, 'part_id' => $part->id, 'quantity' => 1]);

        $order = $this->props('/os/'.$this->order->tracking_token.'/receipt/orentrega')['order'];

        $this->assertNoForbiddenData($order);
        $this->assertSame('12345678909', $order['customer']['cpfcnpj'], 'O recibo continua identificando o cliente.');
        $this->assertArrayNotHasKey('email', $order['customer']);
        $this->assertArrayNotHasKey('observations', $order['customer']);
        $this->assertSame('Tela', $order['order_parts'][0]['name']);
        $this->assertArrayNotHasKey('cost_price', $order['order_parts'][0]);
        $this->assertSame(1, (int) $order['order_parts'][0]['pivot']['quantity']);
    }

    public function test_payment_and_fiscal_proofs_hide_internal_data(): void
    {
        OrderPayment::query()->create(['order_id' => $this->order->id, 'amount' => 250, 'payment_method' => 'pix', 'paid_at' => now()]);
        $this->order->forceFill(['fiscal_document_number' => '123', 'fiscal_notes' => 'Nota fiscal manual'])->save();

        $payment = $this->props('/os/'.$this->order->tracking_token.'/payment-proof')['order'];
        $fiscal = $this->props('/os/'.$this->order->tracking_token.'/fiscal-proof')['order'];

        $this->assertNoForbiddenData($payment);
        $this->assertNoForbiddenData($fiscal);
        $this->assertCount(1, $payment['order_payments']);
        $this->assertSame('123', $fiscal['fiscal_document_number']);
    }

    public function test_access_key_still_blocks_data_until_validated(): void
    {
        Other::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['public_order_access_key_required' => true]);
        $this->order->forceFill(['public_access_key_hash' => Hash::make('ABCD1234')])->save();

        $props = $this->props('/os/'.$this->order->tracking_token);
        $this->assertArrayNotHasKey('order', $props);
        $receipt = $this->get('/os/'.$this->order->tracking_token.'/receipt/orentrega');
        $this->assertContains($receipt->status(), [302, 403], 'Recibo bloqueado sem a chave (o tratador global redireciona o 403).');
        $this->assertStringNotContainsString('SENHA-DO-APARELHO-123', (string) $receipt->getContent());

        $this->post('/os/'.$this->order->tracking_token.'/access', ['key' => 'abcd1234'])->assertRedirect();

        $this->assertNoForbiddenData($this->props('/os/'.$this->order->tracking_token)['order']);
    }
}
