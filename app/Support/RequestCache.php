<?php

namespace App\Support;

use Closure;

/**
 * Memória de leituras repetidas dentro de uma mesma requisição ou job.
 *
 * Registrada como `scoped`: o container a descarta ao fim de cada requisição e o worker
 * da fila a cada job, então nenhum valor sobrevive de um atendimento para o outro.
 * Quem grava o dado chama forget() para a própria requisição não ler valor antigo.
 */
class RequestCache
{
    /** @var array<string, mixed> */
    private array $items = [];

    public static function remember(string $key, Closure $callback): mixed
    {
        $cache = app(self::class);

        if (! array_key_exists($key, $cache->items)) {
            $cache->items[$key] = $callback();
        }

        return $cache->items[$key];
    }

    public static function forget(string $prefix): void
    {
        $cache = app(self::class);

        foreach (array_keys($cache->items) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($cache->items[$key]);
            }
        }
    }
}
