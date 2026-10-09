<?php

namespace App\Services\Payments;

use App\Models\App\AccountReceivable;

/**
 * Ponto de extensão para meios de recebimento automático do tenant (Pix, boleto, cartão,
 * gateways). Hoje nenhum está integrado aos contratos de manutenção; quando houver, a
 * implementação diz se a cobrança é recebida automaticamente e o recebimento manual
 * ("Pagar") fica bloqueado no backend e na tela.
 *
 * Eventos do provedor devem entrar por AccountReceivablePaymentService::register() com
 * origem `integration` e a referência do provedor (idempotente), depois de autenticados.
 */
interface ReceivablePaymentChannel
{
    /** Nome do meio automático ativo e aplicável a esta cobrança, ou null se não houver. */
    public function automaticChannelFor(AccountReceivable $receivable): ?string;
}
