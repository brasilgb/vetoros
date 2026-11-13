<?php

/*
 * VETOROS-PERFORMANCE-OS-03: massa de dados para o teste de carga das OS.
 *
 * Só roda num banco cujo nome contenha "perf" (nunca no banco da aplicação). Exemplo:
 *   DB_DATABASE=vetoros_perf_os03 php artisan migrate --force
 *   DB_DATABASE=vetoros_perf_os03 php scripts/performance/os03-seed.php \
 *       --tenants=50 --sizes=500,2000,10000 --users=3 --manifest=/caminho/manifest.json
 *
 * Cria N tenants com volumes de OS alternados (500, 2.000 e 10.000 por padrão), usuários
 * administradores com senha "bench-password", clientes, equipamentos, peças, histórico,
 * logs, pagamentos e uma OS "pesada" por tenant entre os três primeiros. Todas as OS usam a
 * mesma chave pública (ABCD1234), com bcrypt de custo 12 como em produção. --without-check
 * deixa public_access_key_check vazio, simulando o estado antes do backfill.
 */

use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['tenants::', 'sizes::', 'users::', 'manifest:', 'without-check']);
$tenantCount = (int) ($options['tenants'] ?? 50);
$sizes = array_map('intval', explode(',', (string) ($options['sizes'] ?? '500,2000,10000')));
$usersPerTenant = (int) ($options['users'] ?? 3);
$manifestPath = (string) ($options['manifest'] ?? '');
$withCheck = ! array_key_exists('without-check', $options);

$database = (string) DB::connection()->getDatabaseName();
if (! str_contains($database, 'perf')) {
    fwrite(STDERR, "Recusado: o banco '{$database}' não é um banco de teste de carga (o nome precisa conter 'perf').\n");
    exit(1);
}
if ($manifestPath === '') {
    fwrite(STDERR, "Informe --manifest=arquivo.json\n");
    exit(1);
}

function seedInsert(string $table, array $rows): void
{
    static $columns = [];
    $columns[$table] ??= array_flip(DB::getSchemaBuilder()->getColumnListing($table));
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table($table)->insert(array_map(fn ($row) => array_intersect_key($row, $columns[$table]), $chunk));
    }
}

$started = microtime(true);
$now = now();
$plainKey = 'ABCD1234';
$keyHash = Hash::make($plainKey, ['rounds' => 12]);
$keyCipher = Crypt::encryptString($plainKey);
$keyCheck = $withCheck ? Order::publicAccessKeyCheck($plainKey, $keyHash) : null;
$userPassword = Hash::make('bench-password', ['rounds' => 4]);
$statuses = OrderStatus::values();
$manifest = ['database' => $database, 'public_key' => $plainKey, 'tenants' => []];

for ($t = 1; $t <= $tenantCount; $t++) {
    $size = $sizes[($t - 1) % count($sizes)];
    $tenant = Tenant::factory()->create(['company' => "Bench {$t}", 'subscription_status' => 'active', 'status' => 1, 'expires_at' => $now->copy()->addYear()]);
    Other::factory()->forTenant($tenant->id)->create(['enable_finance' => true]);

    $emails = [];
    for ($u = 1; $u <= $usersPerTenant; $u++) {
        $email = "bench{$t}-{$u}@example.test";
        User::factory()->forTenant($tenant->id)->create(['email' => $email, 'password' => $userPassword, 'roles' => User::ROLE_ADMIN]);
        $emails[] = $email;
    }

    $customerCount = max(50, intdiv($size, 3));
    $customers = [];
    for ($i = 1; $i <= $customerCount; $i++) {
        $phone = '5199'.str_pad((string) $i, 7, '0', STR_PAD_LEFT);
        $customers[] = [
            'tenant_id' => $tenant->id, 'customer_number' => $i, 'name' => 'Cliente '.$i,
            'cpfcnpj' => str_pad((string) ($t * 1000000 + $i), 11, '0', STR_PAD_LEFT), 'phone' => $phone,
            'whatsapp' => $phone, 'email' => null, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    seedInsert('customers', $customers);
    $customerIds = DB::table('customers')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('id')->all();
    $equipmentIds = Equipment::factory()->forTenant($tenant->id)->count(15)->create()->pluck('id')->all();

    $parts = [];
    for ($i = 1; $i <= 300; $i++) {
        $parts[] = [
            'tenant_id' => $tenant->id, 'type' => 'part', 'is_sellable' => 1, 'category' => 'Geral',
            'part_number' => $i, 'reference_number' => 'REF'.$i, 'name' => 'Peça '.$i,
            'cost_price' => 10, 'sale_price' => 20, 'quantity' => 100000, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    seedInsert('parts', $parts);
    $partIds = DB::table('parts')->where('tenant_id', $tenant->id)->pluck('id')->all();

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
            'public_access_key' => $keyCipher, 'public_access_key_hash' => $keyHash, 'public_access_key_check' => $keyCheck,
            'created_at' => $created, 'updated_at' => $created,
        ];
    }
    seedInsert('orders', $orders);
    unset($orders);

    $orderRows = DB::table('orders')->where('tenant_id', $tenant->id)->orderBy('id')
        ->get(['id', 'service_status', 'created_at', 'customer_id', 'equipment_id', 'tracking_token']);
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
    seedInsert('order_status_history', $history);
    seedInsert('order_logs', $logs);
    seedInsert('order_payments', $payments);
    unset($history, $logs, $payments);

    $heavyId = null;
    if ($t <= count($sizes)) {
        $heavyId = (int) $orderRows[intdiv($size, 2)]->id;
        seedInsert('order_parts', array_map(fn ($partId) => ['tenant_id' => $tenant->id, 'order_id' => $heavyId, 'part_id' => $partId, 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now], array_slice($partIds, 0, 30)));
        $heavyHistory = [];
        $heavyLogs = [];
        $heavyMessages = [];
        for ($i = 0; $i < 300; $i++) {
            $heavyHistory[] = ['tenant_id' => $tenant->id, 'order_id' => $heavyId, 'status' => OrderStatus::REPAIR_IN_PROGRESS, 'note' => 'Hist '.$i, 'created_at' => $now, 'updated_at' => $now];
            $heavyLogs[] = ['order_id' => $heavyId, 'action' => 'updated', 'data' => json_encode(['i' => $i]), 'created_at' => $now];
            $heavyMessages[] = ['tenant_id' => $tenant->id, 'order_id' => $heavyId, 'channel' => 'whatsapp', 'status' => 'sent', 'recipient' => '5199000000', 'created_at' => $now, 'updated_at' => $now];
        }
        seedInsert('order_status_history', $heavyHistory);
        seedInsert('order_logs', $heavyLogs);
        seedInsert('order_messages', $heavyMessages);
        for ($v = 1; $v <= 15; $v++) {
            $budgetId = DB::table('order_budgets')->insertGetId(array_intersect_key(
                ['tenant_id' => $tenant->id, 'order_id' => $heavyId, 'version' => $v, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
                array_flip(DB::getSchemaBuilder()->getColumnListing('order_budgets')),
            ));
            $items = [];
            for ($k = 0; $k < 10; $k++) {
                $items[] = ['tenant_id' => $tenant->id, 'order_budget_id' => $budgetId, 'item_type' => 'service', 'description' => 'Item '.$k, 'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'created_at' => $now, 'updated_at' => $now];
            }
            seedInsert('order_budget_items', $items);
        }
    }

    // Amostra para o gerador de carga: OS a abrir/editar, cliente/equipamento e tokens públicos.
    $sample = $orderRows->shuffle()->take(200)->map(fn ($row) => [
        'id' => (int) $row->id, 'customer_id' => (int) $row->customer_id, 'equipment_id' => (int) $row->equipment_id,
        'token' => $row->tracking_token,
    ])->values()->all();

    $manifest['tenants'][] = [
        'tenant_id' => $tenant->id, 'size' => $size, 'users' => $emails, 'heavy_order_id' => $heavyId,
        'customer_id' => $customerIds[0], 'equipment_id' => $equipmentIds[0], 'orders' => $sample,
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
    printf("tenant %2d/%d  id=%d  OS=%d  (%.0fs)\n", $t, $tenantCount, $tenant->id, $size, microtime(true) - $started);
}

printf("Concluído em %.0fs. Manifesto: %s\n", microtime(true) - $started, $manifestPath);
