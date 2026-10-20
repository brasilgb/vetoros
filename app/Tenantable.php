<?php

namespace App;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantWriteGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait Tenantable
{
    protected static function resolveTenantId(): ?int
    {
        return resolveCurrentTenantId();
    }

    protected static function bootTenantable()
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (! checkTenantId()) {
                return;
            }

            $tenantId = static::resolveTenantId();

            if (! TenantWriteGuard::active()) {
                if (empty($model->tenant_id)) {
                    $model->tenant_id = $tenantId;
                }

                return;
            }

            // Numa requisição com empresa no contexto (usuário ou Modo Suporte), o registro é
            // sempre dela: um tenant_id vindo da requisição nunca prevalece.

            if (! empty($model->tenant_id) && (int) $model->tenant_id !== $tenantId) {
                Log::warning('Tentativa de gravar registro em outra empresa bloqueada.', [
                    'model' => $model::class,
                    'requested_tenant_id' => (int) $model->tenant_id,
                    'tenant_id' => $tenantId,
                    'user_id' => Auth::id(),
                ]);
            }

            $model->tenant_id = $tenantId;
        });

        static::updating(function ($model) {
            if (! TenantWriteGuard::active() || ! checkTenantId() || ! $model->isDirty('tenant_id')) {
                return;
            }

            Log::warning('Tentativa de mover registro para outra empresa bloqueada.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'requested_tenant_id' => $model->tenant_id,
                'tenant_id' => $model->getOriginal('tenant_id'),
                'user_id' => Auth::id(),
            ]);

            $model->tenant_id = $model->getOriginal('tenant_id');
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $field ??= $this->getRouteKeyName();
        $query = $this->newQuery()->where($field, $value);
        $tenantId = static::resolveTenantId();

        if (! is_null($tenantId)) {
            $query->where($this->getTable().'.tenant_id', $tenantId);
        }

        return $query->firstOrFail();
    }
}
