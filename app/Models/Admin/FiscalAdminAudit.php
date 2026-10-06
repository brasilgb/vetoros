<?php

namespace App\Models\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trilha das operações administrativas fiscais (habilitações, credenciais, ambientes, notas do SaaS). */
class FiscalAdminAudit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Nunca registrar segredos: `data` deve conter só metadados. */
    public static function record(string $action, ?int $tenantId = null, ?Model $subject = null, array $data = []): self
    {
        return static::query()->create([
            'user_id' => auth()->id(),
            'tenant_id' => $tenantId,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data === [] ? null : $data,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
