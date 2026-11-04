<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_TEMPLATE = 'Bom dia, {{ cliente }}! Agradecemos seu retorno e a aprovação do orçamento. Assim que seu equipamento estiver pronto, avisaremos por aqui. Obrigado pela confiança!';

    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->text('budgetapproved')->nullable()->after('technicianschedule');
        });

        DB::table('whatsapp_messages')
            ->where(fn ($query) => $query->whereNull('budgetapproved')->orWhere('budgetapproved', ''))
            ->update(['budgetapproved' => self::DEFAULT_TEMPLATE]);
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('budgetapproved');
        });
    }
};
