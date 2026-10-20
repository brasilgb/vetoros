<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VETOROS-EFI-TEST-01: cobranças de teste da integração Efí criadas pelo RootAdmin. Tabela própria,
 * sem tenant, contrato, assinatura ou `payments`: nada daqui entra no financeiro, em notas fiscais
 * ou em indicadores. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efi_integration_tests', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30)->default('integration_test');
            $table->string('method', 20);
            $table->string('environment', 20);
            $table->decimal('amount', 10, 2);
            // txid do Pix ou charge_id da API Cobranças.
            $table->string('provider_id', 60)->nullable()->index();
            $table->string('reference', 60)->unique();
            $table->string('status', 20)->default('pending');
            $table->string('provider_status', 40)->nullable();
            // Dados exibíveis: copia e cola, QR Code, linha digitável, links, máscara do cartão.
            $table->json('details')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('webhook_received_at')->nullable();
            $table->unsignedInteger('webhook_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efi_integration_tests');
    }
};
