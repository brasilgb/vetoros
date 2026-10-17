<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Solicitação de nota fiscal avulsa (VETOR-FISCAL-AVULSA-01): origem própria da nota, sem OS,
 * venda ou contrato. `items` guarda os produtos (NF-e/NFC-e: peça, quantidade e preço) e
 * `tax_data` a tributação da NFS-e efetivamente aplicada.
 */
class StandaloneInvoice extends Model
{
    use Tenantable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCARDED = 'discarded';

    public const FINANCE_NONE = 'none';

    /** Conta a receber com vencimento, sem baixa. */
    public const FINANCE_RECEIVABLE = 'receivable';

    /** Conta a receber já quitada por um recebimento registrado no caixa. */
    public const FINANCE_RECEIVED = 'received';

    protected $fillable = [
        'tenant_id',
        'request_key',
        'type',
        'status',
        'customer_id',
        'description',
        'items',
        'tax_data',
        'total_amount',
        'payment_method',
        'finance_mode',
        'account_receivable_id',
        'created_by',
        'discarded_at',
        'discarded_by',
    ];

    protected $casts = [
        'items' => 'array',
        'tax_data' => 'array',
        'total_amount' => 'decimal:2',
        'discarded_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class, 'account_receivable_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fiscalDocuments(): MorphMany
    {
        return $this->morphMany(FiscalDocument::class, 'documentable');
    }

    public function isDiscarded(): bool
    {
        return $this->status === self::STATUS_DISCARDED;
    }
}
