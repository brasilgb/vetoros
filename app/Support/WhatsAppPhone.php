<?php

namespace App\Support;

class WhatsAppPhone
{
    /**
     * Espelha `normalizeWhatsappPhone()` e `isValidWhatsappPhone()` de resources/js/Utils/mask.tsx.
     *
     * Aceita números nacionais (10 ou 11 dígitos, com DDD) e internacionais (12 ou 13 dígitos,
     * começando com 55). Números nacionais recebem o DDI 55; os demais são devolvidos apenas
     * com dígitos, para que isValid() os rejeite. O valor salvo no cadastro não é alterado.
     */
    public static function normalize(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return '55'.$digits;
        }

        return $digits;
    }

    /**
     * Confere DDD (11 a 99) e formato de celular: 9 dígitos começando com 9, ou o formato antigo
     * de 8 dígitos começando com 6 a 9. Fixos (começando com 2 a 5) não têm WhatsApp e são rejeitados.
     */
    public static function isValid(?string $value): bool
    {
        $digits = self::normalize($value);

        if (! str_starts_with($digits, '55')) {
            return false;
        }

        return preg_match('/^[1-9][1-9](9\d{8}|[6-9]\d{7})$/', substr($digits, 2)) === 1;
    }
}
