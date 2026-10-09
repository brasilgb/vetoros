<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VETOR-FISCAL-05.3: NFS-e programada por ciclo, independente do pagamento.
 *
 * Aditiva e compatível com o que existe:
 * - maintenance_contracts.invoice_competence: qual mês o ciclo fatura (padrão: mês do vencimento);
 * - maintenance_contracts.auto_issue_enabled_at: desde quando a emissão automática vale. Só ciclos
 *   programados a partir dessa data são emitidos sozinhos (ligar a opção não emite o histórico).
 *   Contratos que já tinham a opção ligada recebem a data da última alteração;
 * - accounts_receivable: competência (início/fim), data fiscal programada e o carimbo de quando a
 *   emissão foi enfileirada (o agendador não reenfileira à toa). Cobranças antigas ficam sem data
 *   programada e, por isso, nunca são emitidas automaticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->string('invoice_competence', 20)->default('due_month')->after('auto_send_invoice');
            $table->timestamp('auto_issue_enabled_at')->nullable()->after('invoice_competence');
        });

        DB::table('maintenance_contracts')
            ->where('auto_issue_invoice', true)
            ->update(['auto_issue_enabled_at' => DB::raw('updated_at')]);

        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->date('competence_start')->nullable()->after('due_date');
            $table->date('competence_end')->nullable()->after('competence_start');
            $table->date('fiscal_scheduled_for')->nullable()->after('competence_end');
            $table->timestamp('fiscal_queued_at')->nullable()->after('fiscal_scheduled_for');

            $table->index(['source_type', 'fiscal_scheduled_for'], 'accounts_receivable_fiscal_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_receivable', function (Blueprint $table) {
            $table->dropIndex('accounts_receivable_fiscal_schedule_idx');
            $table->dropColumn(['competence_start', 'competence_end', 'fiscal_scheduled_for', 'fiscal_queued_at']);
        });

        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->dropColumn(['invoice_competence', 'auto_issue_enabled_at']);
        });
    }
};
