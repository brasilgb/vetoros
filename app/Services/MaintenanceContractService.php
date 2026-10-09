<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Events\OrderLifecycleCreated;
use App\Jobs\EmitMaintenanceContractInvoice;
use App\Models\App\AccountReceivable;
use App\Models\App\MaintenanceContract;
use App\Models\App\MaintenanceContractLog;
use App\Models\App\Order;
use App\Models\App\Schedule;
use App\Support\Ean13;
use App\Support\OrderActor;
use App\Support\OrderStatus;
use App\Support\TenantSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MaintenanceContractService
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
        private readonly OrderTotalsService $orderTotalsService,
    ) {}

    public function create(array $data, ?int $userId = null): MaintenanceContract
    {
        return DB::transaction(function () use ($data, $userId) {
            $startDate = Carbon::parse($data['start_date']);
            $billingDay = (int) $data['billing_day'];
            $durationMonths = $data['duration_months'] ?? null;

            $contract = MaintenanceContract::create([
                ...$data,
                'auto_issue_enabled_at' => ! empty($data['auto_issue_invoice']) ? now() : null,
                'contract_number' => TenantSequence::next(MaintenanceContract::class, 'contract_number'),
                'end_date' => $durationMonths ? $startDate->copy()->addMonthsNoOverflow((int) $durationMonths) : null,
                'next_billing_date' => $this->nextBillingDate($startDate, $billingDay),
                'next_schedule_date' => filled($data['visit_frequency_days'] ?? null) ? $startDate->copy() : null,
                'status' => MaintenanceContract::STATUS_ACTIVE,
                'created_by' => $userId,
            ]);

            $this->log($contract, $userId, 'created', [
                'monthly_amount' => (float) $contract->monthly_amount,
                'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
            ]);

            return $contract;
        });
    }

    public function update(MaintenanceContract $contract, array $data, ?int $userId = null): MaintenanceContract
    {
        $startDate = Carbon::parse($data['start_date']);
        $durationMonths = $data['duration_months'] ?? null;

        $issue = (bool) ($data['auto_issue_invoice'] ?? $contract->auto_issue_invoice);

        $contract->update([
            ...$data,
            'end_date' => $durationMonths ? $startDate->copy()->addMonthsNoOverflow((int) $durationMonths) : null,
            // Ligar a emissão automática vale daqui em diante; desligar limpa a data.
            'auto_issue_enabled_at' => $issue ? ($contract->auto_issue_enabled_at ?? now()) : null,
        ]);

        $this->log($contract, $userId, 'updated', [
            'monthly_amount' => (float) $contract->monthly_amount,
            'auto_issue_invoice' => (bool) $contract->auto_issue_invoice,
            'auto_send_invoice' => (bool) $contract->auto_send_invoice,
        ]);

        return $contract->fresh();
    }

    public function renew(MaintenanceContract $contract, array $data, ?int $userId = null): MaintenanceContract
    {
        $newDuration = $data['duration_months'] ?? null;
        $referenceDate = $contract->end_date && $contract->end_date->isFuture() ? $contract->end_date : Carbon::today();

        $contract->update([
            'duration_months' => $newDuration,
            'end_date' => $newDuration ? $referenceDate->copy()->addMonthsNoOverflow((int) $newDuration) : null,
            'status' => MaintenanceContract::STATUS_ACTIVE,
            'next_billing_date' => $contract->next_billing_date && $contract->next_billing_date->isFuture()
                ? $contract->next_billing_date
                : $this->nextBillingDate(Carbon::today(), (int) $contract->billing_day),
        ]);

        $this->log($contract, $userId, 'renewed', [
            'duration_months' => $newDuration,
            'end_date' => $contract->end_date?->toDateString(),
        ]);

        return $contract->fresh();
    }

    public function suspend(MaintenanceContract $contract, ?int $userId = null): MaintenanceContract
    {
        $contract->update(['status' => MaintenanceContract::STATUS_SUSPENDED]);
        $this->log($contract, $userId, 'suspended');

        return $contract->fresh();
    }

    public function reactivate(MaintenanceContract $contract, ?int $userId = null): MaintenanceContract
    {
        $contract->update(['status' => MaintenanceContract::STATUS_ACTIVE]);
        $this->log($contract, $userId, 'reactivated');

        return $contract->fresh();
    }

    public function cancel(MaintenanceContract $contract, ?int $userId = null): MaintenanceContract
    {
        $contract->update(['status' => MaintenanceContract::STATUS_CANCELLED]);
        $this->log($contract, $userId, 'cancelled');

        return $contract->fresh();
    }

    public function delete(MaintenanceContract $contract): void
    {
        $contract->delete();
    }

    public function expireIfNeeded(MaintenanceContract $contract): bool
    {
        if ($contract->status !== MaintenanceContract::STATUS_ACTIVE) {
            return false;
        }

        if (! $contract->end_date || $contract->end_date->isFuture()) {
            return false;
        }

        $contract->update(['status' => MaintenanceContract::STATUS_EXPIRED]);
        $this->log($contract, null, 'expired', ['end_date' => $contract->end_date->toDateString()]);

        return true;
    }

    /**
     * Gera a cobrança do ciclo vencido (processo A: cobrança recorrente). A trava no contrato
     * serializa agendadores simultâneos; o ciclo é reconferido dentro dela, então reexecutar não
     * duplica cobrança. Competência e data fiscal programada ficam gravadas na cobrança.
     */
    public function processBillingCycle(MaintenanceContract $contract): ?AccountReceivable
    {
        return DB::transaction(function () use ($contract) {
            $contract = MaintenanceContract::query()->withoutGlobalScopes()->lockForUpdate()->find($contract->id);

            if (! $contract?->next_billing_date || $contract->next_billing_date->isFuture()) {
                return null;
            }

            $dueDate = $contract->next_billing_date->copy();
            [$competenceStart, $competenceEnd] = $contract->competenceFor($dueDate);
            $installmentNumber = MaintenanceContractLog::query()->withoutGlobalScopes()
                ->where('maintenance_contract_id', $contract->id)
                ->where('action', 'billed')
                ->count() + 1;

            $receivable = AccountReceivable::create([
                'tenant_id' => $contract->tenant_id,
                'customer_id' => $contract->customer_id,
                'source_type' => AccountReceivable::SOURCE_MAINTENANCE_CONTRACT,
                'source_id' => $contract->id,
                'description' => 'Manutenção recorrente #'.$contract->contract_number.' - '.$contract->description.' - competência '.$competenceStart->format('m/Y'),
                'total_amount' => $contract->monthly_amount,
                'paid_amount' => 0,
                'balance_amount' => $contract->monthly_amount,
                'due_date' => $dueDate->toDateString(),
                'competence_start' => $competenceStart->toDateString(),
                'competence_end' => $competenceEnd->toDateString(),
                'fiscal_scheduled_for' => $contract->fiscalScheduleFor($dueDate)->toDateString(),
                'status' => AccountReceivable::STATUS_PENDING,
                'installment_number' => $installmentNumber,
                'installments_total' => 1,
            ]);

            $contract->update([
                'next_billing_date' => $dueDate->copy()->addMonthNoOverflow(),
            ]);

            $this->log($contract, null, 'billed', [
                'account_receivable_id' => $receivable->id,
                'due_date' => $receivable->due_date?->toDateString(),
                'competence' => $competenceStart->format('m/Y'),
                'fiscal_scheduled_for' => $receivable->fiscal_scheduled_for?->toDateString(),
                'amount' => (float) $receivable->total_amount,
            ]);

            return $receivable;
        });
    }

    /**
     * Processo B (emissão fiscal recorrente): enfileira a NFS-e dos ciclos cuja data fiscal chegou,
     * sem olhar o pagamento. Só entram cobranças de contratos ativos com emissão automática, cujo
     * ciclo foi programado depois de a opção ser ligada (o histórico não é emitido) e que ainda não
     * têm documento fiscal. A reserva por `fiscal_queued_at` é atômica: execuções repetidas ou
     * simultâneas não enfileiram duas vezes; uma reserva sem documento após 1 hora é refeita
     * (reconciliação de job perdido). Rejeição e falha ficam para reprocessamento manual.
     */
    public function queueScheduledInvoices(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $queued = 0;

        AccountReceivable::query()->withoutGlobalScopes()
            ->where('source_type', AccountReceivable::SOURCE_MAINTENANCE_CONTRACT)
            ->where('status', '!=', AccountReceivable::STATUS_CANCELLED)
            ->whereNotNull('fiscal_scheduled_for')
            ->whereDate('fiscal_scheduled_for', '<=', $today->toDateString())
            ->where(fn ($query) => $query->whereNull('fiscal_queued_at')->orWhere('fiscal_queued_at', '<=', now()->subHour()))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('fiscal_documents')
                ->where('fiscal_documents.documentable_type', AccountReceivable::class)
                ->whereColumn('fiscal_documents.documentable_id', 'accounts_receivable.id'))
            ->orderBy('id')
            ->chunkById(100, function ($receivables) use (&$queued) {
                foreach ($receivables as $receivable) {
                    $contract = $receivable->maintenanceContract();

                    if (! $this->isAutomaticallyInvoiceable($contract, $receivable)) {
                        continue;
                    }

                    $claimed = AccountReceivable::query()->withoutGlobalScopes()
                        ->whereKey($receivable->id)
                        ->where(fn ($query) => $query->whereNull('fiscal_queued_at')->orWhere('fiscal_queued_at', '<=', now()->subHour()))
                        ->update(['fiscal_queued_at' => now()]);

                    if ($claimed !== 1) {
                        continue;
                    }

                    $this->log($contract, null, 'invoice_queued', [
                        'account_receivable_id' => $receivable->id,
                        'fiscal_scheduled_for' => $receivable->fiscal_scheduled_for?->toDateString(),
                    ]);
                    EmitMaintenanceContractInvoice::dispatch($receivable->id);
                    $queued++;
                }
            });

        return $queued;
    }

    /** Regras da emissão automática do ciclo (também conferidas de novo pelo job). */
    public function isAutomaticallyInvoiceable(?MaintenanceContract $contract, AccountReceivable $receivable): bool
    {
        return $contract !== null
            && $contract->status === MaintenanceContract::STATUS_ACTIVE
            && $contract->auto_issue_invoice
            && $contract->auto_issue_enabled_at !== null
            && $receivable->status !== AccountReceivable::STATUS_CANCELLED
            && $receivable->fiscal_scheduled_for !== null
            && ! $receivable->fiscal_scheduled_for->isFuture()
            && $receivable->fiscal_scheduled_for->gte($contract->auto_issue_enabled_at->copy()->startOfDay());
    }

    /**
     * Gera a OS e o agendamento da próxima visita com 1 dia de antecedência da data prevista.
     */
    public function processVisitGeneration(MaintenanceContract $contract): ?Schedule
    {
        if (! $contract->visit_frequency_days || ! $contract->next_schedule_date) {
            return null;
        }

        $visitDate = $contract->next_schedule_date->copy()->startOfDay();

        // Só gera quando faltar 1 dia (ou menos) para a visita.
        if ($visitDate->copy()->subDay()->gt(Carbon::today())) {
            return null;
        }

        return DB::transaction(function () use ($contract, $visitDate) {
            $order = $this->createContractOrder($contract, $visitDate);

            $schedule = Schedule::create([
                'tenant_id' => $contract->tenant_id,
                'customer_id' => $contract->customer_id,
                'user_id' => $contract->preferred_technician_id,
                'order_id' => $order->id,
                'schedules_number' => TenantSequence::next(Schedule::class, 'schedules_number', $contract->tenant_id),
                'schedules' => $visitDate->copy()->setTime(9, 0),
                'service' => 'Manutenção recorrente #'.$contract->contract_number.' - '.$contract->description,
                'details' => (string) ($contract->notes ?? ''),
                'status' => 1,
            ]);

            $contract->update([
                'next_schedule_date' => $visitDate->copy()->addDays((int) $contract->visit_frequency_days),
            ]);

            $this->log($contract, null, 'visit_generated', [
                'schedule_id' => $schedule->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'visit_date' => $visitDate->toDateString(),
            ]);

            return $schedule;
        });
    }

    private function createContractOrder(MaintenanceContract $contract, Carbon $visitDate): Order
    {
        $orderNumber = TenantSequence::next(Order::class, 'order_number', $contract->tenant_id);
        $publicAccessKey = Str::upper(Str::random(8));

        $order = Order::create([
            'tenant_id' => $contract->tenant_id,
            'customer_id' => $contract->customer_id,
            'user_id' => $contract->preferred_technician_id,
            'order_number' => $orderNumber,
            'barcode' => Ean13::fromNumber($orderNumber),
            'tracking_token' => (string) Str::uuid(),
            'public_access_key' => $publicAccessKey,
            'public_access_key_hash' => Hash::make($publicAccessKey),
            'order_type' => Order::TYPE_EXTERNAL_SERVICE,
            'defect' => $contract->description,
            'service_type' => 'Manutenção recorrente',
            'service_details' => $contract->description,
            'service_status' => OrderStatus::OPEN,
            'delivery_forecast' => $visitDate->toDateString(),
            'observations' => 'Ordem gerada automaticamente pelo contrato de manutenção #'.$contract->contract_number.'.',
        ]);

        $this->orderTotalsService->recalculate($order);

        // Geração automática pelo contrato: ator sistema, técnico preferencial como atribuição inicial.
        $this->orderStatusService->recordCreation($order, OrderActor::system(), [
            'source' => 'maintenance_contract',
            'maintenance_contract_id' => $contract->id,
        ]);

        event(new OrderLifecycleCreated($order->id, null, [
            'status' => (int) $order->service_status,
            'status_label' => OrderStatus::label($order->service_status),
            'customer_id' => $order->customer_id,
            'equipment_id' => $order->equipment_id,
            'is_warranty_return' => false,
            'source' => 'maintenance_contract',
            'maintenance_contract_id' => $contract->id,
        ]));

        try {
            event(new OrderCreated($order));
        } catch (\Throwable $e) {
            report($e);
        }

        return $order;
    }

    private function nextBillingDate(Carbon $startDate, int $billingDay): Carbon
    {
        // billing_day é limitado a 1-28 na validação, então é seguro em qualquer mês.
        $candidate = $startDate->copy()->day($billingDay);

        if ($candidate->lt($startDate->copy()->startOfDay())) {
            $candidate = $candidate->addMonthNoOverflow();
        }

        return $candidate;
    }

    private function log(MaintenanceContract $contract, ?int $userId, string $action, array $data = []): void
    {
        MaintenanceContractLog::create([
            'tenant_id' => $contract->tenant_id,
            'maintenance_contract_id' => $contract->id,
            'user_id' => $userId,
            'action' => $action,
            'data' => $data,
        ]);
    }
}
