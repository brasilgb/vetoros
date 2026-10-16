<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesTenantMailConfig;
use App\Mail\Concerns\ResolvesTenantBranding;
use App\Models\App\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPaymentReminderMail extends Mailable
{
    use AppliesTenantMailConfig, Queueable, ResolvesTenantBranding, SerializesModels;

    public Order $order;

    public array $paymentSummary;

    public bool $isOverdue;

    public ?int $tenantId;

    public function __construct(Order $order, array $paymentSummary, bool $isOverdue = false)
    {
        $this->order = $order;
        $this->paymentSummary = $paymentSummary;
        $this->isOverdue = $isOverdue;
        $this->tenantId = $order->tenant_id ? (int) $order->tenant_id : null;
        $this->resolveTenantBranding($this->tenantId);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isOverdue
                ? 'Cobrança pendente da ordem #'.$this->order->order_number
                : 'Lembrete de pagamento da ordem #'.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-payment-reminder',
            with: array_merge([
                'order' => $this->order,
                'paymentSummary' => $this->paymentSummary,
                'isOverdue' => $this->isOverdue,
            ], $this->tenantBrandingViewData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
