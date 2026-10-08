<?php

namespace App\Console\Commands;

use App\Models\App\Order;
use App\Models\App\OrderLog;
use App\Models\App\Other;
use App\Services\FollowUpTaskService;
use App\Services\OrderNotificationService;
use App\Support\OrderStatus;
use Illuminate\Console\Command;

class SendBudgetFollowUps extends Command
{
    protected $signature = 'vetoros:send-budget-followups {--tenant=} {--dry-run}';

    protected $description = 'Envia acompanhamento automático para orçamentos parados';

    private const MAX_AUTOMATIC_FOLLOW_UPS = 3;

    public function __construct(
        private readonly OrderNotificationService $orderNotificationService,
        private readonly FollowUpTaskService $followUpTaskService,
    ) {
        parent::__construct();
    }

    private function automaticFollowUpsSent(Order $order): int
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('action', 'budget_follow_up_sent')
            ->where('data->trigger', 'automatic')
            ->count();
    }

    private function cooldownDays(?int $tenantId): int
    {
        return Other::communicationFollowUpCooldownDays($tenantId);
    }

    private function automaticFollowUpsEnabled(?int $tenantId): bool
    {
        return Other::automaticFollowUpsEnabled($tenantId);
    }

    private function hasRecentFollowUp(Order $order): bool
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('action', 'budget_follow_up_sent')
            ->where('created_at', '>=', now()->subDays($this->cooldownDays($order->tenant_id ? (int) $order->tenant_id : null)))
            ->exists();
    }

    private function eligibleOrders()
    {
        $query = Order::query()
            ->with('customer', 'tenant')
            ->where('service_status', OrderStatus::BUDGET_GENERATED);

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenantId);
        }

        return $query->orderBy('tenant_id')->orderBy('id')->get();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $sent = 0;
        $skipped = 0;

        foreach ($this->eligibleOrders() as $order) {
            $processed++;

            $tenantId = $order->tenant_id ? (int) $order->tenant_id : null;

            if (! $this->automaticFollowUpsEnabled($tenantId)) {
                $skipped++;
                continue;
            }

            $cooldownDays = $this->cooldownDays($tenantId);
            $customerEmail = trim((string) ($order->customer?->email ?? ''));
            $daysPending = max(0, ($order->budgetPendingSince() ?? $order->created_at)?->diffInDays(now()) ?? 0);

            if ($daysPending < $cooldownDays) {
                $skipped++;
                continue;
            }

            if (! is_null($order->budget_follow_up_paused_at)) {
                $skipped++;
                continue;
            }

            if ($customerEmail === '' || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            if (! $this->orderNotificationService->canSendToCustomer($order, $customerEmail)) {
                $skipped++;
                continue;
            }

            if ($this->hasRecentFollowUp($order)) {
                $skipped++;
                continue;
            }

            if ($this->automaticFollowUpsSent($order) >= self::MAX_AUTOMATIC_FOLLOW_UPS) {
                $skipped++;

                if (! $dryRun) {
                    $this->followUpTaskService->pause(
                        $order,
                        'budget',
                        sprintf('Pausado automaticamente após %d tentativas de contato sem resposta.', self::MAX_AUTOMATIC_FOLLOW_UPS),
                        null,
                    );
                }

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    'Dry-run: OS #%s tenant %s orçamento parado há %d dias -> %s',
                    $order->order_number,
                    $tenantId ?? '-',
                    $daysPending,
                    $customerEmail
                ));
                $sent++;
                continue;
            }

            try {
                $this->orderNotificationService->sendBudgetFollowUp($order, $daysPending);

                OrderLog::create([
                    'order_id' => $order->id,
                    'user_id' => null,
                    'action' => 'budget_follow_up_sent',
                    'data' => [
                        'channel' => 'email',
                        'recipient' => $customerEmail,
                        'days_pending' => $daysPending,
                        'trigger' => 'automatic',
                    ],
                    'created_at' => now(),
                ]);

                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $skipped++;
                $this->warn(sprintf('Falha ao enviar acompanhamento da OS #%s: %s', $order->order_number, $e->getMessage()));
            }
        }

        $this->info(sprintf('Processadas: %d | Enviadas: %d | Ignoradas: %d', $processed, $sent, $skipped));

        return self::SUCCESS;
    }
}
