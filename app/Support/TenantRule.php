<?php

namespace App\Support;

use Illuminate\Validation\Rules\Exists;

/**
 * Regras de validação presas à empresa atual: um ID só é aceito se o registro for da
 * empresa do usuário (ou da empresa do Modo Suporte). Substitui `exists:tabela,id`, que
 * aceitava IDs de qualquer empresa.
 */
final class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return (new Exists($table, $column))->where('tenant_id', resolveCurrentTenantId());
    }
}
