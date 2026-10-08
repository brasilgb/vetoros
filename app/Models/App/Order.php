<?php

namespace App\Models\App;

use App\Models\User;
use App\Support\OrderStatus;
use App\Tenantable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Order extends Model
{
    use HasFactory, Tenantable;

    public const TYPE_EQUIPMENT = 'equipment';

    public const TYPE_EXTERNAL_SERVICE = 'external_service';

    protected $guarded = ['allparts'];

    protected $hidden = ['public_access_key', 'public_access_key_hash'];

    protected $casts = [
        'delivery_date' => 'datetime',
        'totals_calculated_at' => 'datetime',
        'warranty_expires_at' => 'datetime',
        'is_warranty_return' => 'boolean',
        'fiscal_issued_at' => 'datetime',
        'customer_notification_acknowledged_at' => 'datetime',
        'customer_pickup_acknowledged_at' => 'datetime',
        'customer_feedback_submitted_at' => 'datetime',
        'customer_feedback_reminder_sent_at' => 'datetime',
        'customer_feedback_request_expired_at' => 'datetime',
        'customer_feedback_recovery_updated_at' => 'datetime',
        'budget_follow_up_paused_at' => 'datetime',
        'payment_follow_up_paused_at' => 'datetime',
        'budget_follow_up_snoozed_until' => 'datetime',
        'payment_follow_up_snoozed_until' => 'datetime',
        'budget_follow_up_response_at' => 'datetime',
        'payment_follow_up_response_at' => 'datetime',
        'technician_checklist_items' => 'array',
        'technician_checklist_completed_at' => 'datetime',
        'customer_signature_captured_at' => 'datetime',
    ];

    /**
     * Chave de acesso público, criptografada com a APP_KEY (mesmo formato do cast "encrypted").
     * Valor gravado com outra APP_KEY (banco vindo de outro ambiente ou chave regenerada) não é
     * legível: devolve null em vez de derrubar a listagem/tela da OS. O acesso público continua
     * validado por public_access_key_hash, que não depende da APP_KEY.
     */
    protected function publicAccessKey(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (DecryptException) {
                    Log::warning('Chave de acesso público da OS ilegível com a APP_KEY atual.', ['order_id' => $this->getKey()]);

                    return null;
                }
            },
            set: fn (?string $value): ?string => $value === null || $value === '' ? $value : Crypt::encryptString($value),
        );
    }

    /**
     * Desde quando o orçamento está com o cliente aguardando resposta, pela melhor evidência:
     * 1. sent_at da versão enviada e ainda sem resposta (order_budgets);
     * 2. entrada registrada no status "Orçamento Gerado" (order_status_history);
     * 3. orders.updated_at — referência anterior a esta etapa, usada só sem nenhuma evidência
     *    (OS legada), para não mudar o comportamento dessas OS.
     */
    public static function budgetPendingSinceSql(): string
    {
        return '(COALESCE('
            ."(SELECT ob.sent_at FROM order_budgets ob WHERE ob.order_id = orders.id AND ob.status = 'sent' AND ob.sent_at IS NOT NULL ORDER BY ob.version DESC LIMIT 1), "
            .'(SELECT MAX(h.created_at) FROM order_status_history h WHERE h.order_id = orders.id AND h.status = '.OrderStatus::BUDGET_GENERATED.'), '
            .'orders.updated_at))';
    }

    public function scopeWhereBudgetPendingBefore(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->whereRaw(self::budgetPendingSinceSql().' <= ?', [Carbon::parse($moment)->toDateTimeString()]);
    }

    public function scopeWithBudgetPendingSince(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }

        return $query->selectRaw(self::budgetPendingSinceSql().' as budget_pending_since');
    }

    public function budgetPendingSince(): ?Carbon
    {
        $value = $this->getAttribute('budget_pending_since')
            ?? self::query()->withoutGlobalScopes()->whereKey($this->getKey())
                ->selectRaw(self::budgetPendingSinceSql().' as budget_pending_since')
                ->value('budget_pending_since');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Total da OS. A coluna se chama service_cost por motivo histórico, mas guarda o total
     * calculado no servidor por OrderTotalsService.
     */
    public function total(): float
    {
        return round((float) ($this->service_cost ?? 0), 2);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function customerEquipment(): BelongsTo
    {
        return $this->belongsTo(CustomerEquipment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function orderParts(): BelongsToMany
    {
        return $this->belongsToMany(Part::class, 'order_parts')
            ->using(OrderPart::class)
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(OrderBudget::class)->orderBy('version');
    }

    public function approvedBudget(): BelongsTo
    {
        return $this->belongsTo(OrderBudget::class, 'approved_budget_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OrderMessage::class)->latest('created_at')->latest('id');
    }

    public function technicianAssignments(): HasMany
    {
        return $this->hasMany(OrderTechnicianAssignment::class)->orderBy('assigned_at')->orderBy('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class)->latest('created_at');
    }

    public function orderPayments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->latest('paid_at');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class, 'documentable_id')
            ->where('documentable_type', self::class)
            ->latest();
    }

    public function warrantySourceOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'warranty_source_order_id');
    }

    public function budgetFollowUpAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'budget_follow_up_assigned_to');
    }

    public function paymentFollowUpAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_follow_up_assigned_to');
    }

    public function customerFeedbackRecoveryAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_feedback_recovery_assigned_to');
    }
}
