<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('commercial_whatsapp', 15)->nullable();
            $table->string('commercial_whatsapp_name', 100)->nullable();
            $table->string('commercial_whatsapp_message', 500)->nullable();
            $table->boolean('commercial_whatsapp_public')->default(true);
            $table->boolean('commercial_whatsapp_internal')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'commercial_whatsapp',
                'commercial_whatsapp_name',
                'commercial_whatsapp_message',
                'commercial_whatsapp_public',
                'commercial_whatsapp_internal',
            ]);
        });
    }
};
