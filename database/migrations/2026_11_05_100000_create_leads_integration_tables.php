<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integração de acompanhamento de cadastro com o VetorLeads (VETOROS-LEADS-INTEGRATION).
 * Configuração única (modo manual ou API, endpoint e token cifrado) e fila de saída de eventos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads_integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16)->default('manual');
            $table->string('endpoint_url', 500)->nullable();
            $table->text('token')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_error', 255)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('leads_integration_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('type', 32);
            $table->timestamp('occurred_at');
            $table->json('payload');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads_integration_events');
        Schema::dropIfExists('leads_integration_settings');
    }
};
