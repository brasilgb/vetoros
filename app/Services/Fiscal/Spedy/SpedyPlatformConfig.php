<?php

namespace App\Services\Fiscal\Spedy;

use App\Models\Admin\SpedyPlatformSetting;
use Illuminate\Database\QueryException;

/**
 * Fonte única das credenciais centrais da Spedy.
 *
 * Precedência (por campo): valor gravado pelo RootAdmin em
 * `spedy_platform_settings` (criptografado) > variável de ambiente
 * (`services.spedy.*`). A variável serve de bootstrap; depois de gravada no
 * painel, a chave do banco prevalece. Nenhum valor daqui vai para o frontend.
 */
class SpedyPlatformConfig
{
    public static function ownerApiKey(): ?string
    {
        return self::stored('owner_api_key') ?? (filled(config('services.spedy.owner_api_key')) ? (string) config('services.spedy.owner_api_key') : null);
    }

    public static function webhookSecret(): ?string
    {
        return self::stored('webhook_secret') ?? (filled(config('services.spedy.webhook_secret')) ? (string) config('services.spedy.webhook_secret') : null);
    }

    public static function environment(): string
    {
        $value = self::stored('environment') ?? config('services.spedy.environment');

        return $value === 'production' ? 'production' : 'sandbox';
    }

    /** De onde vem cada valor, para exibir no painel sem revelar o conteúdo. */
    public static function source(string $field): ?string
    {
        $env = ['owner_api_key' => 'owner_api_key', 'webhook_secret' => 'webhook_secret', 'environment' => 'environment'][$field] ?? null;

        return match (true) {
            self::stored($field) !== null => 'database',
            $env !== null && filled(config("services.spedy.{$env}")) => 'environment',
            default => null,
        };
    }

    private static function stored(string $field): ?string
    {
        try {
            $value = SpedyPlatformSetting::query()->orderBy('id')->toBase()->value($field);
        } catch (QueryException) {
            // Antes de rodar as migrations: só a variável de ambiente vale.
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return in_array($field, ['owner_api_key', 'webhook_secret'], true)
            ? decrypt($value, false)
            : (string) $value;
    }
}
