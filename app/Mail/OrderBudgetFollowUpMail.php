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

class OrderBudgetFollowUpMail extends Mailable
{
    use AppliesTenantMailConfig, Queueable, ResolvesTenantBranding, SerializesModels;

    public Order $order;

    public int $daysPending;

    public ?int $tenantId;

    public function __construct(Order $order, int $daysPending)
    {
        $this->order = $order;
        $this->daysPending = $daysPending;
        $this->tenantId = $order->tenant_id ? (int) $order->tenant_id : null;
        $this->resolveTenantBranding($this->tenantId);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Lembrete de orçamento da ordem #'.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-budget-follow-up',
            with: array_merge([
                'order' => $this->order,
                'daysPending' => $this->daysPending,
            ], $this->tenantBrandingViewData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
