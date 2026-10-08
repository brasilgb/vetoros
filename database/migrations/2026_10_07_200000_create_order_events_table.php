<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trilha operacional imutável da OS (VETOR-INTEL-01). Sem backfill: registros
        // anteriores à implantação continuam só em order_status_history/operational_audits.
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->unsignedTinyInteger('from_status')->nullable();
            $table->unsignedTinyInteger('to_status')->nullable();
            $table->string('transition_kind', 20)->nullable();
            $table->string('actor_type', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('previous_technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'order_id', 'occurred_at'], 'order_events_tenant_order_occurred_idx');
            $table->index(['tenant_id', 'event_type', 'occurred_at'], 'order_events_tenant_type_occurred_idx');
            $table->index(['tenant_id', 'to_status', 'occurred_at'], 'order_events_tenant_to_status_occurred_idx');
        });

        Schema::create('order_technician_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->string('assigned_by_type', 20);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->string('unassigned_by_type', 20)->nullable();
            $table->foreignId('unassigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unassigned_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id', 'assigned_at'], 'order_tech_assign_tenant_order_idx');
            $table->index(['tenant_id', 'technician_id', 'assigned_at'], 'order_tech_assign_tenant_tech_idx');
            $table->index(['order_id', 'unassigned_at'], 'order_tech_assign_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_technician_assignments');
        Schema::dropIfExists('order_events');
    }
};
