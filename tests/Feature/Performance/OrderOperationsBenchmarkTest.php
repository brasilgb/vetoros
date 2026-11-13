<?php

/*
 * Benchmark das operações de OS (VETOROS-PERFORMANCE-OS-02).
 *
 * Fica fora da suíte normal: só roda com BENCH_ORDERS definido. Exemplo (MySQL dedicado):
 *   BENCH_ORDERS=500,2000,10000 BENCH_RUNS=15 vendor/bin/pest -c phpunit.bench.xml \
 *       tests/Feature/Performance/OrderOperationsBenchmarkTest.php
 *
 * Mede no processo (kernel HTTP de teste): banco + PHP + serialização Inertia/JSON. Não mede
 * rede nem renderização do navegador. Usa bcrypt com o custo de produção (12) nas chaves
 * públicas das OS, que é o que faz diferença na listagem.
 */

use App\Models\App\Equipment;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function benchInsert(string $table, array $rows): void
{
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table($table)->insert($chunk);
    }
}

function benchPercentile(array $values, float $p): float
{
    sort($values);

    return $values[(int) ceil((count($values) - 1) * $p)];
}

test('benchmark de operações de OS', function () {
    $sizes = array_filter(array_map('intval', explode(',', (string) getenv('BENCH_ORDERS'))));
    if ($sizes === []) {
        $this->markTestSkipped('Defina BENCH_ORDERS para rodar o benchmark.');
    }
    $runs = max(3, (int) (getenv('BENCH_RUNS') ?: 10));
    $lines = [];
    $now = now();

    foreach ($sizes as $size) {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        Other::factory()->forTenant($tenant->id)->create(['enable_finance' => true]);

        $customerCount = max(50, intdiv($size, 3));
        $customers = [];
        for ($i = 1; $i <= $customerCount; $i++) {
            $customers[] = [
                'tenant_id' => $tenant->id, 'customer_number' => $i, 'name' => 'Cliente '.$i,
                'cpfcnpj' => str_pad((string) $i, 11, '0', STR_PAD_LEFT), 'phone' => '5199'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'whatsapp' => '5199'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'email' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        benchInsert('customers', $customers);
        $customerIds = DB::table('customers')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('id')->all();
        $equipments = Equipment::factory()->forTenant($tenant->id)->count(15)->create();
        $equipmentIds = $equipments->pluck('id')->all();

        $parts = [];
        for ($i = 1; $i <= 300; $i++) {
            $parts[] = [
                'tenant_id' => $tenant->id, 'type' => 'part', 'is_sellable' => 1, 'category' => 'Geral',
                'part_number' => $i, 'reference_number' => 'REF'.$i, 'name' => 'Peça '.$i,
                'cost_price' => 10, 'sale_price' => 20, 'quantity' => 1000, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        benchInsert('parts', array_map(fn ($r) => array_intersect_key($r, array_flip(DB::getSchemaBuilder()->getColumnListing('parts'))), $parts));
        $partIds = DB::table('parts')->where('tenant_id', $tenant->id)->pluck('id')->all();

        $plainKey = 'ABCD1234';
        $keyHash = Hash::make($plainKey, ['rounds' => 12]);
        $keyCipher = Crypt::encryptString($plainKey);
        $statuses = OrderStatus::values();
        $orders = [];
        for ($i = 0; $i < $size; $i++) {
            $status = $statuses[$i % count($statuses)];
            $created = $now->copy()->subMinutes(($size - $i) * 30);
            $delivered = $status === OrderStatus::DELIVERED ? $created->copy()->addDays(3) : null;
            $orders[] = [
                'tenant_id' => $tenant->id, 'customer_id' => $customerIds[$i % count($customerIds)],
                'equipment_id' => $equipmentIds[$i % count($equipmentIds)], 'order_type' => 'equipment',
                'order_number' => $i + 1, 'tracking_token' => (string) Str::uuid(),
                'model' => 'Modelo '.($i % 97), 'defect' => 'Não liga', 'service_status' => $status,
                'parts_value' => 100, 'service_value' => 150, 'service_cost' => 250,
                'delivery_date' => $delivered, 'delivery_forecast' => $created->copy()->addDays(5)->toDateString(),
                'warranty_days' => $delivered ? 90 : null, 'warranty_expires_at' => $delivered?->copy()->addDays(90),
                'public_access_key' => $keyCipher, 'public_access_key_hash' => $keyHash,
                'created_at' => $created, 'updated_at' => $created,
            ];
        }
        benchInsert('orders', $orders);
        $orderRows = DB::table('orders')->where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'service_status', 'created_at']);

        $history = [];
        $logs = [];
        $payments = [];
        foreach ($orderRows as $row) {
            $history[] = ['tenant_id' => $tenant->id, 'order_id' => $row->id, 'status' => OrderStatus::OPEN, 'note' => 'Aberta', 'created_at' => $row->created_at, 'updated_at' => $row->created_at];
            $history[] = ['tenant_id' => $tenant->id, 'order_id' => $row->id, 'status' => $row->service_status, 'note' => 'Status', 'created_at' => $row->created_at, 'updated_at' => $row->created_at];
            $logs[] = ['order_id' => $row->id, 'action' => 'created', 'created_at' => $row->created_at];
            $logs[] = ['order_id' => $row->id, 'action' => 'updated', 'created_at' => $row->created_at];
            if ((int) $row->service_status === OrderStatus::DELIVERED) {
                $payments[] = ['tenant_id' => $tenant->id, 'order_id' => $row->id, 'amount' => 100, 'payment_method' => 'pix', 'paid_at' => $row->created_at, 'created_at' => $row->created_at, 'updated_at' => $row->created_at];
            }
        }
        $filter = fn (string $table, array $rows) => array_map(
            fn ($r) => array_intersect_key($r, array_flip(DB::getSchemaBuilder()->getColumnListing($table))),
            $rows,
        );
        benchInsert('order_status_history', $filter('order_status_history', $history));
        benchInsert('order_logs', $filter('order_logs', $logs));
        benchInsert('order_payments', $filter('order_payments', $payments));

        // OS "pesada": muitas peças, histórico, mensagens, orçamentos e pagamentos.
        $heavyId = (int) $orderRows[intdiv($size, 2)]->id;
        $simpleId = (int) $orderRows[intdiv($size, 3)]->id;
        $heavyParts = array_map(fn ($partId) => ['order_id' => $heavyId, 'part_id' => $partId, 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now], array_slice($partIds, 0, 30));
        benchInsert('order_parts', $filter('order_parts', array_map(fn ($r) => $r + ['tenant_id' => $tenant->id], $heavyParts)));
        $heavyHistory = [];
        $heavyLogs = [];
        $heavyMessages = [];
        for ($i = 0; $i < 300; $i++) {
            $heavyHistory[] = ['tenant_id' => $tenant->id, 'order_id' => $heavyId, 'status' => OrderStatus::REPAIR_IN_PROGRESS, 'note' => 'Hist '.$i, 'created_at' => $now, 'updated_at' => $now];
            $heavyLogs[] = ['order_id' => $heavyId, 'action' => 'updated', 'data' => json_encode(['i' => $i]), 'created_at' => $now];
            $heavyMessages[] = ['tenant_id' => $tenant->id, 'order_id' => $heavyId, 'channel' => 'email', 'status' => 'sent', 'recipient' => 'c@example.test', 'created_at' => $now, 'updated_at' => $now];
        }
        benchInsert('order_status_history', $filter('order_status_history', $heavyHistory));
        benchInsert('order_logs', $filter('order_logs', $heavyLogs));
        benchInsert('order_messages', $filter('order_messages', $heavyMessages));
        for ($v = 1; $v <= 15; $v++) {
            $budgetId = DB::table('order_budgets')->insertGetId(array_intersect_key(
                ['tenant_id' => $tenant->id, 'order_id' => $heavyId, 'version' => $v, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
                array_flip(DB::getSchemaBuilder()->getColumnListing('order_budgets')),
            ));
            $items = [];
            for ($k = 0; $k < 10; $k++) {
                $items[] = ['tenant_id' => $tenant->id, 'order_budget_id' => $budgetId, 'item_type' => 'service', 'description' => 'Item '.$k, 'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'created_at' => $now, 'updated_at' => $now];
            }
            benchInsert('order_budget_items', $filter('order_budget_items', $items));
        }

        $this->withSession(['tenant_id' => $tenant->id])->actingAs($user);
        $openOrders = $orderRows->where('service_status', OrderStatus::OPEN)->pluck('id')->values()->all();
        $customerId = $customerIds[0];
        $equipmentId = $equipmentIds[0];

        $updatePayload = fn (int $orderId, int $status) => [
            'customer_id' => DB::table('orders')->where('id', $orderId)->value('customer_id'),
            'equipment_id' => DB::table('orders')->where('id', $orderId)->value('equipment_id'),
            'user_id' => $user->id, 'model' => 'Modelo editado', 'password' => null, 'defect' => 'Não liga',
            'state_conservation' => null, 'accessories' => null, 'budget_description' => 'Troca de componente',
            'budget_value' => '150,00', 'services_performed' => null, 'parts_value' => '0,00',
            'service_value' => '150,00', 'service_cost' => '150,00', 'delivery_date' => null,
            'service_status' => $status, 'delivery_forecast' => now()->addDays(7)->toDateString(),
            'observations' => 'Benchmark',
        ];

        $scenarios = [
            'listar' => fn () => $this->get('/app/orders'),
            'listar_status' => fn () => $this->get('/app/orders?status='.OrderStatus::BUDGET_GENERATED),
            'listar_busca' => fn () => $this->get('/app/orders?search=Cliente%2012'),
            'listar_pag_final' => fn () => $this->get('/app/orders?page=99999'),
            'abrir_simples' => fn () => $this->get('/app/orders/'.$simpleId),
            'abrir_pesada' => fn () => $this->get('/app/orders/'.$heavyId),
            'form_nova' => fn () => $this->get('/app/orders/create'),
            'buscar_cliente' => fn () => $this->getJson('/app/customers/search?q=Cliente%201'),
            'buscar_equip' => fn () => $this->getJson('/app/customer-equipments/search?customer_id='.$customerId),
            'salvar_nova' => fn () => $this->post('/app/orders', [
                'customer_id' => $customerId, 'equipment_id' => $equipmentId, 'model' => 'Notebook',
                'defect' => 'Não liga', 'service_status' => OrderStatus::OPEN, 'user_id' => null,
                'delivery_forecast' => now()->addDays(7)->toDateString(),
            ]),
            'editar_salvar' => fn () => $this->put('/app/orders/'.$simpleId, $updatePayload($simpleId, (int) DB::table('orders')->where('id', $simpleId)->value('service_status'))),
            'mudar_status' => function () use (&$openOrders, $updatePayload) {
                $id = array_shift($openOrders);

                return $this->put('/app/orders/'.$id, $updatePayload($id, OrderStatus::BUDGET_GENERATED));
            },
        ];

        foreach ($scenarios as $label => $call) {
            $times = [];
            $queries = [];
            $dbTimes = [];
            $bytes = 0;
            $mem = 0;
            $status = 0;
            for ($r = 0; $r < $runs + 1; $r++) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                memory_reset_peak_usage();
                $start = hrtime(true);
                $response = $call();
                $elapsed = (hrtime(true) - $start) / 1e6;
                $log = DB::getQueryLog();
                DB::disableQueryLog();
                $status = $response->getStatusCode();
                if ($r === 0) {
                    continue; // aquecimento (autoload/opcache)
                }
                $times[] = $elapsed;
                $queries[] = count($log);
                $dbTimes[] = array_sum(array_column($log, 'time'));
                $bytes = strlen((string) $response->getContent());
                $mem = memory_get_peak_usage(true);
            }
            $lines[] = sprintf(
                '%6d | %-16s | p50 %8.1f ms | p95 %8.1f ms | db p50 %7.1f ms | queries %4d | payload %7.1f KB | pico %5.1f MB | http %d',
                $size, $label, benchPercentile($times, 0.5), benchPercentile($times, 0.95),
                benchPercentile($dbTimes, 0.5), (int) benchPercentile($queries, 0.5), $bytes / 1024, $mem / 1048576, $status,
            );
        }
    }

    $output = implode(PHP_EOL, $lines);
    if ($file = getenv('BENCH_OUTPUT')) {
        file_put_contents($file, $output.PHP_EOL, FILE_APPEND);
    }
    fwrite(STDERR, PHP_EOL.$output.PHP_EOL);
    expect($lines)->not->toBeEmpty();
})->group('benchmark');
