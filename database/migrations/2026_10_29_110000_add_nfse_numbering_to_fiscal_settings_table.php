<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração da DPS (NFS-e nacional) gerida pela Spedy: guardamos apenas o número enviado por último
 * em configuração, nunca uma sequência concorrente. A Spedy incrementa o número a cada emissão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('nfse_next_number')->nullable()->after('default_nfse_series');
            $table->timestamp('nfse_numbering_synced_at')->nullable()->after('nfse_next_number');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->dropColumn(['nfse_next_number', 'nfse_numbering_synced_at']);
        });
    }
};
