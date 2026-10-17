<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VETOR-SAAS-EFI-02: recebimento das assinaturas SaaS pela Efí Bank (Pix, boleto/Bolix, cartão e
 * recorrência no cartão). Aditiva. As transações da Efí reutilizam `payments`, ligadas à cobrança local.
 * A coluna `active_provider` (escolha de provedor) foi removida na VETOR-SAAS-EFI-03
 * (2026_10_16_140000), que tornou a Efí o único gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Linha única, só RootAdmin. Segredos criptografados com a APP_KEY (cast `encrypted`).
        Schema::create('saas_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('active_provider', 20)->default('mercadopago');
            $table->string('efi_environment', 20)->default('homologation');
            $table->text('efi_charges_client_id')->nullable();
            $table->text('efi_charges_client_secret')->nullable();
            $table->text('efi_pix_client_id')->nullable();
            $table->text('efi_pix_client_secret')->nullable();
            $table->longText('efi_pix_certificate')->nullable();
            $table->string('efi_pix_certificate_subject', 190)->nullable();
            $table->timestamp('efi_pix_certificate_expires_at')->nullable();
            $table->string('efi_pix_key', 120)->nullable();
            $table->string('efi_payee_code', 64)->nullable();
            $table->string('efi_account_reference', 120)->nullable();
            $table->json('efi_methods')->nullable();
            $table->text('efi_webhook_secret')->nullable();
            $table->string('efi_pix_webhook_url', 500)->nullable();
            $table->timestamp('efi_pix_webhook_registered_at')->nullable();
            $table->json('efi_plans')->nullable();
            $table->timestamp('efi_homologated_at')->nullable();
            $table->foreignId('efi_homologated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_connection_test_at')->nullable();
            $table->boolean('last_connection_ok')->nullable();
            $table->string('last_connection_message', 500)->nullable();
            $table->timestamp('last_pix_notification_at')->nullable();
            $table->timestamp('last_charges_notification_at')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('tenant_subscription_charge_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->after('tenant_subscription_charge_id')->constrained()->nullOnDelete();
            $table->string('method', 30)->nullable()->after('gateway');
            // Identificação única local (txid do Pix ou custom_id da API Cobranças).
            $table->string('reference', 60)->nullable()->unique()->after('payment_id');
            $table->string('provider_subscription_id', 60)->nullable()->after('reference')->index();
            $table->date('due_date')->nullable()->after('expires_at');
            $table->timestamp('paid_at')->nullable()->after('due_date');
            $table->decimal('paid_amount', 10, 2)->nullable()->after('paid_at');
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('paid_amount');
            // Dados de pagamento exibíveis ao cliente (copia e cola, linha digitável, links, máscara do cartão).
            $table->json('details')->nullable()->after('raw_response');
            $table->timestamp('last_checked_at')->nullable()->after('details');
            $table->string('error', 500)->nullable()->after('last_checked_at');

            $table->index(['gateway', 'status']);
        });

        // Notificações recebidas: o par (canal, chave) impede processar o mesmo evento duas vezes.
        Schema::create('saas_payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('channel', 20);
            $table->string('event_key', 120);
            $table->string('status', 20)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'channel', 'event_key'], 'saas_payment_events_unique');
            $table->index(['status', 'updated_at']);
        });

        // Recorrência no cartão autorizada pelo titular (assinatura nativa da Efí).
        Schema::create('saas_recurring_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('provider_subscription_id', 60);
            $table->string('provider_plan_id', 60)->nullable();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('interval_months');
            $table->decimal('amount', 12, 2);
            $table->string('card_mask', 40)->nullable();
            $table->string('status', 20);
            $table->timestamp('authorized_at')->nullable();
            $table->string('authorized_ip', 45)->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_subscription_id'], 'saas_recurring_provider_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_recurring_authorizations');
        Schema::dropIfExists('saas_payment_events');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway', 'status']);
            $table->dropConstrainedForeignId('tenant_subscription_charge_id');
            $table->dropConstrainedForeignId('plan_id');
            $table->dropUnique(['reference']);
            $table->dropIndex(['provider_subscription_id']);
            $table->dropColumn(['method', 'reference', 'provider_subscription_id', 'due_date', 'paid_at', 'paid_amount', 'refunded_amount', 'details', 'last_checked_at', 'error']);
        });

        Schema::dropIfExists('saas_payment_settings');
    }
};
