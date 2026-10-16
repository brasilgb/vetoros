<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VETOR-SAAS-CONTRATO-01: contrato comercial personalizado por tenant. Aditiva: todo tenant
 * existente continua no plano tradicional (`billing_mode = plan`) e sem NFS-e automática do SaaS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_mode', 20)->default('plan')->after('period_id');
            $table->boolean('saas_invoice_enabled')->default(false)->after('automatic_fiscal_emission_enabled');
            $table->timestamp('saas_invoice_enabled_at')->nullable()->after('saas_invoice_enabled');
        });

        Schema::create('tenant_commercial_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->string('contract_number', 40)->unique();
            // Valor total de cada ciclo (não um valor mensal multiplicado).
            $table->decimal('amount', 12, 2);
            $table->unsignedTinyInteger('billing_months');
            $table->date('start_date');
            $table->date('next_due_date');
            $table->string('sales_rep', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_subscription_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_commercial_contract_id');
            // Nome explícito: o padrão passa do limite de 64 caracteres do MySQL.
            $table->foreign('tenant_commercial_contract_id', 'tenant_charges_contract_fk')->references('id')->on('tenant_commercial_contracts')->restrictOnDelete();
            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->date('due_date');
            $table->date('fiscal_scheduled_for')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('payment_note', 255)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('fiscal_queued_at')->nullable();
            $table->timestamp('invoice_sent_at')->nullable();
            $table->timestamps();

            // Uma cobrança por ciclo: jobs repetidos ou concorrentes não duplicam.
            $table->unique(['tenant_commercial_contract_id', 'cycle_start'], 'tenant_charges_cycle_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'fiscal_scheduled_for']);
        });

        Schema::create('tenant_subscription_charge_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_subscription_charge_id');
            $table->foreign('tenant_subscription_charge_id', 'tenant_charge_deliveries_charge_fk')->references('id')->on('tenant_subscription_charges')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('email', 190)->nullable();
            $table->string('status', 20);
            $table->string('error', 1000)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_subscription_charge_id', 'kind', 'status'], 'tenant_charge_deliveries_lookup');
        });

        Schema::table('admin_fiscal_documents', function (Blueprint $table) {
            $table->foreignId('tenant_subscription_charge_id')->nullable()->after('payment_id')->constrained()->nullOnDelete();
            $table->boolean('automatic')->default(false)->after('tenant_subscription_charge_id');
        });
    }

    public function down(): void
    {
        Schema::table('admin_fiscal_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_subscription_charge_id');
            $table->dropColumn('automatic');
        });

        Schema::dropIfExists('tenant_subscription_charge_deliveries');
        Schema::dropIfExists('tenant_subscription_charges');
        Schema::dropIfExists('tenant_commercial_contracts');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'saas_invoice_enabled', 'saas_invoice_enabled_at']);
        });
    }
};
