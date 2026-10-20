<?php

namespace Tests\Feature\Security;

use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\App\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * VETOROS-SECURITY-FIX-01 / SEC-04: o total da venda é calculado no servidor e o cancelamento
 * concorrente não devolve estoque em dobro.
 */
class SaleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'roles' => User::ROLE_ADMIN]);
        DB::table('others')->insert(['tenant_id' => $tenant->id, 'navigation' => false, 'enableparts' => false, 'enablesales' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->withSession(['tenant_id' => $tenant->id])->actingAs($this->user);
        $this->customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $this->part = Part::factory()->create(['tenant_id' => $tenant->id, 'quantity' => 10, 'sale_price' => 100]);
        CashSession::create(['tenant_id' => $tenant->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'opening_balance' => 0, 'status' => 'open']);
    }

    private function sell(array $overrides = [])
    {
        return $this->postJson(route('app.sales.store'), array_merge([
            'customer_id' => $this->customer->id,
            'total_amount' => 200,
            'paid_amount' => 200,
            'payment_method' => 'pix',
            'parts' => [['part_id' => $this->part->id, 'quantity' => 2]],
        ], $overrides));
    }

    public function test_manipulated_total_is_rejected_and_stock_untouched(): void
    {
        foreach ([0.01, 199.0, 1000] as $total) {
            $this->sell(['total_amount' => $total, 'paid_amount' => 0])->assertStatus(422);
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(10, (int) $this->part->fresh()->quantity);
    }

    public function test_total_is_calculated_from_current_part_prices(): void
    {
        $this->sell()->assertOk()->assertJsonPath('sale.total_amount', 200);

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(200.0, (float) $sale->total_amount);
        $this->assertSame(200.0, (float) $sale->items()->sum(DB::raw('unit_price * quantity')));
        $this->assertSame(8, (int) $this->part->fresh()->quantity);
    }

    public function test_repeated_part_lines_are_priced_and_checked_together(): void
    {
        $this->sell([
            'total_amount' => 300,
            'paid_amount' => 300,
            'parts' => [['part_id' => $this->part->id, 'quantity' => 2], ['part_id' => $this->part->id, 'quantity' => 1]],
        ])->assertOk();

        $this->assertSame(7, (int) $this->part->fresh()->quantity);
    }

    public function test_paid_amount_cannot_exceed_server_total(): void
    {
        $this->sell(['paid_amount' => 250])->assertStatus(422);

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_discount_is_not_accepted(): void
    {
        // Não existe regra de desconto no PDV: qualquer desconto enviado é recusado.
        $this->sell(['total_amount' => 180, 'paid_amount' => 180, 'discount_amount' => 20])->assertStatus(422);

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_stale_concurrent_cancel_does_not_return_stock_twice(): void
    {
        $this->sell()->assertOk();
        $sale = Sale::query()->firstOrFail();

        // Duas requisições leram a venda ainda "completed" antes de qualquer uma cancelar.
        $first = Sale::query()->findOrFail($sale->id);
        $stale = Sale::query()->findOrFail($sale->id);
        $service = app(SaleService::class);

        $service->cancel($first, 'Cliente desistiu da compra', $this->user);

        try {
            $service->cancel($stale, 'Cliente desistiu da compra', $this->user);
            $this->fail('O segundo cancelamento deveria ser recusado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('já está cancelada', $exception->getMessage());
        }

        $this->assertSame(10, (int) $this->part->fresh()->quantity);
        $this->assertSame(1, PartMovement::query()->where('movement_type', PartMovement::TYPE_RETURN)->count());
    }
}
