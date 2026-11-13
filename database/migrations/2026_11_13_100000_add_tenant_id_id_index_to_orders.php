<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VETOROS-PERFORMANCE-OS-03 (revista na OS-04).
 *
 * Índice (tenant_id, id): a listagem ordena por id DESC filtrando o tenant. Sem ele, o
 * MySQL/MariaDB percorre a chave primária de trás para frente atravessando as OS de todos os
 * outros tenants (medido: ~375 ms com 50 tenants e 202 mil OS; 1,4 ms com o índice).
 * ADD INDEX é online no InnoDB (sem bloquear leituras e escritas).
 *
 * A coluna public_access_key_check prevista na OS-03 não é mais criada: a consulta pública de OS
 * foi removida na OS-04 e a verificação da chave deixou de existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('orders', 'orders_tenant_id_id_idx')) {
            Schema::table('orders', fn (Blueprint $table) => $table->index(['tenant_id', 'id'], 'orders_tenant_id_id_idx'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('orders', 'orders_tenant_id_id_idx')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropIndex('orders_tenant_id_id_idx'));
        }
    }
};
