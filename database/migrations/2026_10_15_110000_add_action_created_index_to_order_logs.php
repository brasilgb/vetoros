<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desempenho: o alerta comercial do menu e os indicadores de follow-up filtram
 * order_logs por ação e período. Sem este índice a consulta percorria todo o
 * histórico de logs das ordens do tenant (só havia order_id + created_at).
 *
 * Aditiva: só cria o índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at'], 'order_logs_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_logs', function (Blueprint $table) {
            $table->dropIndex('order_logs_action_created_idx');
        });
    }
};
