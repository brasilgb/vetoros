<?php

namespace App\Http\Controllers\Admin\Fiscal;

use App\Http\Controllers\Controller;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use App\Services\Fiscal\Spedy\SpedyClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RootAdmin → Fiscal → Monitoramento das notas dos clientes. Só lê o banco.
 * Os indicadores de utilização servem de base para uma política comercial
 * futura; não há cobrança por nota.
 */
class FiscalMonitoringController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'tenant_id' => 'nullable|integer|exists:tenants,id',
            'model' => ['nullable', Rule::in([SpedyClient::MODEL_NFE, SpedyClient::MODEL_NFCE, SpedyClient::MODEL_NFSE])],
        ]);

        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now())->endOfDay();

        $base = fn (): Builder => FiscalDocument::query()->withoutGlobalScopes()
            ->where('provider', FiscalSetting::PROVIDER_SPEDY)
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['tenant_id'] ?? null, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($filters['model'] ?? null, fn ($query, $model) => $query->where('type', $model));

        $byStatus = $base()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $byTenant = $base()
            ->selectRaw("tenant_id, type, COUNT(*) as total, SUM(CASE WHEN status = 'authorized' THEN 1 ELSE 0 END) as authorized, SUM(CASE WHEN status IN ('rejected','denied','failed') THEN 1 ELSE 0 END) as failures")
            ->groupBy('tenant_id', 'type')
            ->orderByDesc('total')
            ->limit(100)
            ->get();

        $tenantNames = Tenant::query()->whereIn('id', $byTenant->pluck('tenant_id')->unique())->pluck('company', 'id');

        // Utilização mensal (notas autorizadas) para política comercial futura.
        $monthExpression = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";
        $usage = $base()
            ->where('status', FiscalDocument::STATUS_AUTHORIZED)
            ->selectRaw("{$monthExpression} as month, type, COUNT(*) as total")
            ->groupBy('month', 'type')
            ->orderBy('month')
            ->get();

        $failures = $base()
            ->whereIn('status', [FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_DENIED, FiscalDocument::STATUS_FAILED])
            ->latest('id')
            ->limit(20)
            ->get(['id', 'tenant_id', 'type', 'status', 'error_message', 'created_at']);

        $pending = FiscalDocument::query()->withoutGlobalScopes()
            ->where('provider', FiscalSetting::PROVIDER_SPEDY)
            ->whereIn('status', FiscalDocument::PENDING_STATUSES)
            ->when($filters['tenant_id'] ?? null, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->oldest('submitted_at')
            ->limit(20)
            ->get(['id', 'tenant_id', 'type', 'status', 'provider_reference', 'submitted_at', 'created_at']);

        $names = Tenant::query()->whereIn('id', $failures->pluck('tenant_id')->merge($pending->pluck('tenant_id'))->unique())->pluck('company', 'id');

        return Inertia::render('admin/fiscal/monitoring', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'tenant_id' => $filters['tenant_id'] ?? null,
                'model' => $filters['model'] ?? null,
            ],
            'tenants' => Tenant::query()->orderBy('company')->get(['id', 'company']),
            'summary' => [
                'total' => (int) $byStatus->sum(),
                'authorized' => (int) ($byStatus[FiscalDocument::STATUS_AUTHORIZED] ?? 0),
                'rejected' => (int) (($byStatus[FiscalDocument::STATUS_REJECTED] ?? 0) + ($byStatus[FiscalDocument::STATUS_DENIED] ?? 0)),
                'failed' => (int) ($byStatus[FiscalDocument::STATUS_FAILED] ?? 0),
                'cancelled' => (int) ($byStatus[FiscalDocument::STATUS_CANCELLED] ?? 0),
                'pending' => (int) (($byStatus[FiscalDocument::STATUS_PROCESSING] ?? 0) + ($byStatus[FiscalDocument::STATUS_CONTINGENCY] ?? 0)),
            ],
            'byTenant' => $byTenant->map(fn ($row) => [
                'tenant_id' => $row->tenant_id,
                'tenant' => $tenantNames[$row->tenant_id] ?? "#{$row->tenant_id}",
                'type' => $row->type,
                'total' => (int) $row->total,
                'authorized' => (int) $row->authorized,
                'failures' => (int) $row->failures,
            ]),
            'usage' => $usage->map(fn ($row) => ['month' => $row->month, 'type' => $row->type, 'total' => (int) $row->total]),
            'failures' => $failures->map(fn (FiscalDocument $document) => [
                ...$document->only(['id', 'tenant_id', 'type', 'status', 'error_message']),
                'tenant' => $names[$document->tenant_id] ?? "#{$document->tenant_id}",
                'created_at' => $document->created_at?->toIso8601String(),
            ]),
            'pending' => $pending->map(fn (FiscalDocument $document) => [
                ...$document->only(['id', 'tenant_id', 'type', 'status']),
                'tenant' => $names[$document->tenant_id] ?? "#{$document->tenant_id}",
                'confirmed_by_provider' => filled($document->provider_reference),
                'submitted_at' => ($document->submitted_at ?? $document->created_at)?->toIso8601String(),
            ]),
            'history' => FiscalAdminAudit::query()
                ->with(['user:id,name', 'tenant:id,company'])
                ->when($filters['tenant_id'] ?? null, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
                ->latest('id')
                ->limit(30)
                ->get(['id', 'user_id', 'tenant_id', 'action', 'data', 'created_at']),
            'webhookEvents' => DB::table('fiscal_webhook_events')->whereBetween('created_at', [$from, $to])
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN processed_at IS NULL THEN 1 ELSE 0 END) as unprocessed')
                ->first(),
        ]);
    }
}
