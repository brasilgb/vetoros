<?php

namespace App\Models\App;

use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\Plan;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transação de pagamento da assinatura SaaS. A Efí é o único gateway de novas cobranças
 * (VETOR-SAAS-EFI-03); registros de provedores anteriores permanecem como histórico, identificados
 * pelo `gateway` original. `payment_id` é o identificador no provedor (txid do Pix ou charge_id da
 * API Cobranças); `reference` é a identificação única local.
 */
class Payment extends Model
{
    use HasFactory;

    public const GATEWAY_EFI = 'efi';

    public const STATUS_PENDING = 'pending';

    /** Status de quitação gravado pelo provedor anterior; só existe no histórico. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_charge_id',
        'plan_id',
        'gateway',
        'method',
        'payment_id',
        'reference',
        'provider_subscription_id',
        'amount',
        'status',
        'idempotency_key',
        'expires_at',
        'due_date',
        'paid_at',
        'paid_amount',
        'refunded_amount',
        'raw_response',
        'details',
        'last_checked_at',
        'error',
        'admin_fiscal_document_id',
        'invoice_email',
        'invoice_email_sent_at',
        'invoice_email_error',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'details' => 'array',
        'expires_at' => 'datetime',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'paid_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'last_checked_at' => 'datetime',
        'invoice_email_sent_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function adminFiscalDocument()
    {
        return $this->belongsTo(AdminFiscalDocument::class);
    }

    public function subscriptionCharge(): BelongsTo
    {
        return $this->belongsTo(TenantSubscriptionCharge::class, 'tenant_subscription_charge_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isEfi(): bool
    {
        return $this->gateway === self::GATEWAY_EFI;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Quitado: `paid` (Efí) ou `approved` (histórico do provedor anterior). */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_APPROVED], true);
    }

    public function scopeSettled($query)
    {
        return $query->whereIn('status', [self::STATUS_PAID, self::STATUS_APPROVED]);
    }

    /** Plano pago: coluna própria; no histórico, o plano gravado na referência externa. */
    public function billedPlanId(): ?int
    {
        if ($this->plan_id) {
            return (int) $this->plan_id;
        }

        $reference = json_decode((string) data_get($this->raw_response, 'external_reference'), true);

        return is_array($reference) && isset($reference['plan_id']) ? (int) $reference['plan_id'] : null;
    }

    /** Momento da quitação: `paid_at`; no histórico, a data de aprovação gravada na resposta. */
    public function settledAt(): Carbon
    {
        return $this->paid_at?->copy()
            ?? Carbon::parse(data_get($this->raw_response, 'date_approved') ?? $this->updated_at ?? $this->created_at ?? now());
    }
}
