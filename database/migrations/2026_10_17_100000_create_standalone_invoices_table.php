<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emissão fiscal avulsa (VETOR-FISCAL-AVULSA-01): origem própria dos documentos fiscais
 * emitidos direto pela tela Notas Fiscais, sem OS, venda ou contrato. Guarda a solicitação
 * (tomador, itens/serviço, tributação aplicada e opção financeira) e é o `documentable` da
 * nota em `fiscal_documents`. A chave da solicitação torna o envio idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->string('type', 20);
            $table->string('status', 20)->default('active');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->text('description')->nullable();
            $table->json('items')->nullable();
            $table->json('tax_data')->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->string('payment_method', 30)->nullable();
            $table->string('finance_mode', 20)->default('none');
            $table->foreignId('account_receivable_id')->nullable()->constrained('accounts_receivable')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'request_key'], 'standalone_invoices_request_unique');
            $table->index(['tenant_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_invoices');
    }
};
