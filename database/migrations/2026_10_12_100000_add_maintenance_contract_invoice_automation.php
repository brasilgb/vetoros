<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VETOR-FISCAL-05: NFS-e automática dos contratos de manutenção.
 *
 * Aditiva:
 * - maintenance_contracts: duas opções, desligadas por padrão (inclusive nos contratos existentes);
 * - account_receivable_payments: recebimentos de contas a receber (baixa manual ou evento
 *   integrado), com estorno auditável e vínculo com o movimento do caixa;
 * - fiscal_document_deliveries: envios da nota ao cliente (automático ou reenvio), sem
 *   relação com a emissão (falha de e-mail nunca gera outra nota).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->boolean('auto_issue_invoice')->default(false)->after('notes');
            $table->boolean('auto_send_invoice')->default(false)->after('auto_issue_invoice');
        });

        Schema::create('account_receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_receivable_id')->constrained('accounts_receivable')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->dateTime('paid_at');
            $table->string('payment_method', 30);
            $table->string('source', 20)->default('manual');
            // Identificador do evento do provedor (pagamento integrado): impede baixa duplicada.
            $table->string('external_reference', 120)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_session_movement_id')->nullable()->constrained('cash_session_movements')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source', 'external_reference'], 'receivable_payments_external_unique');
            $table->index(['tenant_id', 'account_receivable_id'], 'receivable_payments_receivable_idx');
        });

        Schema::create('fiscal_document_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')->constrained('fiscal_documents')->cascadeOnDelete();
            $table->string('email', 190)->nullable();
            $table->string('status', 20);
            $table->string('origin', 20);
            $table->string('error', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['fiscal_document_id', 'status'], 'fiscal_deliveries_document_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_deliveries');
        Schema::dropIfExists('account_receivable_payments');

        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->dropColumn(['auto_issue_invoice', 'auto_send_invoice']);
        });
    }
};
