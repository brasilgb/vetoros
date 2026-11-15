<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de tentativas de cada nota fiscal (VETOR-FISCAL-AVULSA-01): envio, reprocessamento
 * e atualizações vindas da Spedy. Só acrescenta linhas; nunca altera a nota nem a numeração.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')->constrained('fiscal_documents')->cascadeOnDelete();
            $table->string('action', 20);
            $table->string('status', 20);
            $table->string('provider_status', 40)->nullable();
            $table->string('error_code', 20)->nullable();
            $table->text('message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'fiscal_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_attempts');
    }
};
