<?php

namespace App\Models\App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    /** Taxa informada por quem registrou o pagamento (inclusive zero). */
    public const FEE_SOURCE_MANUAL = 'manual';

    /** Taxa calculada pela configuração do meio de pagamento (PaymentFeeSetting). */
    public const FEE_SOURCE_CONFIG = 'config';

    // fee_source null = taxa desconhecida (pagamento legado ou sem informação/configuração).

    use HasFactory;

    protected $fillable = [
        'order_id',
        'cash_session_id',
        'amount',
        'fee_amount',
        'fee_source',
        'fee_percentage',
        'fee_fixed_amount',
        'net_amount',
        'payment_method',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'fee_percentage' => 'decimal:3',
        'fee_fixed_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}
