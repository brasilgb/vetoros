<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VETOR-ROOT-FISCAL-02 (Fase B): os dados da empresa tinham 50 caracteres, menos que a
 * razão social aceita pela Receita (150). A tela de Empresa sincroniza endereço com
 * `tenants`, que é o tomador da NFS-e do SaaS, então as duas tabelas crescem juntas.
 *
 * Aditiva: só aumenta o tamanho de colunas `varchar`, sem alterar dados. O rollback
 * volta aos 50 caracteres apenas se nenhum valor gravado passar disso.
 */
return new class extends Migration
{
    /** @var array<string, array<string, int>> */
    private const WIDENED = [
        'companies' => [
            'shortname' => 150,
            'companyname' => 150,
            'street' => 150,
            'district' => 100,
            'city' => 100,
            'complement' => 100,
            'site' => 150,
            'email' => 150,
        ],
        'tenants' => [
            'street' => 150,
            'district' => 100,
            'city' => 100,
            'complement' => 100,
        ],
    ];

    private const ORIGINAL_LENGTH = 50;

    public function up(): void
    {
        foreach (self::WIDENED as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column => $length) {
                    $blueprint->string($column, $length)->nullable()->change();
                }
            });
        }
    }

    public function down(): void
    {
        $length = DB::getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        foreach (self::WIDENED as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $longer = DB::table($table)->whereRaw($length.'('.$column.') > ?', [self::ORIGINAL_LENGTH])->count();

                if ($longer > 0) {
                    throw new RuntimeException(sprintf(
                        'Rollback interrompido: %d registro(s) em %s.%s passam de %d caracteres. Ajuste os dados antes de reduzir a coluna.',
                        $longer, $table, $column, self::ORIGINAL_LENGTH
                    ));
                }
            }
        }

        foreach (self::WIDENED as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach (array_keys($columns) as $column) {
                    $blueprint->string($column, self::ORIGINAL_LENGTH)->nullable()->change();
                }
            });
        }
    }
};
