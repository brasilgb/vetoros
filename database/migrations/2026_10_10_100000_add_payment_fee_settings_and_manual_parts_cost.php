<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VETOR-INTEL-02.1: taxas de pagamento configuráveis e custo de materiais avulsos.
     *
     * Nada é estimado: custo avulso de OS antiga fica null (desconhecido) e pagamento sem
     * taxa comprovada fica com taxa/líquido null.
     */
    public function up(): void
    {
        // Taxa por meio de pagamento, por tenant. Sem linha para o meio = taxa desconhecida.
        Schema::create('payment_fee_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('payment_method', 30);
            $table->decimal('fee_percentage', 6, 3)->default(0);
            $table->decimal('fee_fixed_amount', 10, 2)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'payment_method']);
        });

        Schema::table('order_payments', function (Blueprint $table) {
            // Parâmetros da configuração aplicados no pagamento (congelados com a taxa).
            $table->decimal('fee_percentage', 6, 3)->nullable()->after('fee_source');
            $table->decimal('fee_fixed_amount', 10, 2)->nullable()->after('fee_percentage');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Custo dos materiais avulsos. Null = desconhecido; 0 = custo zero informado.
            $table->decimal('manual_parts_cost', 10, 2)->nullable()->after('manual_parts_value');
        });

        // INTEL-03 gravava "not_informed" com taxa 0 e líquido = bruto. Zero não comprovado é
        // dado inventado: volta a ser desconhecido (null), como os pagamentos legados.
        DB::table('order_payments')
            ->where('fee_source', 'not_informed')
            ->update(['fee_amount' => null, 'net_amount' => null, 'fee_source' => null]);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('manual_parts_cost'));
        Schema::table('order_payments', fn (Blueprint $table) => $table->dropColumn(['fee_percentage', 'fee_fixed_amount']));
        Schema::dropIfExists('payment_fee_settings');
    }
};
