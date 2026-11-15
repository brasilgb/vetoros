<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Último próximo número da DPS que a Spedy devolveu nas respostas de configuração, e quando.
 * Serve para bloquear a emissão de NFS-e quando o contador conhecido está zerado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('nfse_spedy_next_number')->nullable()->after('nfse_numbering_synced_at');
            $table->timestamp('nfse_spedy_checked_at')->nullable()->after('nfse_spedy_next_number');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->dropColumn(['nfse_spedy_next_number', 'nfse_spedy_checked_at']);
        });
    }
};
