<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código de indicação do VetorLeads com o qual a conta se cadastrou (VETOROS-LEADS-INTEGRATION).
 * Vai no evento "registered" para o VetorLeads vincular o lead certo. Opaco, sem dados pessoais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->char('leads_referral_code', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['leads_referral_code']);
            $table->dropColumn('leads_referral_code');
        });
    }
};
