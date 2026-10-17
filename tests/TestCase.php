<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Recusa rodar fora do banco de teste. Com a configuração em cache
     * (`php artisan optimize`/`config:cache`), o `phpunit.xml` é ignorado e o
     * RefreshDatabase executaria `migrate:fresh` no banco do `.env` — já apagou
     * o MySQL local uma vez. A checagem roda antes de qualquer trait de banco.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        $problem = match (true) {
            $app->configurationIsCached() => 'a configuração está em cache ('.$app->getCachedConfigPath().')',
            ! $app->environment('testing') => "o ambiente é \"{$app->environment()}\", não \"testing\"",
            $connection !== 'sqlite' || $database !== ':memory:' => "o banco é {$connection}:{$database}, não sqlite::memory:",
            default => null,
        };

        if ($problem !== null) {
            throw new RuntimeException("Testes abortados para proteger o banco: {$problem}. Rode `php artisan config:clear` e confira o phpunit.xml.");
        }

        return $app;
    }
}
