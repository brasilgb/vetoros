<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VETOR-SAAS-EFI-03: a Efí é o único gateway das assinaturas SaaS.
 * - Sai a escolha de provedor (`saas_payment_settings.active_provider`): sem homologação da Efí,
 *   novas cobranças externas ficam bloqueadas. É configuração, não histórico.
 * - O padrão de `payments.gateway` passa a ser `efi`. Só o default muda: nenhuma linha existente é
 *   alterada, e o histórico do provedor anterior continua com o `gateway` original.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('saas_payment_settings', 'active_provider')) {
            Schema::table('saas_payment_settings', function (Blueprint $table) {
                $table->dropColumn('active_provider');
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway')->default('efi')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway')->default('mercadopago')->change();
        });

        if (! Schema::hasColumn('saas_payment_settings', 'active_provider')) {
            Schema::table('saas_payment_settings', function (Blueprint $table) {
                $table->string('active_provider', 20)->default('mercadopago')->after('id');
            });
        }
    }
};
