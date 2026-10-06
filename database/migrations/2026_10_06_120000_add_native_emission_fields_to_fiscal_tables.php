<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            // Empresa emissora do tenant na conta Spedy da plataforma. A chave dela
            // fica criptografada em api_token (coluna já existente).
            $table->string('spedy_company_id', 64)->nullable()->unique()->after('provider');
            $table->string('registration_status', 20)->default('pending')->after('spedy_company_id');
            $table->text('registration_error')->nullable()->after('registration_status');
            $table->timestamp('registered_at')->nullable()->after('registration_error');
            $table->string('emission_environment', 20)->default('homologation')->after('registered_at');
            $table->string('certificate_subject', 255)->nullable()->after('emission_environment');
            $table->timestamp('certificate_expires_at')->nullable()->after('certificate_subject');
            $table->boolean('nfce_enabled')->default(false)->after('nfse_enabled');
            $table->string('default_nfce_series', 20)->nullable()->after('default_nfse_series');
            $table->string('nfce_csc_id', 20)->nullable()->after('default_nfce_series');
            $table->text('nfce_csc')->nullable()->after('nfce_csc_id');
        });

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->string('integration_id', 36)->nullable()->after('provider_reference');
            $table->string('provider_status', 30)->nullable()->after('status');
            $table->string('authorization_protocol', 120)->nullable()->after('access_key');
            $table->timestamp('cancelled_at')->nullable()->after('issued_at');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');
            $table->foreignId('requested_by')->nullable()->after('registered_by')->constrained('users')->nullOnDelete();

            $table->unique('integration_id');
            // Índice simples: documentos legados (Focus) podem repetir a referência.
            $table->index(['provider', 'provider_reference']);
        });

        Schema::create('fiscal_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 64);
            $table->string('event', 60);
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_webhook_events');

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_reference']);
            $table->dropUnique(['integration_id']);
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['integration_id', 'provider_status', 'authorization_protocol', 'cancelled_at', 'cancel_reason']);
        });

        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->dropUnique(['spedy_company_id']);
            $table->dropColumn([
                'spedy_company_id',
                'registration_status',
                'registration_error',
                'registered_at',
                'emission_environment',
                'certificate_subject',
                'certificate_expires_at',
                'nfce_enabled',
                'default_nfce_series',
                'nfce_csc_id',
                'nfce_csc',
            ]);
        });
    }
};
