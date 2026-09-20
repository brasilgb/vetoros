<?php

namespace App\Console\Commands;

use App\Models\App\Order;
use App\Models\App\Other;
use App\Services\OrderNotificationService;
use App\Support\OrderStatus;
use Illuminate\Console\Command;

class ProcessCustomerFeedbackRequests extends Command
{
    protected $signature = 'vetoros:process-customer-feedback-requests {--tenant=} {--dry-run}';

    protected $description = 'Envia lembretes de avaliação e retira solicitações antigas da fila';

    private const EXPIRATION_GRACE_DAYS = 7;

    public function __construct(private readonly OrderNotificationService $orderNotificationService)
    {
        parent::__construct();
    }

    private function eligibleOrders()
    {
        $query = Order::query()
            ->with(['customer', 'tenant'])
            ->where('service_status', OrderStatus::DELIVERED)
            ->whereNotNull('delivery_date')
            ->whereNull('customer_feedback_submitted_at')
            ->whereNull('customer_feedback_request_expired_at');

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
        $expired = 0;
        $skipped = 0;

        foreach ($this->eligibleOrders() as $order) {
            $processed++;
            $tenantId = $order->tenant_id ? (int) $order->tenant_id : null;
            $delayDays = Other::customerFeedbackRequestDelayDays($tenantId);
            $daysSinceDelivery = (int) max(0, $order->delivery_date?->diffInDays(now()) ?? 0);
            $reminderDueAt = $order->delivery_date?->copy()->addDays($delayDays);
            $expirationDueAt = $reminderDueAt?->copy()->addDays(self::EXPIRATION_GRACE_DAYS);

            if ($expirationDueAt?->lte(now())) {
                if (! $dryRun) {
                    $order->update(['customer_feedback_request_expired_at' => now()]);
                }

                $expired++;

                continue;
            }

            if (! $reminderDueAt?->lte(now()) || $order->customer_feedback_reminder_sent_at) {
                $skipped++;

                continue;
            }

            $customerEmail = trim((string) ($order->customer?->email ?? ''));

            if (! $this->orderNotificationService->canSendToCustomer($order, $customerEmail)) {
                $skipped++;

                continue;
            }

            if (! $dryRun) {
                try {
                    $this->orderNotificationService->sendFeedbackReminder($order);
                    $order->update(['customer_feedback_reminder_sent_at' => now()]);
                } catch (\Throwable $e) {
                    report($e);
                    $skipped++;
                    $this->warn(sprintf('Falha ao agendar lembrete da OS #%s: %s', $order->order_number, $e->getMessage()));

                    continue;
                }
            }

            $sent++;
        }

        $this->info(sprintf(
            'Processadas: %d | Lembretes: %d | Expiradas: %d | Ignoradas: %d',
            $processed,
            $sent,
            $expired,
            $skipped
        ));

        return self::SUCCESS;
    }
}
