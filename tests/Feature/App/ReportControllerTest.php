<?php

namespace Tests\Feature\App;

use App\Models\App\Customer;
use App\Models\App\Expense;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\App\Order;
use App\Models\App\Part;
use App\Models\App\Sale;
use App\Models\App\Schedule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Test Tenant']);
        $this->user = User::factory()->forTenant($this->tenant->id)->create();

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($this->user);
    }

    public function test_orders_report_exposes_warranty_return_meta(): void
    {
        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => true,
            'created_at' => now()->subDay(),
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => false,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->post(route('app.reports.store'), [
            'type' => 'orders',
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $response
            ->assertOk()
            ->assertViewHas('page.props.reportMeta.warranty_return_threshold', 10.0)
            ->assertViewHas('page.props.reportMeta.warranty_return_rate', 50.0)
            ->assertViewHas('page.props.reportMeta.warranty_return_alert', true)
            ->assertViewHas('page.props.reportMeta.warranty_returns', 1);
    }

    public function test_orders_preview_renders_slim_rows_and_warranty_meta(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['name' => 'Cliente Relatório']);

        Order::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'is_warranty_return' => true,
            'parts_value' => 30,
            'service_value' => 70,
            'created_at' => now()->subDay(),
        ]);

        Order::factory()->forTenant($this->tenant->id)->create([
            'is_warranty_return' => false,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->get(route('app.reports.preview', [
            'type' => 'orders',
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $response
            ->assertOk()
            ->assertViewHas('page.component', 'app/reports/view')
            ->assertViewHas('page.props.reportMeta.warranty_return_rate', 50.0)
            ->assertViewHas('page.props.reportData', function (array $rows): bool {
                $warranty = collect($rows)->firstWhere('is_warranty_return', true);

                return count($rows) === 2
                    && $warranty['parts_value'] === 30.0
                    && $warranty['service_value'] === 70.0
                    && $warranty['customer'] === ['name' => 'Cliente Relatório']
                    && $warranty['user'] === ['name' => $this->user->name];
            })
            ->assertViewHas('page.props.error', null);
    }

    public function test_orders_preview_shows_error_for_too_long_range(): void
    {
        $response = $this->get(route('app.reports.preview', [
            'type' => 'orders',
            'from' => now()->subDays(500)->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $response
            ->assertOk()
            ->assertViewHas('page.props.reportData', [])
            ->assertViewHas('page.props.error', fn ($error) => str_contains((string) $error, '400 dias'));
    }

    public function test_customers_preview_renders_slim_rows(): void
    {
        Customer::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Cliente Novo',
            'email' => 'novo@example.com',
            'created_at' => now()->subDay(),
        ]);

        Customer::factory()->forTenant($this->tenant->id)->create([
            'created_at' => now()->subDays(30),
        ]);

        $response = $this->get(route('app.reports.preview', [
            'type' => 'customers',
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $response
            ->assertOk()
            ->assertViewHas('page.props.type', 'customers')
            ->assertViewHas('page.props.reportData', function (array $rows): bool {
                return count($rows) === 1
                    && $rows[0]['name'] === 'Cliente Novo'
                    && $rows[0]['email'] === 'novo@example.com'
                    && array_keys($rows[0]) === ['id', 'customer_number', 'name', 'email', 'cpfcnpj', 'phone', 'created_at'];
            });
    }

    public function test_schedules_preview_uses_order_service_and_slim_relations(): void
    {
        $customer = Customer::factory()->forTenant($this->tenant->id)->create(['name' => 'Cliente Visita']);
        $order = Order::factory()->forTenant($this->tenant->id)->create(['service_type' => 'Instalação de rede']);

        Schedule::factory()->forTenant($this->tenant->id)->create([
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'order_id' => $order->id,
            'status' => 2,
            'created_at' => now()->subDay(),
        ]);

        $this->get($this->previewUrl('schedules'))
            ->assertOk()
            ->assertViewHas('page.props.reportData', function (array $rows): bool {
                return count($rows) === 1
                    && $rows[0]['service'] === 'Instalação de rede'
                    && $rows[0]['customer'] === ['name' => 'Cliente Visita']
                    && $rows[0]['user'] === ['name' => $this->user->name]
                    && $rows[0]['status'] === 2;
            });
    }

    public function test_sales_preview_includes_items_count_and_expenses_total(): void
    {
        $this->enableSalesAndFinance();

        Sale::factory()->forTenant($this->tenant->id)->create([
            'status' => 'completed',
            'total_amount' => 150,
            'created_at' => now()->subDay(),
        ]);

        $this->createExpense(40);

        $this->get($this->previewUrl('sales'))
            ->assertOk()
            ->assertViewHas('page.props.reportMeta.expenses_total', 40.0)
            ->assertViewHas('page.props.reportData', function (array $rows): bool {
                return count($rows) === 1
                    && $rows[0]['total_amount'] === 150.0
                    && $rows[0]['items_count'] === 0
                    && $rows[0]['customer'] === null;
            });
    }

    public function test_parts_preview_renders_stock_columns(): void
    {
        Part::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Fonte ATX',
            'quantity' => 3,
            'cost_price' => 50,
            'created_at' => now()->subDay(),
        ]);

        $this->get($this->previewUrl('parts'))
            ->assertOk()
            ->assertViewHas('page.props.reportData', function (array $rows): bool {
                return count($rows) === 1
                    && $rows[0]['name'] === 'Fonte ATX'
                    && $rows[0]['quantity'] === 3.0
                    && $rows[0]['cost_price'] === 50.0;
            });
    }

    public function test_expenses_preview_requires_finance_and_exposes_author(): void
    {
        $this->get($this->previewUrl('expenses'))->assertRedirect()->assertSessionHas('authorization_error');

        $this->enableSalesAndFinance();
        $this->createExpense(25);

        $this->get($this->previewUrl('expenses'))
            ->assertOk()
            ->assertViewHas('page.props.reportData', function (array $rows): bool {
                return count($rows) === 1
                    && $rows[0]['amount'] === 25.0
                    && $rows[0]['expense_date'] === now()->subDay()->toDateString()
                    && $rows[0]['created_by'] === ['name' => $this->user->name];
            });
    }

    public function test_fiscal_preview_exposes_counts_and_registered_by(): void
    {
        $order = Order::factory()->forTenant($this->tenant->id)->create();

        FiscalDocument::create([
            'tenant_id' => $this->tenant->id,
            'documentable_type' => Order::class,
            'documentable_id' => $order->id,
            'type' => 'nfse',
            'provider' => 'manual',
            'number' => 'NFSE-001',
            'status' => 'registered',
            'issued_at' => now()->subDay(),
            'registered_by' => $this->user->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->get($this->previewUrl('fiscal'))
            ->assertOk()
            ->assertViewHas('page.props.reportMeta.documents_count', 1)
            ->assertViewHas('page.props.reportMeta.nfse_count', 1)
            ->assertViewHas('page.props.reportData', function (array $rows) use ($order): bool {
                return count($rows) === 1
                    && $rows[0]['documentable_id'] === $order->id
                    && $rows[0]['registered_by'] === ['name' => $this->user->name];
            });
    }

    public function test_preview_denies_users_without_reports_permission_before_range_check(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);

        $this->actingAs($technician)
            ->get(route('app.reports.preview', [
                'type' => 'orders',
                'from' => now()->subDays(500)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            // O handler global transforma 403 em redirect com aviso para requisições de página.
            ->assertRedirect()
            ->assertSessionHas('authorization_error');
    }

    public function test_fiscal_report_exposes_documents_by_domain(): void
    {
        FiscalSetting::create([
            'tenant_id' => $this->tenant->id,
            'enabled' => true,
            'nfe_enabled' => true,
            'nfse_enabled' => true,
        ]);

        $order = Order::factory()->forTenant($this->tenant->id)->create();

        FiscalDocument::create([
            'tenant_id' => $this->tenant->id,
            'documentable_type' => Order::class,
            'documentable_id' => $order->id,
            'type' => 'nfse',
            'provider' => 'manual',
            'number' => 'NFSE-001',
            'status' => 'registered',
            'issued_at' => now()->subDay(),
            'registered_by' => $this->user->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        FiscalDocument::create([
            'tenant_id' => $this->tenant->id,
            'documentable_type' => Order::class,
            'documentable_id' => $order->id,
            'type' => 'nfe',
            'provider' => 'legacy_integration',
            'provider_reference' => 'nfe-1-1',
            'status' => 'error',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->post(route('app.reports.store'), [
            'type' => 'fiscal',
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $response
            ->assertOk()
            ->assertViewHas('page.props.reportData', fn ($documents) => count($documents) === 2)
            ->assertViewHas('page.props.reportMeta.documents_count', 2)
            ->assertViewHas('page.props.reportMeta.manual_count', 1)
            ->assertViewHas('page.props.reportMeta.integration_count', 1)
            ->assertViewHas('page.props.reportMeta.nfe_count', 1)
            ->assertViewHas('page.props.reportMeta.nfse_count', 1)
            ->assertViewHas('page.props.reportMeta.error_count', 1);
    }

    private function previewUrl(string $type): string
    {
        return route('app.reports.preview', [
            'type' => $type,
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    private function enableSalesAndFinance(): void
    {
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

    private function createExpense(float $amount): Expense
    {
        return Expense::create([
            'tenant_id' => $this->tenant->id,
            'expense_number' => (int) Expense::query()->max('expense_number') + 1,
            'created_by' => $this->user->id,
            'expense_date' => now()->subDay()->toDateString(),
            'description' => 'Material de limpeza',
            'category' => 'Operacional',
            'amount' => $amount,
        ]);
    }
}
