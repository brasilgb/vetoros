<?php

namespace App\Support;

/**
 * Regras para URLs públicas (webhooks, links enviados por e-mail). HTTPS é exigido pelo
 * host, não por APP_ENV: só endereços locais de desenvolvimento podem usar HTTP.
 */
final class PublicUrl
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    private const LOCAL_SUFFIXES = ['.localhost', '.test'];

    public static function isLocalHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function isSecureOrLocal(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://') || self::isLocalHost($url);
    }
}
