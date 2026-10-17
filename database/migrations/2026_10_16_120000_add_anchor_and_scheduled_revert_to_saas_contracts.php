<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VETOR-SAAS-CONTRATO-02 (aditiva):
 * - `anchor_day`: dia contratual de vencimento (1..31), para que 31/01 → 28/02 → 31/03 não escorregue.
 * - `revert_*`: volta ao plano tradicional agendada para uma data efetiva confirmada pelo RootAdmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_commercial_contracts', function (Blueprint $table) {
            $table->unsignedTinyInteger('anchor_day')->nullable()->after('next_due_date');
            $table->date('revert_scheduled_for')->nullable()->after('deactivated_at');
            $table->foreignId('revert_plan_id')->nullable()->after('revert_scheduled_for')->constrained('plans')->nullOnDelete();
            $table->foreignId('revert_period_id')->nullable()->after('revert_plan_id')->constrained('periods')->nullOnDelete();
            $table->foreignId('revert_requested_by')->nullable()->after('revert_period_id')->constrained('users')->nullOnDelete();
        });

        // Contratos existentes: a referência passa a ser o dia do próximo vencimento já gravado.
        DB::table('tenant_commercial_contracts')->orderBy('id')->each(function ($contract) {
            DB::table('tenant_commercial_contracts')->where('id', $contract->id)->update([
                'anchor_day' => (int) date('j', strtotime((string) $contract->next_due_date)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_commercial_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revert_requested_by');
            $table->dropConstrainedForeignId('revert_period_id');
            $table->dropConstrainedForeignId('revert_plan_id');
            $table->dropColumn(['anchor_day', 'revert_scheduled_for']);
        });
    }
};
