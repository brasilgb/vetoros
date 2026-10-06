<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            // Último envio à Spedy: base da reconciliação de envios sem resposta.
            $table->timestamp('submitted_at')->nullable()->after('cancel_reason');
            // Cópia local (disco privado) do XML/PDF autorizados, para guarda e rastreabilidade.
            $table->string('xml_path', 255)->nullable()->after('xml_url');
            $table->string('pdf_path', 255)->nullable()->after('xml_path');
            $table->char('xml_sha256', 64)->nullable()->after('pdf_path');
        });

        Schema::table('fiscal_settings', function (Blueprint $table) {
            // Confirmação explícita do tenant de que os dados tributários foram
            // validados; sem ela a emissão nativa fica bloqueada.
            $table->timestamp('tax_settings_confirmed_at')->nullable()->after('default_cofins_situation');
            $table->foreignId('tax_settings_confirmed_by')->nullable()->after('tax_settings_confirmed_at')->constrained('users')->nullOnDelete();
            $table->string('nfse_taxation_type', 40)->nullable()->after('nfse_operation_indicator');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_settings_confirmed_by');
            $table->dropColumn(['tax_settings_confirmed_at', 'nfse_taxation_type']);
        });

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'xml_path', 'pdf_path', 'xml_sha256']);
        });
    }
};
