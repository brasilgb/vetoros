<?php

namespace App\Services\Fiscal\Spedy;

use RuntimeException;

/**
 * Falha de comunicação ou recusa da API da Spedy. A mensagem é segura para
 * exibir ao usuário: nunca contém chaves, senhas ou o corpo bruto da requisição.
 */
class SpedyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status ?? 0);
    }

    /** Erro de validação/regra de negócio: reenviar sem corrigir repete o erro. */
    public function isDefinitive(): bool
    {
        return $this->status === 400;
    }

    /** Timeout, 429 ou 5xx: a operação pode ter sido processada; reconciliar antes de agir. */
    public function isTransient(): bool
    {
        return $this->status === null || $this->status === 429 || $this->status >= 500;
    }
}
