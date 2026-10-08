<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comunicação com o cliente ligada à OS (e à versão do orçamento, quando for o caso).
 * O corpo da mensagem não é armazenado: só destinatário, modelo e situação de entrega.
 */
class OrderMessage extends Model
{
    use Tenantable;

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_EMAIL = 'email';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    /** Ordem de progressão: status nunca regride. */
    public const STATUS_RANK = [
        self::STATUS_SENT => 1,
        self::STATUS_DELIVERED => 2,
        self::STATUS_READ => 3,
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            OrderEvent::assertSameTenantAsOrder($message->tenant_id, $message->order_id);

            if ($message->order_budget_id) {
                $budgetOrderId = OrderBudget::withoutGlobalScopes()->whereKey($message->order_budget_id)->value('order_id');

                if ((int) $budgetOrderId !== (int) $message->order_id) {
                    throw new \LogicException('O orçamento informado não pertence a esta OS.');
                }
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(OrderBudget::class, 'order_budget_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
