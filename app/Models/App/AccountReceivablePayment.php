<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recebimento (baixa) de uma conta a receber; estornos ficam registrados, nunca apagados. */
class AccountReceivablePayment extends Model
{
    use Tenantable;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_INTEGRATION = 'integration';

    protected $fillable = [
        'tenant_id',
        'account_receivable_id',
        'amount',
        'paid_at',
        'payment_method',
        'source',
        'external_reference',
        'received_by',
        'cash_session_movement_id',
        'notes',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class, 'account_receivable_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
