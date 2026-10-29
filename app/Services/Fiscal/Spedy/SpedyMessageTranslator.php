<?php

namespace App\Services\Fiscal\Spedy;

/**
 * Traduz para o português as mensagens em inglês devolvidas pela Spedy (campos de
 * validação, erros HTTP e processingDetail). Mensagens que já vêm em português, como as
 * rejeições do município e da Receita, passam sem alteração: traduzir por tentativa
 * seria pior do que mostrar o texto original.
 */
class SpedyMessageTranslator
{
    /** Nomes de campo da API da Spedy (notação de ponto) para rótulos legíveis. */
    private const FIELDS = [
        'receiver.federalTaxNumber' => 'CPF/CNPJ do tomador',
        'receiver.name' => 'nome do tomador',
        'receiver.email' => 'e-mail do tomador',
        'receiver.address' => 'endereço do tomador',
        'receiver.city' => 'cidade do tomador',
        'company.federalTaxNumber' => 'CNPJ do emitente',
        'servicesAmount' => 'valor do serviço',
        'amount' => 'valor',
        'description' => 'descrição',
        'cityServiceCode' => 'código do serviço municipal',
        'federalServiceCode' => 'código federal do serviço',
        'serviceCode' => 'código do serviço',
    ];

    /** Frases típicas de validação e de erro HTTP em inglês. */
    private const PHRASES = [
        '/\bis required\b/i' => 'é obrigatório',
        '/\bis invalid\b/i' => 'é inválido',
        '/\bis not valid\b/i' => 'não é válido',
        '/\bunauthorized\b/i' => 'não autorizado',
        '/\bforbidden\b/i' => 'acesso negado',
        '/\bnot found\b/i' => 'não encontrado',
        '/\btoo many requests\b/i' => 'muitas requisições',
        '/\b(?:request )?timed? ?out\b|\btimeout\b/i' => 'tempo limite excedido',
        '/\bservice unavailable\b|\bunavailable\b/i' => 'indisponível',
        '/\binternal server error\b|\binternal error\b/i' => 'erro interno no serviço de emissão',
    ];

    public static function translate(string $message): string
    {
        $message = trim($message);

        if (preg_match('/^([\w.]+):\s*(.+)$/s', $message, $matches) === 1) {
            $label = self::FIELDS[$matches[1]] ?? null;

            if ($label !== null) {
                return $label.': '.self::phrases($matches[2]);
            }

            return $matches[1].': '.self::phrases($matches[2]);
        }

        return self::phrases($message);
    }

    private static function phrases(string $text): string
    {
        return preg_replace(array_keys(self::PHRASES), array_values(self::PHRASES), $text) ?? $text;
    }
}
