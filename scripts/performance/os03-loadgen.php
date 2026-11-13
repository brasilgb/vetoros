<?php

/*
 * VETOROS-PERFORMANCE-OS-03: gerador de carga HTTP para as operações de OS.
 *
 * Cada usuário virtual tem sessão própria (login real), pertence a um tenant e repete, em
 * laço fechado, uma mistura de operações (listar, filtrar, pesquisar, abrir, criar, editar,
 * mudar status, link público). Requisições de navegação usam o protocolo Inertia (JSON),
 * como o app faz depois do primeiro carregamento. Escritas não seguem o redirecionamento.
 *
 *   php scripts/performance/os03-loadgen.php --base=http://127.0.0.1:8099 --manifest=m.json \
 *       --tenants=10 --users=30 --duration=60 --think=1000 --out=resultado.json [--reads-only]
 *
 * Percentis por operação: p50/p95/p99 por posição (nearest-rank) sobre todas as amostras
 * daquela operação; nunca média de percentis. Não executar contra produção.
 */

$o = getopt('', ['base:', 'manifest:', 'tenants:', 'users:', 'duration:', 'think::', 'out::', 'reads-only', 'label::']);
$base = rtrim($o['base'] ?? 'http://127.0.0.1:8099', '/');
$manifest = json_decode(file_get_contents($o['manifest']), true, flags: JSON_THROW_ON_ERROR);
$tenantCount = min((int) $o['tenants'], count($manifest['tenants']));
$userCount = (int) $o['users'];
$duration = (float) $o['duration'];
$thinkMs = (int) ($o['think'] ?? 0);
$readsOnly = array_key_exists('reads-only', $o);
$label = $o['label'] ?? "t{$tenantCount}-u{$userCount}";
$tenants = array_slice($manifest['tenants'], 0, $tenantCount);

if (! preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
    fwrite(STDERR, "Recusado: o gerador de carga só roda contra 127.0.0.1/localhost.\n");
    exit(1);
}

function newHandle(): CurlHandle
{
    $h = curl_init();
    curl_setopt($h, CURLOPT_COOKIEFILE, '');
    curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($h, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($h, CURLOPT_TIMEOUT, 60);
    curl_setopt($h, CURLOPT_HEADER, true);

    return $h;
}

function xsrf(CurlHandle $h): string
{
    foreach (curl_getinfo($h, CURLINFO_COOKIELIST) as $line) {
        $parts = explode("\t", $line);
        if (($parts[5] ?? '') === 'XSRF-TOKEN') {
            return urldecode($parts[6]);
        }
    }

    return '';
}

function configure(CurlHandle $h, string $method, string $url, array $headers, ?array $json = null): void
{
    curl_setopt($h, CURLOPT_URL, $url);
    curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($h, CURLOPT_HTTPGET, $method === 'GET');
    if ($json !== null) {
        curl_setopt($h, CURLOPT_POSTFIELDS, json_encode($json));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($h, CURLOPT_HTTPHEADER, $headers);
}

// Login de cada usuário virtual.
$vus = [];
for ($i = 0; $i < $userCount; $i++) {
    $tenant = $tenants[$i % $tenantCount];
    $email = $tenant['users'][intdiv($i, $tenantCount) % count($tenant['users'])];
    $h = newHandle();
    configure($h, 'GET', $base.'/login', ['Accept: text/html']);
    curl_exec($h);
    configure($h, 'POST', $base.'/login', ['Accept: text/html', 'X-XSRF-TOKEN: '.xsrf($h)], ['email' => $email, 'password' => 'bench-password']);
    $raw = curl_exec($h);
    $code = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    if ($code !== 302 || str_contains((string) $raw, '/login')) {
        fwrite(STDERR, "Falha no login de {$email} (HTTP {$code}).\n");
        exit(1);
    }
    configure($h, 'GET', $base.'/app/orders', ['Accept: text/html']);
    $html = (string) curl_exec($h);
    if (! preg_match('/&quot;version&quot;:&quot;([^&]*)&quot;/', $html, $m) && ! preg_match('/"version":"([^"]*)"/', $html, $m)) {
        fwrite(STDERR, "Versão Inertia não encontrada para {$email} (HTTP ".curl_getinfo($h, CURLINFO_RESPONSE_CODE).").\n");
        exit(1);
    }
    $vus[] = ['h' => $h, 'tenant' => $tenant, 'version' => $m[1], 'next' => 0.0, 'busy' => false, 'op' => null, 'start' => 0.0, 'toggle' => $i % 2];
}

$weights = $readsOnly
    ? ['listar' => 30, 'listar_status' => 10, 'listar_busca' => 10, 'abrir_simples' => 25, 'abrir_pesada' => 5, 'form_nova' => 5, 'buscar_cliente' => 5, 'link_publico' => 10]
    : ['listar' => 25, 'listar_status' => 8, 'listar_busca' => 8, 'abrir_simples' => 20, 'abrir_pesada' => 4, 'form_nova' => 5, 'buscar_cliente' => 5,
        'link_publico' => 7, 'salvar_nova' => 5, 'editar_salvar' => 8, 'mudar_status' => 5];
$bag = [];
foreach ($weights as $op => $w) {
    $bag = array_merge($bag, array_fill(0, $w, $op));
}

function updatePayload(array $order, int $status): array
{
    return [
        'order_type' => 'equipment', 'customer_id' => $order['customer_id'], 'equipment_id' => $order['equipment_id'],
        'user_id' => null, 'model' => 'Modelo editado', 'password' => null, 'defect' => 'Não liga',
        'state_conservation' => null, 'accessories' => null, 'budget_description' => 'Troca de componente',
        'budget_value' => '150,00', 'services_performed' => null, 'service_value' => '150,00',
        'manual_parts_value' => '0,00', 'manual_parts_cost' => '0,00', 'discount_amount' => '0,00', 'surcharge_amount' => '0,00',
        'delivery_date' => null, 'service_status' => $status, 'status_reason' => 'Teste de carga controlado',
        'delivery_forecast' => date('Y-m-d', strtotime('+7 days')), 'observations' => 'Teste de carga',
    ];
}

function startRequest(array &$vu, string $op, string $base): void
{
    $t = $vu['tenant'];
    // Índices pares: edição (mantém o status do seed); ímpares: mudança de status. Assim a
    // edição nunca encontra um status alterado por outra operação do teste.
    $idx = array_rand($t['orders']);
    if ($op === 'editar_salvar') {
        $idx -= $idx % 2;
    } elseif ($op === 'mudar_status') {
        $idx = min(count($t['orders']) - 1, $idx | 1);
    }
    $order = $t['orders'][$idx];
    $inertia = ['Accept: text/html, application/xhtml+xml', 'X-Requested-With: XMLHttpRequest', 'X-Inertia: true', 'X-Inertia-Version: '.$vu['version']];
    $write = array_merge($inertia, ['X-XSRF-TOKEN: '.xsrf($vu['h'])]);
    $h = $vu['h'];
    $vu['order'] = $order['id'];

    match ($op) {
        'listar' => configure($h, 'GET', $base.'/app/orders', $inertia),
        'listar_status' => configure($h, 'GET', $base.'/app/orders?status=3', $inertia),
        'listar_busca' => configure($h, 'GET', $base.'/app/orders?search='.rawurlencode('Cliente 1'.random_int(0, 9)), $inertia),
        'abrir_simples' => configure($h, 'GET', $base.'/app/orders/'.$order['id'], $inertia),
        'abrir_pesada' => configure($h, 'GET', $base.'/app/orders/'.($t['heavy_order_id'] ?? $order['id']), $inertia),
        'form_nova' => configure($h, 'GET', $base.'/app/orders/create', $inertia),
        'buscar_cliente' => configure($h, 'GET', $base.'/app/customers/search?q=Cliente%20'.random_int(1, 99), ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']),
        'link_publico' => configure($h, 'GET', $base.'/os/'.$order['token'], ['Accept: text/html']),
        'salvar_nova' => configure($h, 'POST', $base.'/app/orders', $write, [
            'order_type' => 'equipment', 'customer_id' => $order['customer_id'], 'equipment_id' => $order['equipment_id'],
            'model' => 'Notebook', 'defect' => 'Não liga', 'service_status' => 1, 'user_id' => null,
            'delivery_forecast' => date('Y-m-d', strtotime('+7 days')),
        ]),
        'editar_salvar' => configure($h, 'PUT', $base.'/app/orders/'.$order['id'], $write, null),
        'mudar_status' => configure($h, 'PUT', $base.'/app/orders/'.$order['id'], $write, null),
    };

    // Edição mantém o status atual; mudança de status alterna entre "Em reparo" e "Aguardando peça".
    if ($op === 'editar_salvar' || $op === 'mudar_status') {
        $status = $op === 'mudar_status' ? (($vu['toggle'] ^= 1) ? 6 : 14) : null;
        $payload = updatePayload($order, $status ?? (int) $order['status']);
        configure($h, 'PUT', $base.'/app/orders/'.$order['id'], $write, $payload);
    }

    $vu['op'] = $op;
    $vu['start'] = microtime(true);
    $vu['busy'] = true;
}

$samples = [];
$errors = [];
$multi = curl_multi_init();
$startedAt = microtime(true);
$endAt = $startedAt + $duration;
$byHandle = [];
foreach ($vus as $k => $vu) {
    $vus[$k]['next'] = $startedAt + ($thinkMs > 0 ? mt_rand(0, $thinkMs) / 1000 : 0);
    $byHandle[spl_object_id($vu['h'])] = $k;
}

while (true) {
    $now = microtime(true);
    $active = 0;
    foreach ($vus as $k => &$vu) {
        if ($vu['busy']) {
            $active++;
        } elseif ($now < $endAt && $now >= $vu['next']) {
            startRequest($vu, $bag[array_rand($bag)], $base);
            curl_multi_add_handle($multi, $vu['h']);
            $active++;
        }
    }
    unset($vu);
    if ($active === 0 && $now >= $endAt) {
        break;
    }

    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.005);
    while ($info = curl_multi_info_read($multi)) {
        $h = $info['handle'];
        $k = $byHandle[spl_object_id($h)];
        $elapsed = (microtime(true) - $vus[$k]['start']) * 1000;
        $code = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $raw = (string) curl_multi_getcontent($h);
        $op = $vus[$k]['op'];
        curl_multi_remove_handle($multi, $h);

        $ok = $info['result'] === CURLE_OK && $code >= 200 && $code < 400;
        if ($ok && in_array($op, ['salvar_nova', 'editar_salvar', 'mudar_status'], true)) {
            // Sucesso redireciona para a OS/listagem; erro de validação volta para a página anterior.
            $ok = (bool) preg_match('#^Location: .*/app/orders#mi', $raw);
        }
        if ($ok) {
            $samples[$op][] = $elapsed;
        } else {
            $errors[$op][] = $code ?: 'curl:'.$info['result'];
        }
        $vus[$k]['busy'] = false;
        $vus[$k]['next'] = microtime(true) + ($thinkMs > 0 ? $thinkMs * (0.5 + mt_rand() / mt_getrandmax()) / 1000 : 0);
    }
}
$wall = microtime(true) - $startedAt;

function pct(array $v, float $p): float
{
    sort($v);

    return $v[max(0, (int) ceil(count($v) * $p) - 1)];
}

$result = ['label' => $label, 'tenants' => $tenantCount, 'users' => $userCount, 'think_ms' => $thinkMs, 'duration_s' => round($wall, 1), 'ops' => []];
$all = [];
foreach (array_keys($weights) as $op) {
    $v = $samples[$op] ?? [];
    $all = array_merge($all, $v);
    $result['ops'][$op] = [
        'n' => count($v), 'errors' => count($errors[$op] ?? []), 'error_codes' => array_count_values(array_map('strval', $errors[$op] ?? [])),
        'p50' => $v ? round(pct($v, 0.5), 1) : null, 'p95' => $v ? round(pct($v, 0.95), 1) : null,
        'p99' => $v ? round(pct($v, 0.99), 1) : null, 'max' => $v ? round(max($v), 1) : null,
    ];
}
$result['total'] = ['n' => count($all), 'errors' => array_sum(array_map('count', $errors)), 'rps' => round(count($all) / $wall, 1),
    'p50' => $all ? round(pct($all, 0.5), 1) : null, 'p95' => $all ? round(pct($all, 0.95), 1) : null];

printf("== %s | tenants=%d usuários=%d pausa=%dms | %.0fs | %d req | %.1f req/s | erros %d\n", $label, $tenantCount, $userCount, $thinkMs, $wall, count($all), $result['total']['rps'], $result['total']['errors']);
foreach ($result['ops'] as $op => $r) {
    printf("   %-15s n=%5d err=%3d  p50 %7s  p95 %7s  p99 %7s  max %7s %s\n", $op, $r['n'], $r['errors'], $r['p50'] ?? '-', $r['p95'] ?? '-', $r['p99'] ?? '-', $r['max'] ?? '-',
        $r['error_codes'] ? json_encode($r['error_codes']) : '');
}
if (! empty($o['out'])) {
    file_put_contents($o['out'], json_encode($result).PHP_EOL, FILE_APPEND);
}
