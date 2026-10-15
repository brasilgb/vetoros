<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VETOR-FISCAL-01: o vínculo com a Spedy (id da empresa e chave) pertence a uma conta,
 * e sandbox e produção são contas separadas. Guarda em qual ambiente o vínculo foi criado
 * e quando foi sincronizado pela última vez.
 *
 * Aditiva. Vínculos existentes recebem o ambiente atual da plataforma: a troca de ambiente
 * pelo painel já descarta os cadastros do ambiente anterior, então eles são do ambiente atual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->string('spedy_environment', 20)->nullable()->after('spedy_company_id');
            $table->timestamp('last_synced_at')->nullable()->after('registered_at');
        });

        $environment = DB::table('spedy_platform_settings')->orderBy('id')->value('environment')
            ?? config('services.spedy.environment');

        DB::table('fiscal_settings')
            ->whereNotNull('spedy_company_id')
            ->update([
                'spedy_environment' => $environment === 'production' ? 'production' : 'sandbox',
                'last_synced_at' => DB::raw('registered_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->dropColumn(['spedy_environment', 'last_synced_at']);
        });
    }
};
