<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('schedules', 'send_to_technician')) {
                $table->boolean('send_to_technician')->default(false)->after('responsible_technician');
            }

            if (! Schema::hasColumn('schedules', 'check_in_at')) {
                $table->timestamp('check_in_at')->nullable()->after('send_to_technician');
            }

            if (! Schema::hasColumn('schedules', 'check_in_latitude')) {
                $table->decimal('check_in_latitude', 10, 7)->nullable()->after('check_in_at');
            }

            if (! Schema::hasColumn('schedules', 'check_in_longitude')) {
                $table->decimal('check_in_longitude', 10, 7)->nullable()->after('check_in_latitude');
            }

            if (! Schema::hasColumn('schedules', 'check_in_observations')) {
                $table->text('check_in_observations')->nullable()->after('check_in_longitude');
            }

            if (! Schema::hasColumn('schedules', 'check_out_at')) {
                $table->timestamp('check_out_at')->nullable()->after('check_in_observations');
            }

            if (! Schema::hasColumn('schedules', 'check_out_latitude')) {
                $table->decimal('check_out_latitude', 10, 7)->nullable()->after('check_out_at');
            }

            if (! Schema::hasColumn('schedules', 'check_out_longitude')) {
                $table->decimal('check_out_longitude', 10, 7)->nullable()->after('check_out_latitude');
            }

            if (! Schema::hasColumn('schedules', 'check_out_observations')) {
                $table->text('check_out_observations')->nullable()->after('check_out_longitude');
            }
        });
    }

    public function down(): void
    {
        // As colunas pertencem a 2026_06_01_120000_add_order_and_send_to_technician_to_schedules_table,
        // que as remove no próprio down. Esta migration só garante que existam;
        // removê-las aqui fazia o rollback daquela falhar (coluna inexistente).
    }
};
