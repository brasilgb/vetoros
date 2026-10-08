<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VETOR-INTEL-02: integridade financeira, custos históricos e prazos.
     *
     * Nenhum custo ou preço histórico é reconstruído. Os únicos preenchimentos são:
     * - decomposição dos totais já gravados da OS (peças avulsas, desconto, acréscimo),
     *   para que o próximo salvamento calculado no servidor resulte exatamente no mesmo
     *   total que já estava persistido. Não altera service_cost, parts_value nem itens.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('manual_parts_value', 10, 2)->default(0)->after('parts_value');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('service_value');
            $table->decimal('surcharge_amount', 10, 2)->default(0)->after('discount_amount');
            // Null = total legado (calculado pelo navegador antes desta etapa).
            $table->timestamp('totals_calculated_at')->nullable()->after('service_cost');
            // Primeiro prazo prometido. Null em OS legadas: o prazo atual pode já ter sido renegociado.
            $table->date('original_delivery_forecast')->nullable()->after('delivery_forecast');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('total_cost', 14, 2)->nullable()->after('unit_cost');
            // Momento em que preço e custo foram congelados. Null = item legado (valores
            // regravados a cada salvamento antes desta etapa; não comprovadamente históricos).
            $table->timestamp('pricing_snapshot_at')->nullable()->after('total_cost');
            $table->index(['order_id', 'source_type', 'source_id'], 'order_items_order_source_idx');
        });

        Schema::table('part_movements', function (Blueprint $table) {
            // Null em movimentos anteriores a esta etapa: custo desconhecido, não inventado.
            $table->decimal('unit_cost', 12, 2)->nullable()->after('quantity');
            $table->decimal('total_cost', 14, 2)->nullable()->after('unit_cost');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });

        $this->decomposeLegacyOrderTotals();
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::table('part_movements', fn (Blueprint $table) => $table->dropColumn(['unit_cost', 'total_cost']));
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_source_idx');
            $table->dropColumn(['total_cost', 'pricing_snapshot_at']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn([
            'manual_parts_value',
            'discount_amount',
            'surcharge_amount',
            'totals_calculated_at',
            'original_delivery_forecast',
        ]));
    }

    /**
     * total = serviço + peças de estoque (itens) + peças avulsas + acréscimo − desconto.
     * Para cada OS, escolhe peças avulsas/desconto/acréscimo de modo que a fórmula
     * reproduza exatamente o service_cost já gravado.
     */
    private function decomposeLegacyOrderTotals(): void
    {
        DB::table('orders')
            ->select(['id', 'service_value', 'parts_value', 'service_cost'])
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                $stockTotals = DB::table('order_items')
                    ->whereIn('order_id', $orders->pluck('id'))
                    ->where('item_type', 'product')
                    ->groupBy('order_id')
                    ->pluck(DB::raw('SUM(total_price)'), 'order_id');

                foreach ($orders as $order) {
                    $service = round((float) ($order->service_value ?? 0), 2);
                    $parts = round((float) ($order->parts_value ?? 0), 2);
                    $total = round((float) ($order->service_cost ?? 0), 2);
                    $stock = round((float) ($stockTotals[$order->id] ?? 0), 2);

                    $manual = max(0, round($parts - $stock, 2));
                    $discount = max(0, round($stock - $parts, 2));
                    $difference = round($total - ($service + $parts), 2);
                    $surcharge = max(0, $difference);
                    $discount = round($discount + max(0, -$difference), 2);

                    if ($manual > 0 || $discount > 0 || $surcharge > 0) {
                        DB::table('orders')->where('id', $order->id)->update([
                            'manual_parts_value' => $manual,
                            'discount_amount' => $discount,
                            'surcharge_amount' => $surcharge,
                        ]);
                    }
                }
            });
    }
};
