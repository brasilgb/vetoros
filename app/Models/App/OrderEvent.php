<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Evento operacional imutável da OS. Só é criado pelos serviços de domínio
 * (OrderStatusService e OrderTechnicianAssignmentService).
 */
class OrderEvent extends Model
{
    use Tenantable;

    public const TYPE_ORDER_CREATED = 'order_created';

    public const TYPE_STATUS_CHANGED = 'status_changed';

    public const TYPE_ORDER_REOPENED = 'order_reopened';

    public const TYPE_TECHNICIAN_ASSIGNED = 'technician_assigned';

    public const TYPE_TECHNICIAN_UNASSIGNED = 'technician_unassigned';

    public const TYPE_TECHNICIAN_REASSIGNED = 'technician_reassigned';

    public const TYPE_CUSTOMER_NOTIFICATION_ACKNOWLEDGED = 'customer_notification_acknowledged';

    public const TYPE_CUSTOMER_PICKUP_ACKNOWLEDGED = 'customer_pickup_acknowledged';

    public const TYPE_DELIVERY_FORECAST_CHANGED = 'delivery_forecast_changed';

    public const TYPE_DELIVERY_DATE_CHANGED = 'delivery_date_changed';

    public const TYPE_BUDGET_CREATED = 'budget_created';

    public const TYPE_BUDGET_SENT = 'budget_sent';

    public const TYPE_BUDGET_APPROVED = 'budget_approved';

    public const TYPE_BUDGET_REJECTED = 'budget_rejected';

    public const TYPE_BUDGET_EXPIRED = 'budget_expired';

    public const TYPE_BUDGET_SUPERSEDED = 'budget_superseded';

    public const TYPE_MESSAGE_SENT = 'message_sent';

    public const TYPE_MESSAGE_FAILED = 'message_failed';

    /**
     * Eventos que alteram (ou definem) o status atual da OS: base da linha do tempo de status.
     */
    public const STATUS_TIMELINE_TYPES = [
        self::TYPE_ORDER_CREATED,
        self::TYPE_STATUS_CHANGED,
        self::TYPE_ORDER_REOPENED,
    ];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'from_status' => 'integer',
        'to_status' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            OrderEvent::assertSameTenantAsOrder($event->tenant_id, $event->order_id);
        });

        static::updating(fn () => throw new LogicException('Eventos da OS são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Eventos da OS são imutáveis.'));
    }

    /**
     * Um registro da trilha nunca pode pertencer a tenant diferente da OS.
     */
    public static function assertSameTenantAsOrder(mixed $tenantId, mixed $orderId): void
    {
        if (empty($tenantId) || empty($orderId)) {
            throw new LogicException('Registro da trilha da OS exige tenant_id e order_id.');
        }

        $orderTenantId = DB::table('orders')->where('id', $orderId)->value('tenant_id');

        if ((int) $orderTenantId !== (int) $tenantId) {
            throw new LogicException('O tenant do registro difere do tenant da OS.');
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function previousTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_technician_id');
    }
}
