<?php

namespace App\Support;

/**
 * Chaves que nunca podem ser gravadas em trilhas de auditoria/eventos
 * (order_events.metadata, operational_audits.data).
 */
final class SensitiveData
{
    public const KEYS = [
        'password',
        'public_access_key',
        'public_access_key_hash',
        'tracking_token',
        'token',
        'customer_signature',
    ];

    /**
     * Remove as chaves sensíveis em qualquer nível do array.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function strip(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::strip($value) : $value;
        }

        return $clean;
    }
}
