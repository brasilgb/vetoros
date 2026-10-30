<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Erro funcional de integração com o WhatsApp (via WAHA).
 *
 * A mensagem desta exceção é sempre segura para exibir ao usuário final
 * (nunca contém detalhes técnicos da requisição HTTP subjacente).
 */
class WhatsAppException extends RuntimeException
{
    public static function notConnected(): self
    {
        return new self('O WhatsApp da empresa está desconectado.');
    }

    public static function unavailable(): self
    {
        return new self('Não foi possível enviar a mensagem. Tente novamente.');
    }

    public static function invalidPhone(): self
    {
        return new self('Confira o número de WhatsApp cadastrado para este cliente.');
    }

    public static function notOnWhatsapp(): self
    {
        return new self('Não foi possível confirmar uma conta WhatsApp neste número.');
    }

    public static function sendFailed(): self
    {
        return new self('Não foi possível enviar a mensagem. Tente novamente.');
    }
}
