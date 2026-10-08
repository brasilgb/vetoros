<?php

namespace App\Services;

use App\Mail\OrderBudgetFollowUpMail;
use App\Mail\OrderCreatedMail;
use App\Mail\OrderFeedbackReminderMail;
use App\Mail\OrderPaymentReminderMail;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\App\Order;
use App\Models\App\OrderMessage;
use App\Support\OrderStatus;
use App\Support\TenantMailConfig;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class OrderNotificationService
{
    public function __construct(private readonly OrderMessageService $messages) {}

    /**
     * Envia o e-mail e registra a comunicação em order_messages. A falha é registrada
     * sem detalhes do servidor SMTP (podem conter usuário/host) e a exceção segue para
     * o job, preservando o retry atual.
     */
    private function sendRecorded(Order $order, string $customerEmail, string $template, Mailable $mail): void
    {
        if (! $order->tenant_id) {
            // OS legada sem tenant não pode ter trilha (tenant obrigatório): só envia, como antes.
            Mail::to($customerEmail)->send($mail);

            return;
        }

        try {
            Mail::to($customerEmail)->send($mail);
        } catch (\Throwable $exception) {
            $this->messages->recordFailed($order, OrderMessage::CHANNEL_EMAIL, $customerEmail, $template, 'smtp', class_basename($exception), 'Falha no envio do e-mail.');

            throw $exception;
        }

        $this->messages->recordSent($order, OrderMessage::CHANNEL_EMAIL, $customerEmail, $template, 'smtp');
    }

    private function resolveOrder(int $orderId): ?Order
    {
        return Order::query()
            ->with(['customer', 'tenant'])
            ->find($orderId);
    }

    public function canSendToCustomer(Order $order, ?string $customerEmail): bool
    {
        $email = trim((string) ($customerEmail ?? ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return TenantMailConfig::hasConfiguredForTenantId($order->tenant_id ? (int) $order->tenant_id : null);
    }

    public function sendCreated(Order $order): void
    {
        if (! $this->canSendToCustomer($order->loadMissing(['customer', 'tenant']), $order->customer?->email)) {
            return;
        }

        $this->deliverCreated($order->id);
    }

    public function sendStatusUpdated(Order $order, string $statusLabel, ?string $observations = null): void
    {
        if (! $this->canSendToCustomer($order->loadMissing(['customer', 'tenant']), $order->customer?->email)) {
            return;
        }

        $this->deliverStatusUpdated($order->id, $statusLabel, $observations);
    }

    public function sendPaymentReminder(Order $order, array $paymentSummary, bool $isOverdue): void
    {
        if (! $this->canSendToCustomer($order->loadMissing(['customer', 'tenant']), $order->customer?->email)) {
            return;
        }

        $this->deliverPaymentReminder($order->id, $paymentSummary, $isOverdue);
    }

    public function sendBudgetFollowUp(Order $order, int $daysPending): void
    {
        if (! $this->canSendToCustomer($order->loadMissing(['customer', 'tenant']), $order->customer?->email)) {
            return;
        }

        $this->deliverBudgetFollowUp($order->id, $daysPending);
    }

    public function sendFeedbackReminder(Order $order): void
    {
        if (! $this->canSendToCustomer($order->loadMissing(['customer', 'tenant']), $order->customer?->email)) {
            return;
        }

        $this->deliverFeedbackReminder($order->id);
    }

    public function deliverCreated(int $orderId): void
    {
        $order = $this->resolveOrder($orderId);

        if (! $order) {
            return;
        }

        $customerEmail = trim((string) ($order->customer?->email ?? ''));

        if (! $this->canSendToCustomer($order, $customerEmail)) {
            return;
        }

        TenantMailConfig::applyForTenantId($order->tenant_id ? (int) $order->tenant_id : null);
        $this->sendRecorded($order, $customerEmail, 'order_created', new OrderCreatedMail($order));
    }

    public function deliverStatusUpdated(int $orderId, string $statusLabel, ?string $observations = null): void
    {
        $order = $this->resolveOrder($orderId);

        if (! $order) {
            return;
        }

        $customerEmail = trim((string) ($order->customer?->email ?? ''));

        if (! $this->canSendToCustomer($order, $customerEmail)) {
            return;
        }

        TenantMailConfig::applyForTenantId($order->tenant_id ? (int) $order->tenant_id : null);
        // Aviso de "Orçamento Gerado" leva o orçamento: a mensagem fica ligada à versão enviada.
        $template = (int) $order->service_status === OrderStatus::BUDGET_GENERATED ? 'budget_generated' : 'status_updated';
        $this->sendRecorded($order, $customerEmail, $template, new OrderStatusUpdatedMail($order, $statusLabel, $observations));
    }

    public function deliverPaymentReminder(int $orderId, array $paymentSummary, bool $isOverdue): void
    {
        $order = $this->resolveOrder($orderId);

        if (! $order) {
            return;
        }

        $customerEmail = trim((string) ($order->customer?->email ?? ''));

        if (! $this->canSendToCustomer($order, $customerEmail)) {
            return;
        }

        TenantMailConfig::applyForTenantId($order->tenant_id ? (int) $order->tenant_id : null);
        $this->sendRecorded($order, $customerEmail, 'payment_reminder', new OrderPaymentReminderMail($order, $paymentSummary, $isOverdue));
    }

    public function deliverBudgetFollowUp(int $orderId, int $daysPending): void
    {
        $order = $this->resolveOrder($orderId);

        if (! $order) {
            return;
        }

        $customerEmail = trim((string) ($order->customer?->email ?? ''));

        if (! $this->canSendToCustomer($order, $customerEmail)) {
            return;
        }

        TenantMailConfig::applyForTenantId($order->tenant_id ? (int) $order->tenant_id : null);
        $this->sendRecorded($order, $customerEmail, 'budget_follow_up', new OrderBudgetFollowUpMail($order, $daysPending));
    }

    public function deliverFeedbackReminder(int $orderId): void
    {
        $order = $this->resolveOrder($orderId);

        if (! $order || $order->customer_feedback_submitted_at || $order->customer_feedback_request_expired_at) {
            return;
        }

        $customerEmail = trim((string) ($order->customer?->email ?? ''));

        if (! $this->canSendToCustomer($order, $customerEmail)) {
            return;
        }

        TenantMailConfig::applyForTenantId($order->tenant_id ? (int) $order->tenant_id : null);
        $this->sendRecorded($order, $customerEmail, 'feedback_reminder', new OrderFeedbackReminderMail($order));
    }
}
