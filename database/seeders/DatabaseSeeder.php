<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    private array $counts = [];

    public function run(): void
    {
        $this->counts = [
            'customers' => (int) env('SEED_TEST_CUSTOMERS', 60),
            'equipment' => (int) env('SEED_TEST_EQUIPMENT', 12),
            'budgets' => (int) env('SEED_TEST_BUDGETS', 32),
            'parts' => (int) env('SEED_TEST_PARTS', 80),
            'orders' => (int) env('SEED_TEST_ORDERS', 100),
            'schedules' => (int) env('SEED_TEST_SCHEDULES', 45),
            'messages' => (int) env('SEED_TEST_MESSAGES', 60),
            'sales' => (int) env('SEED_TEST_SALES', 50),
            'expenses' => (int) env('SEED_TEST_EXPENSES', 24),
            'customer_equipments' => (int) env('SEED_TEST_CUSTOMER_EQUIPMENTS', 40),
        ];

        DB::transaction(function (): void {
            $this->cleanupTestTenant();
            $this->seedTenant();
        });
    }

    private function seedTenant(): void
    {
        $now = now();
        $planId = DB::table('plans')->where('id', 1)->exists()
            ? 1
            : DB::table('plans')->value('id');
        $periodId = $planId && Schema::hasColumn('tenants', 'period_id')
            ? DB::table('periods')->where('plan_id', $planId)->value('id')
            : null;

        $tenant = [
            'plan_id' => $planId,
            'name' => 'Tenant Teste Funcional',
            'company' => 'Assistencia Teste Funcional',
            'cnpj' => '11222333000181',
            'email' => 'tenant-teste@example.test',
            'phone' => '(11) 4000-1000',
            'whatsapp' => '(11) 90000-1000',
            'zip_code' => '01001000',
            'state' => 'SP',
            'city' => 'Sao Paulo',
            'district' => 'Centro',
            'street' => 'Rua de Testes',
            'complement' => 'Sala 1',
            'number' => '101',
            'status' => 1,
            'subscription_status' => 'active',
            'expires_at' => $now->copy()->addDays(90),
            'observations' => 'Tenant unico criado para testes funcionais completos.',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($periodId) {
            $tenant['period_id'] = $periodId;
        }

        $tenantId = DB::table('tenants')->insertGetId($tenant);
        $users = $this->seedUsers($tenantId);

        $this->seedSettings($tenantId, $now);
        $this->seedEquipmentAndChecklists($tenantId, $now);
        $this->seedCustomers($tenantId, $now);
        $this->seedCustomerEquipments($tenantId, $now);
        $this->seedBudgets($tenantId, $now);
        $this->seedParts($tenantId, $now);

        $customerIds = DB::table('customers')->where('tenant_id', $tenantId)->pluck('id')->all();
        $equipmentIds = DB::table('equipment')->where('tenant_id', $tenantId)->pluck('id')->all();
        $partIds = DB::table('parts')->where('tenant_id', $tenantId)->pluck('id')->all();
        $cashSessionIds = $this->seedCashSessions($tenantId, $users, $now);

        $orderIds = $this->seedOrders($tenantId, $users, $customerIds, $equipmentIds, $partIds, $cashSessionIds, $now);
        $this->seedSchedules($tenantId, $users, $customerIds, $now);
        $this->seedMessages($tenantId, $users, $now);
        $saleIds = $this->seedSales($tenantId, $users, $customerIds, $partIds, $cashSessionIds, $now);
        $this->seedFiscalDocuments($tenantId, $users, $orderIds, $saleIds, $now);
        $this->seedExpenses($tenantId, $users, $now);
        $this->seedPayments($tenantId, $planId, $now);
        $this->refreshCashSessions($cashSessionIds);
        $this->seedOperationalAudits($tenantId, $users, $orderIds, $now);
    }

    private function seedUsers(int $tenantId): array
    {
        $now = now();
        $password = Hash::make('password');
        $rows = [
            [
                'name' => 'Root App Teste',
                'email' => 'root@app.test',
                'roles' => User::ROLE_ROOT_APP,
            ],
            [
                'name' => 'Administrador Teste',
                'email' => 'admin@app.test',
                'roles' => User::ROLE_ADMIN,
            ],
            [
                'name' => 'Atendente Teste',
                'email' => 'atendente@app.test',
                'roles' => User::ROLE_OPERATOR,
            ],
            [
                'name' => 'Tecnico Teste',
                'email' => 'tecnico@app.test',
                'roles' => User::ROLE_TECHNICIAN,
            ],
        ];

        $ids = [];
        foreach ($rows as $number => $row) {
            $ids[$row['roles']] = DB::table('users')->insertGetId([
                'tenant_id' => $tenantId,
                'user_number' => $number + 1,
                'name' => $row['name'],
                'email' => $row['email'],
                'telephone' => '(11) 3000-'.$this->pad($tenantId + $number, 4),
                'whatsapp' => '(11) 93000-'.$this->pad($tenantId + $number, 4),
                'password' => $password,
                'roles' => $row['roles'],
                'status' => 1,
                'email_verified_at' => $now,
                'remember_token' => Str::random(10),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'root' => $ids[User::ROLE_ROOT_APP],
            'admin' => $ids[User::ROLE_ADMIN],
            'operator' => $ids[User::ROLE_OPERATOR],
            'technician' => $ids[User::ROLE_TECHNICIAN],
            'all' => array_values($ids),
        ];
    }

    private function seedSettings(int $tenantId, Carbon $now): void
    {
        DB::table('companies')->insert([
            'tenant_id' => $tenantId,
            'shortname' => 'Teste OS',
            'companyname' => 'Assistencia Teste Funcional',
            'cnpj' => '11222333000181',
            'zip_code' => '01001000',
            'state' => 'SP',
            'city' => 'Sao Paulo',
            'district' => 'Centro',
            'street' => 'Rua de Testes',
            'number' => '101',
            'telephone' => '(11) 4000-1000',
            'whatsapp' => '(11) 90000-1000',
            'site' => 'https://teste.example.test',
            'email' => 'tenant-teste@example.test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('settings')->insert([
            'tenant_id' => $tenantId,
            'name' => 'Teste OS',
            'logo' => 'default.png',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('branches')->insert([
            'tenant_id' => $tenantId,
            'branch_cnpj' => '11222333000262',
            'branch_name' => 'Filial Teste',
            'branch_number' => '1',
            'contact_name' => 'Contato Teste',
            'contact_email' => 'filial@app.test',
            'contact_phone' => '(11) 4000-1010',
            'contact_whatsapp' => '(11) 94000-1010',
            'cep' => '01001000',
            'state' => 'SP',
            'city' => 'Sao Paulo',
            'district' => 'Centro',
            'street' => 'Rua da Filial',
            'number' => '200',
            'status' => true,
            'observations' => 'Filial para testes funcionais.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('others')->insert($this->withExistingColumns('others', [
            'tenant_id' => $tenantId,
            'navigation' => true,
            'enableparts' => true,
            'enable_finance' => true,
            'enablesales' => true,
            'show_follow_ups_menu' => true,
            'show_tasks_menu' => true,
            'show_commercial_performance_menu' => true,
            'show_quality_menu' => true,
            'print_label_button_after_order_create' => true,
            'warranty_return_alert_threshold' => 8,
            'communication_follow_up_cooldown_days' => 2,
            'automatic_follow_ups_enabled' => true,
            'enable_technician_schedule_notifications' => true,
            'customer_feedback_request_delay_days' => 5,
            'budget_conversion_target' => 65,
            'payment_recovery_target' => 75,
            'mail_mailer' => 'smtp',
            'mail_host' => 'mailpit',
            'mail_port' => 1025,
            'mail_from_address' => 'teste@example.test',
            'mail_from_name' => 'Teste OS',
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        DB::table('receipts')->insert([
            'tenant_id' => $tenantId,
            'receivingequipment' => 'Eu {{ cliente }}, inscrito(a) sob CPF/CNPJ numero {{ cpf_cnpj }}, me responsabilizo por eventuais perdas e/ou danos de arquivos, fotos, agenda, ou quaisquer outros dados armazenados no HD, SSD, cartao de memoria ou memoria interna do meu equipamento, inclusive peliculas ou adesivos, capas e demais acessorios, ficando a Mega System Informatica isenta de qualquer responsabilidade. E obrigatoria a apresentacao desta Ordem de Servico (O.S.) no ato da retirada para que o equipamento seja liberado pela empresa. Caso o aparelho nao seja retirado em ate 90 dias, a contar da presente data, tal fato sera considerado como abandono, estando a empresa apta a descarta-lo. O(A) cliente declara e reconhece que todas as informacoes aqui fornecidas sao verdadeiras e que entendeu e aceitou todos os termos desta Ordem de Servico.',
            'equipmentdelivery' => 'Eu {{ cliente }}, inscrito(a) sob CPF/CNPJ numero {{ cpf_cnpj }}, declaro estar ciente de que, de acordo com o Codigo de Defesa do Consumidor (Lei n. 8.078/90, secao IV, Art. 26), tenho o direito de solicitar a garantia pelo servico executado em 30 (trinta) dias, tratando-se de servicos e de produtos nao duraveis (relacionado a sistema); e em 90 (noventa) dias, tratando-se de fornecimento de servico e de produtos duraveis (relacionado a pecas).',
            'budgetissuance' => 'Equipamento analisado preliminarmente. Segue orcamento inicial para reparo conforme diagnostico tecnico apresentado nesta O.S. O servico sera executado somente mediante aprovacao do cliente. Valores e prazo podem sofrer alteracoes caso sejam identificadas necessidades adicionais durante o reparo.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('whatsapp_messages')->insert($this->withExistingColumns('whatsapp_messages', [
            'tenant_id' => $tenantId,
            'generatedbudget' => "{{ saudacao }}, {{ cliente }}!\nEquipamento analisado preliminarmente. Segue orçamento inicial para reparo conforme diagnóstico técnico apresentado na OS {{ ordem }}.\nO serviço será executado somente mediante sua aprovação. Valores e prazo podem sofrer alterações caso sejam identificadas necessidades adicionais durante o reparo.\nVocê pode acompanhar pelo link: {{ link_os }}",
            'servicecompleted' => 'Ola {{ cliente }}, sua OS {{ ordem }} foi concluida.',
            'feedback' => 'Ola {{ cliente }}, avalie seu atendimento da OS {{ ordem }}.',
            'defaultmessage' => 'Ola {{ cliente }}, acompanhe sua OS {{ ordem }} pelo link {{ link_os }}.',
            'budgetfollowup' => 'Ola {{ cliente }}, seu orçamento segue aguardando retorno.',
            'pendingpayment' => 'Ola {{ cliente }}, sua OS possui saldo pendente de {{ saldo }}.',
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        if (Schema::hasTable('fiscal_settings')) {
            DB::table('fiscal_settings')->insert($this->withExistingColumns('fiscal_settings', [
                'tenant_id' => $tenantId,
                'enabled' => true,
                'nfe_enabled' => true,
                'nfse_enabled' => true,
                'environment' => 'sandbox',
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    private function seedEquipmentAndChecklists(int $tenantId, Carbon $now): void
    {
        $equipmentRows = [];
        for ($i = 1; $i <= $this->counts['equipment']; $i++) {
            $equipmentRows[] = [
                'tenant_id' => $tenantId,
                'equipment_number' => $i,
                'equipment' => 'Equipamento Teste '.$i,
                'chart' => $i % 2 === 0,
                'created_at' => $now->copy()->subDays($i % 90),
                'updated_at' => $now,
            ];
        }
        DB::table('equipment')->insert($equipmentRows);

        $equipmentIds = DB::table('equipment')->where('tenant_id', $tenantId)->pluck('id')->all();
        $rows = [];
        $counter = 1;
        foreach ($equipmentIds as $equipmentId) {
            foreach (['Entrada', 'Diagnostico', 'Entrega'] as $label) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'equipment_id' => $equipmentId,
                    'checklist_number' => $counter++,
                    'checklist' => $label.' - item '.$equipmentId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('checklists')->insert($rows);
    }

    private function seedCustomers(int $tenantId, Carbon $now): void
    {
        $rows = [];
        for ($i = 1; $i <= $this->counts['customers']; $i++) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'customer_number' => $i,
                'name' => 'Cliente Teste '.$this->pad($i, 5),
                'cpfcnpj' => $this->digits(11, ($tenantId * 100000) + $i),
                'birth' => $now->copy()->subYears(25 + ($i % 30))->toDateString(),
                'email' => "cliente{$tenantId}-{$i}@example.test",
                'zipcode' => '01001000',
                'state' => 'SP',
                'city' => 'Sao Paulo',
                'district' => 'Centro',
                'street' => 'Rua Cliente '.$i,
                'number' => 100 + $i,
                'phone' => '(11) 3100-'.$this->pad($i, 4),
                'contactname' => 'Contato '.$i,
                'whatsapp' => '(11) 91000-'.$this->pad($i, 4),
                'contactphone' => '(11) 3200-'.$this->pad($i, 4),
                'observations' => 'Cliente gerado para testes funcionais.',
                'created_at' => $now->copy()->subDays($i % 90),
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('customers', $rows);
    }

    private function seedCustomerEquipments(int $tenantId, Carbon $now): void
    {
        $customerIds = DB::table('customers')->where('tenant_id', $tenantId)->pluck('id')->all();
        $equipmentIds = DB::table('equipment')->where('tenant_id', $tenantId)->pluck('id')->all();

        if (empty($customerIds) || empty($equipmentIds)) {
            return;
        }

        $rows = [];
        $number = 1;
        for ($i = 1; $i <= $this->counts['customer_equipments']; $i++) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'customer_id' => $customerIds[$i % count($customerIds)],
                'equipment_id' => $equipmentIds[$i % count($equipmentIds)],
                'customer_equipment_number' => $number++,
                'brand' => 'Marca Teste '.($i % 5 + 1),
                'model' => 'Modelo Teste '.$i,
                'serial_number' => $i % 3 === 0 ? null : 'SN'.$this->pad($i, 8),
                'imei' => $i % 2 === 0 ? $this->digits(15, ($tenantId * 100000) + $i) : null,
                'color' => null,
                'accessories' => null,
                'notes' => null,
                'status' => 'active',
                'created_at' => $now->copy()->subDays($i % 90),
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('customer_equipments', $rows);
    }

    private function seedBudgets(int $tenantId, Carbon $now): void
    {
        $equipmentIds = DB::table('equipment')->where('tenant_id', $tenantId)->pluck('id')->all();
        $rows = [];
        for ($i = 1; $i <= $this->counts['budgets']; $i++) {
            $partValue = 30 + ($i % 15) * 18;
            $laborValue = 80 + ($i % 20) * 22;
            $rows[] = [
                'tenant_id' => $tenantId,
                'budget_number' => $i,
                'equipment_id' => $equipmentIds[$i % count($equipmentIds)],
                'model' => 'Modelo '.$i,
                'service' => 'Servico preventivo '.$i,
                'description' => 'Orcamento funcional com mao de obra e pecas.',
                'estimated_time' => (1 + ($i % 7)).' dias',
                'part_value' => $partValue,
                'labor_value' => $laborValue,
                'total_value' => $partValue + $laborValue,
                'warranty' => '90 dias',
                'validity' => 10,
                'obs' => 'Gerado por seeder de testes funcionais.',
                'created_at' => $now->copy()->subDays($i % 90),
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('budgets', $rows);
    }

    private function seedParts(int $tenantId, Carbon $now): void
    {
        $rows = [];
        $categories = ['Tela', 'Bateria', 'Conector', 'Placa', 'Fonte', 'Cabo', 'Acessorio'];
        for ($i = 1; $i <= $this->counts['parts']; $i++) {
            $cost = 12 + ($i % 50) * 3.7;
            $rows[] = [
                'tenant_id' => $tenantId,
                'type' => $i % 3 === 0 ? 'product' : 'part',
                'is_sellable' => true,
                'category' => $categories[$i % count($categories)].' '.$tenantId.'-'.$i,
                'part_number' => 'P'.$tenantId.'-'.$this->pad($i, 6),
                'reference_number' => 'REF'.$tenantId.'-'.$this->pad($i, 6),
                'name' => 'Peca Produto Teste '.$this->pad($i, 5),
                'description' => 'Item para testes de estoque e vendas.',
                'manufacturer' => 'Fabricante '.$i % 12,
                'model_compatibility' => 'Modelos A/B/C',
                'cost_price' => $cost,
                'sale_price' => round($cost * 1.85, 2),
                'quantity' => 25 + ($i % 80),
                'minimum_stock_level' => 5 + ($i % 10),
                'location' => 'A'.$i % 20,
                'status' => true,
                'created_at' => $now->copy()->subDays($i % 90),
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('parts', $rows);
    }

    private function seedCashSessions(int $tenantId, array $users, Carbon $now): array
    {
        $ids = [];
        for ($i = 1; $i <= 8; $i++) {
            $openedAt = $now->copy()->subDays(9 - $i)->setTime(8, 0);
            $closed = $i < 8;
            $id = DB::table('cash_sessions')->insertGetId($this->withExistingColumns('cash_sessions', [
                'tenant_id' => $tenantId,
                'opened_by' => $users['operator'],
                'closed_by' => $closed ? $users['root'] : null,
                'opened_at' => $openedAt,
                'closed_at' => $closed ? $openedAt->copy()->setTime(18, 0) : null,
                'opening_balance' => 150 + ($i * 20),
                'closing_balance' => null,
                'expected_balance' => null,
                'difference' => null,
                'total_completed_sales' => 0,
                'total_order_payments' => 0,
                'total_cancelled_sales' => 0,
                'manual_entries' => 20,
                'manual_exits' => 10,
                'status' => $closed ? 'closed' : 'open',
                'notes' => 'Caixa teste '.$i,
                'closing_notes' => $closed ? 'Fechamento teste.' : null,
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ]));
            $ids[] = $id;

            DB::table('cash_session_logs')->insert([
                'tenant_id' => $tenantId,
                'cash_session_id' => $id,
                'user_id' => $users['operator'],
                'action' => 'opened',
                'data' => json_encode(['seed' => 'functional_test']),
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ]);
        }

        return $ids;
    }

    private function seedOrders(
        int $tenantId,
        array $users,
        array $customerIds,
        array $equipmentIds,
        array $partIds,
        array $cashSessionIds,
        Carbon $now
    ): array {
        $orderIds = [];
        $statuses = [
            OrderStatus::OPEN,
            OrderStatus::CANCELLED,
            OrderStatus::BUDGET_GENERATED,
            OrderStatus::BUDGET_APPROVED,
            OrderStatus::BUDGET_REJECTED,
            OrderStatus::REPAIR_IN_PROGRESS,
            OrderStatus::SERVICE_COMPLETED,
            OrderStatus::SERVICE_NOT_EXECUTED,
            OrderStatus::CUSTOMER_NOTIFIED,
            OrderStatus::DELIVERED,
        ];

        for ($i = 1; $i <= $this->counts['orders']; $i++) {
            $status = $statuses[$i % count($statuses)];
            $createdAt = $now->copy()->subDays($i % 90)->subMinutes($i);
            $serviceValue = 90 + ($i % 40) * 11;
            $partsValue = 30 + ($i % 25) * 9;
            $total = $serviceValue + $partsValue;
            $delivered = $status === OrderStatus::DELIVERED;
            $hasServiceStarted = in_array($status, [
                OrderStatus::REPAIR_IN_PROGRESS,
                OrderStatus::SERVICE_COMPLETED,
                OrderStatus::SERVICE_NOT_EXECUTED,
                OrderStatus::CUSTOMER_NOTIFIED,
                OrderStatus::DELIVERED,
            ], true);
            $orderId = DB::table('orders')->insertGetId($this->withExistingColumns('orders', [
                'tenant_id' => $tenantId,
                'customer_id' => $customerIds[$i % count($customerIds)],
                'equipment_id' => $equipmentIds[$i % count($equipmentIds)],
                'user_id' => $users['technician'],
                'order_number' => $i,
                'tracking_token' => Str::uuid()->toString(),
                'model' => 'Modelo OS '.$i,
                'password' => $i % 5 === 0 ? '1234' : null,
                'defect' => 'Defeito teste '.$i,
                'state_conservation' => 'Usado, marcas leves.',
                'accessories' => 'Carregador',
                'budget_description' => 'Diagnostico, pecas e mao de obra.',
                'budget_value' => $total,
                'service_status' => $status,
                'observations' => 'Ordem gerada para testes funcionais.',
                'services_performed' => $hasServiceStarted ? 'Servico executado no fluxo de teste.' : null,
                'parts_value' => $partsValue,
                'service_value' => $serviceValue,
                'service_cost' => $total,
                'delivery_forecast' => $createdAt->copy()->addDays(5)->toDateString(),
                'delivery_date' => $delivered ? $createdAt->copy()->addDays(6) : null,
                'feedback' => $delivered,
                'warranty_days' => $delivered ? 30 : null,
                'warranty_expires_at' => $delivered ? $createdAt->copy()->addDays(36) : null,
                'customer_feedback_rating' => $delivered ? (($i % 5) + 1) : null,
                'customer_feedback_comment' => $delivered ? 'Feedback teste '.$i : null,
                'customer_feedback_submitted_at' => $delivered ? $createdAt->copy()->addDays(7) : null,
                'budget_follow_up_assigned_to' => $users['operator'],
                'payment_follow_up_assigned_to' => $users['operator'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addDays(1),
            ]));
            $orderIds[] = $orderId;

            $this->seedOrderStatusHistory($orderId, $status, $users, $createdAt);
            $this->seedOrderParts($tenantId, $orderId, $partIds, $users, $createdAt, $i);

            if (in_array($status, [OrderStatus::SERVICE_COMPLETED, OrderStatus::CUSTOMER_NOTIFIED, OrderStatus::DELIVERED], true)) {
                $paidAt = $createdAt->copy()->addDays(4);
                DB::table('order_payments')->insert($this->withExistingColumns('order_payments', [
                    'order_id' => $orderId,
                    'cash_session_id' => $cashSessionIds[$i % count($cashSessionIds)],
                    'amount' => round($total * ($i % 3 === 0 ? 0.5 : 1), 2),
                    'payment_method' => ['pix', 'cartao', 'dinheiro'][$i % 3],
                    'paid_at' => $paidAt,
                    'notes' => 'Pagamento teste OS '.$i,
                    'created_at' => $paidAt,
                    'updated_at' => $paidAt,
                ]));
            }
        }

        return $orderIds;
    }

    private function seedOrderStatusHistory(int $orderId, int $finalStatus, array $users, Carbon $createdAt): void
    {
        $path = match ($finalStatus) {
            OrderStatus::CANCELLED => [OrderStatus::OPEN, OrderStatus::CANCELLED],
            OrderStatus::BUDGET_GENERATED => [OrderStatus::OPEN, OrderStatus::BUDGET_GENERATED],
            OrderStatus::BUDGET_APPROVED => [OrderStatus::OPEN, OrderStatus::BUDGET_GENERATED, OrderStatus::BUDGET_APPROVED],
            OrderStatus::BUDGET_REJECTED => [OrderStatus::OPEN, OrderStatus::BUDGET_GENERATED, OrderStatus::BUDGET_REJECTED],
            OrderStatus::REPAIR_IN_PROGRESS => [OrderStatus::OPEN, OrderStatus::BUDGET_GENERATED, OrderStatus::BUDGET_APPROVED, OrderStatus::REPAIR_IN_PROGRESS],
            OrderStatus::SERVICE_COMPLETED => [OrderStatus::OPEN, OrderStatus::BUDGET_GENERATED, OrderStatus::BUDGET_APPROVED, OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::SERVICE_COMPLETED],
            OrderStatus::SERVICE_NOT_EXECUTED => [OrderStatus::OPEN, OrderStatus::BUDGET_GENERATED, OrderStatus::BUDGET_APPROVED, OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::SERVICE_NOT_EXECUTED],
            OrderStatus::CUSTOMER_NOTIFIED => [OrderStatus::OPEN, OrderStatus::BUDGET_GENERATED, OrderStatus::BUDGET_APPROVED, OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::SERVICE_COMPLETED, OrderStatus::CUSTOMER_NOTIFIED],
            OrderStatus::DELIVERED => [OrderStatus::OPEN, OrderStatus::BUDGET_GENERATED, OrderStatus::BUDGET_APPROVED, OrderStatus::REPAIR_IN_PROGRESS, OrderStatus::SERVICE_COMPLETED, OrderStatus::CUSTOMER_NOTIFIED, OrderStatus::DELIVERED],
            default => [OrderStatus::OPEN],
        };

        foreach ($path as $index => $status) {
            $at = $createdAt->copy()->addHours($index * 6);
            DB::table('order_status_history')->insert([
                'order_id' => $orderId,
                'status' => $status,
                'note' => OrderStatus::label($status),
                'changed_by' => $users['all'][$index % count($users['all'])],
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        DB::table('order_logs')->insert([
            'order_id' => $orderId,
            'user_id' => $users['operator'],
            'action' => 'functional_test_seed_created',
            'data' => json_encode(['final_status' => $finalStatus]),
            'created_at' => $createdAt,
        ]);
    }

    private function seedOrderParts(int $tenantId, int $orderId, array $partIds, array $users, Carbon $createdAt, int $seed): void
    {
        for ($i = 0; $i < 3; $i++) {
            $partId = $partIds[($seed + $i) % count($partIds)];
            $quantity = 1 + (($seed + $i) % 3);
            DB::table('order_parts')->insert([
                'order_id' => $orderId,
                'part_id' => $partId,
                'quantity' => $quantity,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            DB::table('part_movements')->insert([
                'tenant_id' => $tenantId,
                'part_id' => $partId,
                'order_id' => $orderId,
                'user_id' => $users['technician'],
                'movement_type' => 'uso_os',
                'quantity' => $quantity,
                'reason' => 'Uso na OS teste '.$orderId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    private function seedSchedules(int $tenantId, array $users, array $customerIds, Carbon $now): void
    {
        $rows = [];
        for ($i = 1; $i <= $this->counts['schedules']; $i++) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'customer_id' => $customerIds[$i % count($customerIds)],
                'user_id' => $users['technician'],
                'schedules_number' => $i,
                'schedules' => $now->copy()->addDays(($i % 45) - 15)->setTime(8 + ($i % 9), 0),
                'status' => ($i % 4) + 1,
                'observations' => 'Observacao de teste.',
                'responsible_technician' => 'Tecnico Teste',
                'created_at' => $now->copy()->subDays($i % 30),
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('schedules', $rows);
    }

    private function seedMessages(int $tenantId, array $users, Carbon $now): void
    {
        $rows = [];
        for ($i = 1; $i <= $this->counts['messages']; $i++) {
            $sender = $users['all'][$i % count($users['all'])];
            $recipient = $users['all'][($i + 1) % count($users['all'])];
            $rows[] = [
                'tenant_id' => $tenantId,
                'sender_id' => $sender,
                'recipient_id' => $recipient,
                'message_number' => (string) $i,
                'title' => 'Mensagem teste '.$i,
                'message' => 'Conteudo de mensagem para teste funcional '.$i,
                'status' => $i % 3 !== 0,
                'created_at' => $now->copy()->subMinutes($i * 3),
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('messages', $rows);
    }

    private function seedSales(int $tenantId, array $users, array $customerIds, array $partIds, array $cashSessionIds, Carbon $now): array
    {
        $saleIds = [];

        for ($i = 1; $i <= $this->counts['sales']; $i++) {
            $createdAt = $now->copy()->subDays($i % 90)->subMinutes($i);
            $status = $i % 12 === 0 ? 'cancelled' : 'completed';
            $saleId = DB::table('sales')->insertGetId($this->withExistingColumns('sales', [
                'sales_number' => $i,
                'tenant_id' => $tenantId,
                'customer_id' => $customerIds[$i % count($customerIds)],
                'cash_session_id' => $cashSessionIds[$i % count($cashSessionIds)],
                'total_amount' => 0,
                'paid_amount' => 0,
                'financial_status' => $status === 'cancelled' ? 'cancelled' : 'pending',
                'payment_method' => ['pix', 'cartao', 'dinheiro', 'transferencia'][$i % 4],
                'status' => $status,
                'cancelled_at' => $status === 'cancelled' ? $createdAt->copy()->addHour() : null,
                'cancelled_by' => $status === 'cancelled' ? $users['operator'] : null,
                'cancel_reason' => $status === 'cancelled' ? 'Cancelamento de teste.' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]));
            $saleIds[] = $saleId;

            $total = 0;
            for ($item = 0; $item < 4; $item++) {
                $quantity = 1 + (($i + $item) % 3);
                $unit = 25 + (($i + $item) % 80) * 2.5;
                $total += $quantity * $unit;
                DB::table('sale_items')->insert([
                    'sale_id' => $saleId,
                    'part_id' => $partIds[($i + $item) % count($partIds)],
                    'quantity' => $quantity,
                    'unit_price' => $unit,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            $paid = $status === 'cancelled' ? 0 : ($i % 5 === 0 ? round($total / 2, 2) : $total);
            DB::table('sales')->where('id', $saleId)->update([
                'total_amount' => $total,
                'paid_amount' => $paid,
                'financial_status' => $status === 'cancelled' ? 'cancelled' : ($paid < $total ? 'partial' : 'paid'),
            ]);

            DB::table('sale_logs')->insert([
                'sale_id' => $saleId,
                'user_id' => $users['operator'],
                'action' => $status === 'cancelled' ? 'cancelled' : 'completed',
                'data' => json_encode(['seed' => 'functional_test']),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $saleIds;
    }

    private function seedFiscalDocuments(int $tenantId, array $users, array $orderIds, array $saleIds, Carbon $now): void
    {
        if (! Schema::hasTable('fiscal_documents')) {
            return;
        }

        $rows = [];
        foreach (array_slice($orderIds, 0, 12) as $index => $orderId) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'documentable_type' => 'App\\Models\\App\\Order',
                'documentable_id' => $orderId,
                'type' => $index % 2 === 0 ? 'nfe' : 'nfse',
                'provider' => 'manual',
                'environment' => 'sandbox',
                'provider_reference' => 'OS-'.$orderId,
                'number' => 'NF-OS-'.$this->pad($index + 1, 4),
                'series' => '1',
                'access_key' => $this->digits(44, 1000 + $index),
                'status' => 'registered',
                'pdf_url' => 'https://teste.example.test/notas/os-'.$orderId.'.pdf',
                'xml_url' => 'https://teste.example.test/notas/os-'.$orderId.'.xml',
                'issued_at' => $now->copy()->subDays($index + 1),
                'registered_by' => $users['admin'],
                'notes' => 'Documento fiscal de teste vinculado a OS.',
                'request_payload' => json_encode(['seed' => 'functional_test']),
                'response_payload' => json_encode(['status' => 'registered']),
                'created_at' => $now->copy()->subDays($index + 1),
                'updated_at' => $now->copy()->subDays($index + 1),
            ];
        }

        foreach (array_slice($saleIds, 0, 12) as $index => $saleId) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'documentable_type' => 'App\\Models\\App\\Sale',
                'documentable_id' => $saleId,
                'type' => 'nfe',
                'provider' => 'manual',
                'environment' => 'sandbox',
                'provider_reference' => 'VENDA-'.$saleId,
                'number' => 'NF-V-'.$this->pad($index + 1, 4),
                'series' => '1',
                'access_key' => $this->digits(44, 2000 + $index),
                'status' => $index % 5 === 0 ? 'cancelled' : 'registered',
                'pdf_url' => 'https://teste.example.test/notas/venda-'.$saleId.'.pdf',
                'xml_url' => 'https://teste.example.test/notas/venda-'.$saleId.'.xml',
                'issued_at' => $now->copy()->subDays($index + 2),
                'registered_by' => $users['admin'],
                'notes' => 'Documento fiscal de teste vinculado a venda.',
                'request_payload' => json_encode(['seed' => 'functional_test']),
                'response_payload' => json_encode(['status' => 'registered']),
                'created_at' => $now->copy()->subDays($index + 2),
                'updated_at' => $now->copy()->subDays($index + 2),
            ];
        }

        $this->insertChunks('fiscal_documents', $rows);
    }

    private function seedExpenses(int $tenantId, array $users, Carbon $now): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        $categories = ['Pecas', 'Ferramentas', 'Frete', 'Energia', 'Internet', 'Marketing'];
        $expenseIds = [];
        for ($i = 1; $i <= $this->counts['expenses']; $i++) {
            $createdAt = $now->copy()->subDays($i % 90);
            $expenseIds[] = DB::table('expenses')->insertGetId($this->withExistingColumns('expenses', [
                'tenant_id' => $tenantId,
                'expense_number' => $i,
                'created_by' => $users['operator'],
                'expense_date' => $createdAt->toDateString(),
                'description' => 'Despesa teste '.$i,
                'category' => $categories[$i % count($categories)],
                'amount' => 25 + ($i % 70) * 6.3,
                'notes' => 'Despesa gerada para testes funcionais.',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]));
        }

        if (Schema::hasTable('expense_logs')) {
            $rows = [];
            foreach ($expenseIds as $expenseId) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'expense_id' => $expenseId,
                    'user_id' => $users['operator'],
                    'action' => 'created',
                    'data' => json_encode(['seed' => 'functional_test']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $this->insertChunks('expense_logs', $rows);
        }
    }

    private function seedPayments(int $tenantId, ?int $planId, Carbon $now): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        for ($i = 1; $i <= 6; $i++) {
            DB::table('payments')->insert([
                'tenant_id' => $tenantId,
                'gateway' => 'efi',
                'method' => 'pix',
                'payment_id' => 'functional-test-'.$tenantId.'-'.$i,
                'amount' => $planId ? (DB::table('plans')->where('id', $planId)->value('value') ?? 99.90) : 99.90,
                'status' => 'paid',
                'paid_at' => $now->copy()->subDays($i * 10),
                'idempotency_key' => 'functional-test-key-'.$tenantId.'-'.$i,
                'expires_at' => $now->copy()->addMonth(),
                'raw_response' => json_encode(['seed' => 'functional_test']),
                'created_at' => $now->copy()->subDays($i * 10),
                'updated_at' => $now->copy()->subDays($i * 10),
            ]);
        }
    }

    private function seedOperationalAudits(int $tenantId, array $users, array $orderIds, Carbon $now): void
    {
        if (! Schema::hasTable('operational_audits')) {
            return;
        }

        $rows = [];
        foreach (array_slice($orderIds, 0, 300) as $index => $orderId) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'user_id' => $users['all'][$index % count($users['all'])],
                'entity_type' => 'order',
                'entity_id' => $orderId,
                'action' => ['created', 'updated', 'payment_registered', 'follow_up_sent'][$index % 4],
                'data' => json_encode(['seed' => 'functional_test']),
                'created_at' => $now->copy()->subMinutes($index),
            ];
        }
        $this->insertChunks('operational_audits', $rows);
    }

    private function refreshCashSessions(array $cashSessionIds): void
    {
        foreach ($cashSessionIds as $id) {
            $sales = (float) DB::table('sales')
                ->where('cash_session_id', $id)
                ->where('status', 'completed')
                ->sum('paid_amount');
            $cancelled = (float) DB::table('sales')
                ->where('cash_session_id', $id)
                ->where('status', 'cancelled')
                ->sum('total_amount');
            $orderPayments = (float) DB::table('order_payments')
                ->where('cash_session_id', $id)
                ->sum('amount');
            $session = DB::table('cash_sessions')->where('id', $id)->first();
            $expected = (float) $session->opening_balance + (float) $session->manual_entries + $sales + $orderPayments - (float) $session->manual_exits;

            DB::table('cash_sessions')->where('id', $id)->update($this->withExistingColumns('cash_sessions', [
                'total_completed_sales' => $sales,
                'total_cancelled_sales' => $cancelled,
                'total_order_payments' => $orderPayments,
                'expected_balance' => $expected,
                'closing_balance' => $session->status === 'closed' ? $expected : null,
                'difference' => $session->status === 'closed' ? 0 : null,
                'updated_at' => now(),
            ]));
        }
    }

    private function cleanupTestTenant(): void
    {
        $tenantIds = DB::table('tenants')
            ->where('email', 'tenant-teste@example.test')
            ->orWhere('email', 'like', 'stress-tenant-%@example.test')
            ->pluck('id')
            ->all();

        if ($tenantIds === []) {
            return;
        }

        $orderIds = DB::table('orders')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();
        $saleIds = DB::table('sales')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();
        $expenseIds = Schema::hasTable('expenses') ? DB::table('expenses')->whereIn('tenant_id', $tenantIds)->pluck('id')->all() : [];
        $cashSessionIds = DB::table('cash_sessions')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();

        Schema::disableForeignKeyConstraints();
        DB::table('sale_items')->whereIn('sale_id', $saleIds)->delete();
        DB::table('sale_logs')->whereIn('sale_id', $saleIds)->delete();
        DB::table('order_parts')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_payments')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_status_history')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_logs')->whereIn('order_id', $orderIds)->delete();
        DB::table('cash_session_logs')->whereIn('cash_session_id', $cashSessionIds)->delete();
        if (Schema::hasTable('expense_logs')) {
            DB::table('expense_logs')->whereIn('expense_id', $expenseIds)->delete();
        }
        foreach ([
            'operational_audits',
            'part_movements',
            'fiscal_documents',
            'fiscal_settings',
            'payments',
            'expenses',
            'sales',
            'cash_session_movements',
            'cash_sessions',
            'messages',
            'schedules',
            'images',
            'orders',
            'customer_equipments',
            'budgets',
            'checklists',
            'equipment',
            'parts',
            'customers',
            'receipts',
            'whatsapp_messages',
            'others',
            'settings',
            'branches',
            'companies',
            'users',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                DB::table($table)->whereIn('tenant_id', $tenantIds)->delete();
            }
        }
        DB::table('tenants')->whereIn('id', $tenantIds)->delete();
        Schema::enableForeignKeyConstraints();
    }

    private function withExistingColumns(string $table, array $payload): array
    {
        return array_filter(
            $payload,
            fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function insertChunks(string $table, array $rows, int $size = 500): void
    {
        foreach (array_chunk($rows, $size) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function digits(int $length, int $seed): string
    {
        return str_pad((string) $seed, $length, '0', STR_PAD_LEFT);
    }

    private function pad(int $value, int $length): string
    {
        return str_pad((string) $value, $length, '0', STR_PAD_LEFT);
    }
}
