<?php

namespace App\Services\Payments;

use App\Models\App\AccountReceivable;

/** Sem integração de pagamento: todas as cobranças são recebidas manualmente. */
class ManualOnlyPaymentChannel implements ReceivablePaymentChannel
{
    public function automaticChannelFor(AccountReceivable $receivable): ?string
    {
        return null;
    }
}
