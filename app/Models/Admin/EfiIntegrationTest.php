<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cobrança de teste da integração Efí (VETOROS-EFI-TEST-01), sempre `source=integration_test`.
 * Isolada das cobranças reais: sem tenant, contrato, assinatura, nota fiscal ou financeiro.
 */
class EfiIntegrationTest extends Model
{
    public const SOURCE = 'integration_test';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    /** A Efí recusou a criação (nenhuma cobrança existe no provedor). */
    public const STATUS_ERROR = 'error';

    protected $guarded = ['id'];

    protected $attributes = ['source' => self::SOURCE];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'details' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'webhook_received_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'webhook_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Dados para o modal: sem credenciais, token ou dados do cartão (só a máscara). */
    public function toPanel(): array
    {
        $details = $this->details ?? [];

        return [
            'id' => $this->id,
            'source' => $this->source,
            'method' => $this->method,
            'environment' => $this->environment,
            'amount' => (string) $this->amount,
            'provider_id' => $this->provider_id,
            'reference' => $this->reference,
            'status' => $this->status,
            'provider_status' => $this->provider_status,
            'error' => $this->error,
            'pix_copy_paste' => $details['pix_copy_paste'] ?? null,
            'pix_qr_image' => $details['pix_qr_image'] ?? null,
            'barcode' => $details['barcode'] ?? null,
            'billet_link' => $details['billet_link'] ?? null,
            'billet_pdf' => $details['billet_pdf'] ?? null,
            'due_date' => $details['due_date'] ?? null,
            'card_mask' => $details['card_mask'] ?? null,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'webhook_received_at' => $this->webhook_received_at?->toIso8601String(),
            'webhook_count' => $this->webhook_count,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->creator?->name,
        ];
    }
}
