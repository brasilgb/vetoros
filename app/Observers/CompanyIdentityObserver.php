<?php

namespace App\Observers;

use App\Models\Admin\FiscalAdminAudit;
use App\Models\App\Company;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Audita mudanças de CNPJ e razão social da empresa do tenant, venham da tela de
 * Empresa (companies), do RootAdmin (tenants) ou da sincronização entre as duas.
 * `tenants` é o tomador da NFS-e do SaaS e `companies` o emitente das notas do tenant.
 */
class CompanyIdentityObserver
{
    /** @var array<class-string<Model>, array<string, string>> coluna => nome no registro */
    private const FIELDS = [
        Company::class => ['cnpj' => 'cnpj', 'companyname' => 'legal_name'],
        Tenant::class => ['cnpj' => 'cnpj', 'company' => 'legal_name'],
    ];

    public const ACTION = 'company.identity_changed';

    public function updated(Model $model): void
    {
        $changes = [];

        foreach (self::FIELDS[$model::class] ?? [] as $column => $label) {
            if ($model->wasChanged($column)) {
                $changes[$label] = ['from' => $model->getOriginal($column), 'to' => $model->getAttribute($column)];
            }
        }

        if ($changes === []) {
            return;
        }

        $tenantId = $model instanceof Tenant ? (int) $model->getKey() : ($model->getAttribute('tenant_id') ? (int) $model->getAttribute('tenant_id') : null);

        FiscalAdminAudit::record(self::ACTION, $tenantId, $model, [
            'source' => $model->getTable(),
            'changes' => $changes,
        ]);
    }
}
