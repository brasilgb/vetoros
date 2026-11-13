<?php

namespace App\Support;

use App\Models\App\Order;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TenantSequence
{
    /** @var array<class-string<Model>, string> */
    private const INTEGER_INDEXED = [
        Order::class => 'order_number',
    ];

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function next(string $modelClass, string $column, ?int $tenantId = null): int
    {
        if (! is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException("{$modelClass} must be an Eloquent model.");
        }

        $tenantId ??= resolveCurrentTenantId();
        $query = $modelClass::query()->lockForUpdate();

        if (! is_null($tenantId)) {
            $query->where('tenant_id', $tenantId);
        }

        // Coluna inteira com índice único (tenant_id, coluna): o MAX é resolvido pelo índice,
        // sem trazer (e travar) todos os números do tenant. VETOROS-PERFORMANCE-OS-03.
        if (isset(self::INTEGER_INDEXED[$modelClass]) && self::INTEGER_INDEXED[$modelClass] === $column) {
            return ((int) $query->max($column)) + 1;
        }

        // Demais colunas podem ser texto (messages.message_number, parts.part_number): o máximo
        // numérico continua sendo calculado em PHP.
        $max = $query
            ->pluck($column)
            ->map(fn (mixed $value): int => (int) $value)
            ->max();

        return ((int) $max) + 1;
    }
}
