<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Período em que um técnico foi responsável pela OS. A única alteração permitida
 * é o fechamento (unassigned_*) de uma atribuição ainda aberta.
 */
class OrderTechnicianAssignment extends Model
{
    use Tenantable;

    private const CLOSING_FIELDS = ['unassigned_at', 'unassigned_by', 'unassigned_by_type', 'updated_at'];

    protected $guarded = ['id'];

    protected $casts = [
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            OrderEvent::assertSameTenantAsOrder($assignment->tenant_id, $assignment->order_id);
        });

        static::updating(function (self $assignment): void {
            $changed = array_keys($assignment->getDirty());

            if ($assignment->getOriginal('unassigned_at') !== null || array_diff($changed, self::CLOSING_FIELDS) !== []) {
                throw new LogicException('Atribuições de técnico só podem ser encerradas, nunca alteradas.');
            }
        });

        static::deleting(fn () => throw new LogicException('Atribuições de técnico são imutáveis.'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('unassigned_at');
    }

    /**
     * Atribuição vigente em um instante: assigned_at <= instante < unassigned_at.
     */
    public function scopeActiveAt(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('assigned_at', '<=', $moment)
            ->where(fn (Builder $q) => $q->whereNull('unassigned_at')->orWhere('unassigned_at', '>', $moment));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
