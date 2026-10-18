<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->boolean('web_chat_enabled')->default(true);
            $table->string('web_chat_welcome_message', 500)->nullable();
            $table->string('web_chat_business_hours', 190)->nullable();
            $table->string('web_chat_offline_message', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn(['web_chat_enabled', 'web_chat_welcome_message', 'web_chat_business_hours', 'web_chat_offline_message']);
        });
    }
};
