<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Dados insuficientes para emitir: detectado antes de chamar o provedor,
 * para o usuário corrigir o cadastro em vez de receber uma rejeição da SEFAZ.
 */
class FiscalValidationException extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Corrija os dados para emitir a nota: '.implode(' ', $problems));
    }
}
