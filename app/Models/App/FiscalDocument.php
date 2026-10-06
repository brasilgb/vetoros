<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FiscalDocument extends Model
{
    use HasFactory, Tenantable;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_CONTINGENCY = 'contingency';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DENIED = 'denied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    /** Estados em que a nota ainda pode mudar do lado da Spedy. */
    public const PENDING_STATUSES = [self::STATUS_PROCESSING, self::STATUS_CONTINGENCY];

    /** Estados que impedem uma nova emissão para o mesmo registro. */
    public const BLOCKING_STATUSES = [self::STATUS_PROCESSING, self::STATUS_CONTINGENCY, self::STATUS_AUTHORIZED];

    protected $guarded = ['id'];

    protected $hidden = ['request_payload', 'response_payload'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isNative(): bool
    {
        return $this->provider === FiscalSetting::PROVIDER_SPEDY;
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING_STATUSES, true);
    }
}
