<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo Suporte (VETOR-STABILIZE-03): auditoria das entradas do RootAdmin no painel das empresas.
 * Só criação de tabelas novas; nenhum dado existente é alterado. Nada de senhas, tokens ou corpo das
 * requisições: de cada ação ficam método, rota, caminho, status e se foi bloqueada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('root_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
            $table->index(['root_user_id', 'ended_at']);
        });

        Schema::create('support_session_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_session_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('route_name', 150)->nullable();
            $table->string('path', 255);
            $table->unsignedSmallInteger('status')->nullable();
            $table->boolean('blocked')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['support_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_session_actions');
        Schema::dropIfExists('support_sessions');
    }
};
