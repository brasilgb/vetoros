<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->index('slug', 'plans_slug_idx');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->index(['subscription_status', 'expires_at'], 'tenants_subscription_expires_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['tenant_id', 'roles', 'status'], 'users_tenant_roles_status_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['tenant_id', 'customer_number'], 'customers_tenant_number_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'service_status', 'created_at'], 'orders_tenant_status_created_idx');
            $table->index(['tenant_id', 'customer_id', 'created_at'], 'orders_tenant_customer_created_idx');
            $table->index(['tenant_id', 'created_at'], 'orders_tenant_created_idx');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['recipient_id', 'status', 'id'], 'messages_recipient_status_id_idx');
            $table->index(['sender_id', 'id'], 'messages_sender_id_idx');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'id'], 'schedules_tenant_status_id_idx');
            $table->index(['tenant_id', 'schedules'], 'schedules_tenant_datetime_idx');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->index(['tenant_id', 'budget_number'], 'budgets_tenant_number_idx');
        });

        Schema::table('checklists', function (Blueprint $table) {
            $table->index(['tenant_id', 'checklist_number'], 'checklists_tenant_number_idx');
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->index(['tenant_id', 'type', 'created_at'], 'parts_tenant_type_created_idx');
            $table->index(['tenant_id', 'is_sellable'], 'parts_tenant_sellable_idx');
        });

        Schema::table('part_movements', function (Blueprint $table) {
            $table->index(['tenant_id', 'part_id', 'created_at'], 'part_movements_tenant_part_created_idx');
            $table->index(['order_id', 'created_at'], 'part_movements_order_created_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['tenant_id', 'sales_number'], 'sales_tenant_number_idx');
            $table->index(['tenant_id', 'status', 'created_at'], 'sales_tenant_status_created_idx');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->index(['sale_id', 'part_id'], 'sale_items_sale_part_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'created_at'], 'payments_tenant_status_created_idx');
            $table->index(['gateway', 'status'], 'payments_gateway_status_idx');
            $table->index('expires_at', 'payments_expires_at_idx');
        });

        Schema::table('order_status_history', function (Blueprint $table) {
            $table->index(['order_id', 'created_at'], 'order_status_history_order_created_idx');
        });

        Schema::table('order_payments', function (Blueprint $table) {
            $table->index(['order_id', 'paid_at'], 'order_payments_order_paid_at_idx');
        });

        Schema::table('order_logs', function (Blueprint $table) {
            $table->index(['order_id', 'created_at'], 'order_logs_order_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $indexes = [
            'plans' => ['plans_slug_idx'],
            'tenants' => ['tenants_subscription_expires_idx'],
            'users' => ['users_tenant_roles_status_idx'],
            'customers' => ['customers_tenant_number_idx'],
            'orders' => ['orders_tenant_status_created_idx', 'orders_tenant_customer_created_idx', 'orders_tenant_created_idx'],
            'messages' => ['messages_recipient_status_id_idx', 'messages_sender_id_idx'],
            'schedules' => ['schedules_tenant_status_id_idx', 'schedules_tenant_datetime_idx'],
            'budgets' => ['budgets_tenant_number_idx'],
            'checklists' => ['checklists_tenant_number_idx'],
            'parts' => ['parts_tenant_type_created_idx', 'parts_tenant_sellable_idx'],
            'part_movements' => ['part_movements_tenant_part_created_idx', 'part_movements_order_created_idx'],
            'sales' => ['sales_tenant_number_idx', 'sales_tenant_status_created_idx'],
            'sale_items' => ['sale_items_sale_part_idx'],
            'payments' => ['payments_tenant_status_created_idx', 'payments_gateway_status_idx', 'payments_expires_at_idx'],
            'order_status_history' => ['order_status_history_order_created_idx'],
            'order_payments' => ['order_payments_order_paid_at_idx'],
            'order_logs' => ['order_logs_order_created_idx'],
        ];

        foreach ($indexes as $table => $names) {
            foreach ($names as $name) {
                $this->dropIndexKeepingForeignKeys($table, $name);
            }
        }
    }

    /**
     * No MySQL, uma FK criada depois deste índice composto passa a usá-lo como
     * índice de suporte; removê-lo direto falha (erro 1553). Antes de remover,
     * cria um índice simples para cada coluna de FK que dependia só dele.
     */
    private function dropIndexKeepingForeignKeys(string $table, string $name): void
    {
        if (DB::getDriverName() === 'mysql') {
            $database = DB::getDatabaseName();
            $leading = DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $name)
                ->where('seq_in_index', 1)
                ->value('column_name');

            $usedByForeignKey = $leading && DB::table('information_schema.key_column_usage')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('column_name', $leading)
                ->whereNotNull('referenced_table_name')
                ->exists();

            $otherIndex = $leading && DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('column_name', $leading)
                ->where('seq_in_index', 1)
                ->where('index_name', '!=', $name)
                ->exists();

            if ($usedByForeignKey && ! $otherIndex) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($leading));
            }
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
    }
};
