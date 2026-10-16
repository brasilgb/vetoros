<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tentativa de envio da fatura (`invoice`) ou da comunicação fiscal (`fiscal`) de uma cobrança. */
class TenantSubscriptionChargeDelivery extends Model
{
    public const KIND_INVOICE = 'invoice';

    public const KIND_FISCAL = 'fiscal';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
