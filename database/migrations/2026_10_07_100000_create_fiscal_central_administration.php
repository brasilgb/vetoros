<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuração central da conta Spedy da plataforma (linha única).
        // Precedência: valor gravado aqui > variável de ambiente.
        Schema::create('spedy_platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->nullable();
            $table->text('owner_api_key')->nullable();
            $table->string('owner_api_key_last4', 4)->nullable();
            $table->timestamp('owner_api_key_rotated_at')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_id', 64)->nullable();
            $table->string('webhook_url', 200)->nullable();
            $table->timestamp('webhook_configured_at')->nullable();
            $table->timestamp('last_diagnostic_at')->nullable();
            $table->boolean('last_diagnostic_ok')->nullable();
            $table->string('last_diagnostic_message', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fiscal_admin_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Liberações por modelo e aprovação de produção, controladas pelo RootAdmin.
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->boolean('nfe_allowed')->default(false)->after('nfce_enabled');
            $table->boolean('nfce_allowed')->default(false)->after('nfe_allowed');
            $table->boolean('nfse_allowed')->default(false)->after('nfce_allowed');
            $table->timestamp('production_released_at')->nullable()->after('emission_environment');
            $table->foreignId('production_released_by')->nullable()->after('production_released_at')->constrained('users')->nullOnDelete();
        });

        // Emitente próprio da plataforma (ABrasil Sistemas) para as notas do SaaS.
        Schema::table('admin_fiscal_settings', function (Blueprint $table) {
            $table->string('spedy_company_id', 64)->nullable()->after('provider');
            $table->string('registration_status', 20)->default('pending')->after('spedy_company_id');
            $table->text('registration_error')->nullable()->after('registration_status');
            $table->timestamp('registered_at')->nullable()->after('registration_error');
            $table->string('emission_environment', 20)->default('homologation')->after('registered_at');
            $table->timestamp('production_released_at')->nullable()->after('emission_environment');
            $table->string('certificate_subject', 255)->nullable()->after('production_released_at');
            $table->timestamp('certificate_expires_at')->nullable()->after('certificate_subject');
            $table->string('nfse_mode', 20)->default('national')->after('certificate_expires_at');
            $table->string('nfse_taxation_type', 40)->nullable()->after('nfse_mode');
            $table->string('default_nfse_series', 20)->nullable()->after('nfse_taxation_type');
            $table->string('email', 120)->nullable()->after('default_nfse_series');
            $table->timestamp('tax_settings_confirmed_at')->nullable()->after('email');
        });

        Schema::table('admin_fiscal_documents', function (Blueprint $table) {
            $table->string('integration_id', 36)->nullable()->unique()->after('provider_reference');
            $table->string('provider_status', 30)->nullable()->after('status');
            $table->string('authorization_protocol', 120)->nullable()->after('access_key');
            $table->date('reference_start')->nullable()->after('description');
            $table->date('reference_end')->nullable()->after('reference_start');
            $table->timestamp('submitted_at')->nullable()->after('issued_at');
            $table->timestamp('cancelled_at')->nullable()->after('submitted_at');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');
            $table->string('xml_path', 255)->nullable()->after('xml_url');
            $table->string('pdf_path', 255)->nullable()->after('xml_path');
            $table->char('xml_sha256', 64)->nullable()->after('pdf_path');
        });

        Schema::create('admin_fiscal_document_deliveries', function (Blueprint $table) {
            $table->id();
            // Nome explícito: o padrão passa de 64 caracteres no MySQL.
            $table->foreignId('admin_fiscal_document_id')->constrained('admin_fiscal_documents', indexName: 'admin_fiscal_deliveries_document_fk')->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_fiscal_document_deliveries');

        Schema::table('admin_fiscal_documents', function (Blueprint $table) {
            $table->dropUnique(['integration_id']);
            $table->dropColumn([
                'integration_id', 'provider_status', 'authorization_protocol', 'reference_start', 'reference_end',
                'submitted_at', 'cancelled_at', 'cancel_reason', 'xml_path', 'pdf_path', 'xml_sha256',
            ]);
        });

        Schema::table('admin_fiscal_settings', function (Blueprint $table) {
            $table->dropColumn([
                'spedy_company_id', 'registration_status', 'registration_error', 'registered_at', 'emission_environment',
                'production_released_at', 'certificate_subject', 'certificate_expires_at', 'nfse_mode', 'nfse_taxation_type',
                'default_nfse_series', 'email', 'tax_settings_confirmed_at',
            ]);
        });

        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_released_by');
            $table->dropColumn(['nfe_allowed', 'nfce_allowed', 'nfse_allowed', 'production_released_at']);
        });

        Schema::dropIfExists('fiscal_admin_audits');
        Schema::dropIfExists('spedy_platform_settings');
    }
};
