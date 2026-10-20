<?php

namespace App\Support;

/**
 * Liga, durante o atendimento de uma requisição HTTP, a trava de escrita por empresa do
 * App\Tenantable: registros criados ficam sempre na empresa do contexto e não podem ser
 * movidos para outra. Jobs, comandos e webhooks (sem requisição de usuário) seguem gravando
 * o tenant_id explícito, como antes.
 */
final class TenantWriteGuard
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function enforce(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
