<?php

namespace App\Models\App;

use App\Tenantable;
use Illuminate\Database\Eloquent\Model;

/**
 * Taxa cobrada pela operadora em um meio de pagamento (por tenant). É aplicada e
 * congelada em cada pagamento no momento do registro; mudar a configuração depois não
 * altera pagamentos anteriores.
 */
class PaymentFeeSetting extends Model
{
    use Tenantable;

    /** Meios aceitos no registro de pagamento da OS (OrderController::storePayment). */
    public const METHODS = ['pix', 'cartao', 'dinheiro', 'transferencia', 'boleto'];

    protected $guarded = ['id'];

    protected $casts = [
        'fee_percentage' => 'decimal:3',
        'fee_fixed_amount' => 'decimal:2',
    ];
}
