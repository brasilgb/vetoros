<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Proteção dos bancos reais (VETOR-STABILIZE-01). Comandos que apagam dados (`db:wipe`,
 * `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`) só rodam:
 *
 * - nos testes, com o banco isolado em memória (sqlite `:memory:`); ou
 * - fora de produção, com liberação explícita `DB_ALLOW_DESTRUCTIVE_COMMANDS=true` informada
 *   na própria linha de comando, para uma restauração consciente.
 *
 * Em produção nunca são liberados. Um cache de configuração apontando para o MySQL faz o
 * ambiente deixar de ser "testing" e a trava continua valendo, mesmo dentro dos testes.
 */
class DatabaseSafety
{
    public static function destructiveCommandsAllowed(Application $app): bool
    {
        if ($app->environment('production')) {
            return false;
        }

        if ($app->environment('testing') && self::usesIsolatedTestDatabase($app)) {
            return true;
        }

        return (bool) $app['config']->get('database.allow_destructive_commands', false);
    }

    public static function usesIsolatedTestDatabase(Application $app): bool
    {
        $connection = (string) $app['config']->get('database.default');

        return $connection === 'sqlite'
            && $app['config']->get("database.connections.{$connection}.database") === ':memory:';
    }
}
