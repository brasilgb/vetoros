<?php

namespace App\Models\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entrada do RootAdmin no painel de uma empresa (Modo Suporte, VETOR-STABILIZE-03). Não usa o
 * escopo de tenant: é registro da plataforma, consultado pelo RootAdmin.
 */
class SupportSession extends Model
{
    use Prunable;

    public const END_MANUAL = 'manual';

    public const END_EXPIRED = 'expired';

    public const END_LOGOUT = 'logout';

    public const END_INVALID = 'invalid';

    protected $fillable = [
        'root_user_id',
        'tenant_id',
        'reason',
        'ip_address',
        'user_agent',
        'started_at',
        'expires_at',
        'ended_at',
        'end_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function rootUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'root_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SupportSessionAction::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function end(string $reason): void
    {
        if ($this->ended_at === null) {
            $this->forceFill(['ended_at' => now(), 'end_reason' => $reason])->save();
        }
    }

    /** Retenção (config support.retention_days); as ações saem junto (cascade). */
    public function prunable(): Builder
    {
        return static::query()->where('started_at', '<', now()->subDays(max(1, (int) config('support.retention_days', 365))));
    }
}
