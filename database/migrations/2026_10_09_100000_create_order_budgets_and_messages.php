<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VETOR-INTEL-03: orçamento versionado, comunicação rastreável e taxas de pagamento.
     *
     * Legado: no máximo uma versão "legada" por OS com o orçamento atual, sem nenhuma
     * data inventada (sent_at/approved_at/etc. nulos) e sem itens. Pagamentos antigos
     * ficam com taxa desconhecida (null), não zero.
     */
    public function up(): void
    {
        Schema::create('order_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 20);
            $table->boolean('is_legacy')->default(false);
            // O que o cliente vê e aprova (orders.budget_description / budget_value / budget_link).
            $table->text('description')->nullable();
            $table->decimal('quoted_amount', 10, 2)->nullable();
            $table->text('budget_link')->nullable();
            // Composição da OS no momento da versão (null em versão legada: desconhecida).
            $table->decimal('subtotal_services', 10, 2)->nullable();
            $table->decimal('subtotal_parts', 10, 2)->nullable();
            $table->decimal('manual_parts_value', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('surcharge_amount', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('response_channel', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_type', 20)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_by_type', 20)->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'version']);
            $table->index(['tenant_id', 'status', 'sent_at'], 'order_budgets_tenant_status_sent_idx');
            $table->index(['tenant_id', 'order_id'], 'order_budgets_tenant_order_idx');
        });

        Schema::create('order_budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_budget_id')->constrained('order_budgets')->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 500);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('total_cost', 14, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index('order_budget_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Versão exata aprovada; null quando não há aprovação comprovada (inclusive legado).
            $table->foreignId('approved_budget_id')->nullable()->after('budget_link')
                ->constrained('order_budgets')->nullOnDelete();
        });

        Schema::create('order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_budget_id')->nullable()->constrained('order_budgets')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('direction', 10)->default('outbound');
            $table->string('recipient', 120)->nullable();
            $table->string('template', 50)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->string('status', 20);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id', 'created_at'], 'order_messages_tenant_order_idx');
            $table->index(['tenant_id', 'provider', 'provider_message_id'], 'order_messages_provider_idx');
        });

        Schema::table('order_payments', function (Blueprint $table) {
            // Null em pagamentos anteriores: taxa desconhecida, não zero.
            $table->decimal('fee_amount', 10, 2)->nullable()->after('amount');
            $table->string('fee_source', 20)->nullable()->after('fee_amount');
            $table->decimal('net_amount', 10, 2)->nullable()->after('fee_source');
        });

        $this->createLegacyBudgets();
    }

    public function down(): void
    {
        Schema::table('order_payments', fn (Blueprint $table) => $table->dropColumn(['fee_amount', 'fee_source', 'net_amount']));
        Schema::dropIfExists('order_messages');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_budget_id');
        });
        Schema::dropIfExists('order_budget_items');
        Schema::dropIfExists('order_budgets');
    }

    /**
     * Uma versão legada por OS que já tem orçamento. Status "sent" só quando a OS está
     * hoje em "Orçamento Gerado" (orçamento visível ao cliente, data de envio desconhecida);
     * nos demais casos "legacy" (desfecho desconhecido). Nenhuma data e nenhum item.
     */
    private function createLegacyBudgets(): void
    {
        DB::table('orders')
            ->select(['id', 'tenant_id', 'service_status', 'budget_description', 'budget_value', 'budget_link'])
            ->whereNotNull('tenant_id')
            ->where(function ($query) {
                $query->where(fn ($q) => $q->whereNotNull('budget_description')->where('budget_description', '!=', ''))
                    ->orWhere('budget_value', '>', 0);
            })
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                $now = now();
                $rows = [];

                foreach ($orders as $order) {
                    $rows[] = [
                        'tenant_id' => $order->tenant_id,
                        'order_id' => $order->id,
                        'version' => 1,
                        'status' => (int) $order->service_status === 3 ? 'sent' : 'legacy',
                        'is_legacy' => true,
                        'description' => $order->budget_description,
                        'quoted_amount' => $order->budget_value,
                        'budget_link' => $order->budget_link,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('order_budgets')->insert($rows);
            });
    }
};
