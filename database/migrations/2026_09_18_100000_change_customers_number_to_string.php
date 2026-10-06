<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * O campo "número" do endereço é validado (CustomerRequest) como texto livre
     * (aceita "S/N", "123A", etc. — comum em endereços brasileiros), mas a coluna
     * no banco era `integer`. Qualquer valor não numérico quebrava o INSERT/UPDATE
     * com um erro de SQL não tratado (500 genérico ao salvar cliente).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('customers', fn (Blueprint $table) => $table->string('number', 20)->nullable()->change());

            return;
        }

        // Um ALTER TABLE reconstrói a tabela inteira, então o MySQL revalida TODAS
        // as colunas — inclusive as que não estamos alterando. Bancos antigos podem
        // ter "birth" = '0000-00-00' (placeholder legado, nunca foi uma data válida),
        // o que bloqueia até este ALTER que só mexe em "number". Zera esse lixo antes.
        DB::statement("UPDATE customers SET birth = NULL WHERE birth = '0000-00-00'");

        DB::statement('ALTER TABLE customers MODIFY number VARCHAR(20) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('customers', fn (Blueprint $table) => $table->integer('number')->nullable()->change());

            return;
        }

        DB::statement('ALTER TABLE customers MODIFY number INT NULL');
    }
};
