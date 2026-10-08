<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Versão do orçamento da OS. Só OrderBudgetService cria e altera versões.
 *
 * Rascunho (draft) pode ser atualizado no lugar até ser enviado. A partir do envio,
 * conteúdo financeiro e itens são imutáveis: qualquer mudança gera nova versão.
 */
class OrderBudget extends Model
{
    use Tenantable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUPERSEDED = 'superseded';

    /** Versão criada na implantação a partir do orçamento já existente (histórico desconhecido). */
    public const STATUS_LEGACY = 'legacy';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_SUPERSEDED,
        self::STATUS_LEGACY,
    ];

    /** Campos congelados depois do envio. */
    public const FROZEN_FIELDS = [
        'version',
        'description',
        'quoted_amount',
        'budget_link',
        'subtotal_services',
        'subtotal_parts',
        'manual_parts_value',
        'discount_amount',
        'surcharge_amount',
        'total_amount',
        'valid_until',
        'sent_at',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'is_legacy' => 'boolean',
        'quoted_amount' => 'decimal:2',
        'subtotal_services' => 'decimal:2',
        'subtotal_parts' => 'decimal:2',
        'manual_parts_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'surcharge_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'valid_until' => 'date:Y-m-d',
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expired_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    protected $appends = ['effective_status'];

    protected static function booted(): void
    {
        static::creating(function (self $budget): void {
            OrderEvent::assertSameTenantAsOrder($budget->tenant_id, $budget->order_id);
        });

        static::updating(function (self $budget): void {
            if ($budget->getOriginal('status') === self::STATUS_DRAFT) {
                return;
            }

            if (array_intersect(array_keys($budget->getDirty()), self::FROZEN_FIELDS) !== []) {
                throw new LogicException('Versão de orçamento já enviada não pode ter conteúdo alterado; crie nova versão.');
            }
        });

        static::deleting(fn () => throw new LogicException('Versões de orçamento são históricas e não podem ser apagadas.'));
    }

    /**
     * Regra única de expiração: versão enviada com validade vencida (após o fim do dia
     * de valid_until) está expirada, mesmo antes de o status ser persistido.
     */
    public function isExpired(?Carbon $at = null): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        return $this->status === self::STATUS_SENT
            && $this->valid_until !== null
            && ($at ?? now())->greaterThan($this->expiresAt());
    }

    public function expiresAt(): ?Carbon
    {
        return $this->valid_until ? Carbon::parse($this->valid_until)->endOfDay() : null;
    }

    public function getEffectiveStatusAttribute(): string
    {
        return $this->isExpired() ? self::STATUS_EXPIRED : (string) $this->status;
    }

    /**
     * Versões efetivamente vencidas (persistidas ou derivadas pela validade).
     */
    public function scopeEffectivelyExpired(Builder $query, ?Carbon $at = null): Builder
    {
        $today = ($at ?? now())->toDateString();

        return $query->where(fn (Builder $q) => $q->where('status', self::STATUS_EXPIRED)
            ->orWhere(fn (Builder $sent) => $sent->where('status', self::STATUS_SENT)
                ->whereNotNull('valid_until')
                ->where('valid_until', '<', $today)));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderBudgetItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OrderMessage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
