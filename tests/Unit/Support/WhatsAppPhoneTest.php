<?php

namespace Tests\Unit\Support;

use App\Support\WhatsAppPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppPhoneTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            'celular com máscara' => ['(51) 99999-9999'],
            'celular sem máscara' => ['51999999999'],
            'com DDI e máscara' => ['+55 (51) 99999-9999'],
            'com DDI sem máscara' => ['5551999999999'],
            'celular antigo de 8 dígitos' => ['51 99999999'],
            'DDD 55 sem DDI' => ['55999999999'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_accepts_brazilian_mobile_numbers(string $number): void
    {
        $this->assertTrue(WhatsAppPhone::isValid($number));
    }

    public static function invalidNumbers(): array
    {
        return [
            'vazio' => [''],
            'nulo' => [null],
            'curto' => ['9999'],
            'fixo com DDD' => ['5133333333'],
            'DDD inexistente' => ['00999999999'],
            'celular sem o 9 inicial' => ['51899999999'],
            'letras' => ['abc'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_rejects_numbers_without_whatsapp_format(?string $number): void
    {
        $this->assertFalse(WhatsAppPhone::isValid($number));
    }

    public function test_normalize_keeps_only_digits_with_country_code(): void
    {
        $this->assertSame('5551999999999', WhatsAppPhone::normalize('(51) 99999-9999'));
        $this->assertSame('5551999999999', WhatsAppPhone::normalize('+55 51 99999-9999'));
        $this->assertSame('5555999999999', WhatsAppPhone::normalize('55 99999-9999'));
    }
}
