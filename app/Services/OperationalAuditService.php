<?php

namespace App\Services;

use App\Models\App\OperationalAudit;
use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Model;

class OperationalAuditService
{
    public function record(
        string $action,
        string $entityType,
        Model $entity,
        ?int $userId = null,
        array $data = []
    ): OperationalAudit {
        /** @var mixed $tenantId */
        $tenantId = $entity->getAttribute('tenant_id');

        return OperationalAudit::create([
            'tenant_id' => (int) $tenantId,
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entity->getKey(),
            'action' => $action,
            // Ex.: o JSON 'changes' de order_status_changed incluía a senha do equipamento.
            'data' => SensitiveData::strip($data),
            'created_at' => now(),
        ]);
    }
}
