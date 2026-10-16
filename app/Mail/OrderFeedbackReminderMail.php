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

class OrderFeedbackReminderMail extends Mailable
{
    use AppliesTenantMailConfig, Queueable, ResolvesTenantBranding, SerializesModels;

    public ?int $tenantId;

    public function __construct(public Order $order)
    {
        $this->tenantId = $order->tenant_id ? (int) $order->tenant_id : null;
        $this->resolveTenantBranding($this->tenantId);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Como foi seu atendimento? Avalie a ordem #'.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-feedback-reminder',
            with: array_merge([
                'order' => $this->order,
            ], $this->tenantBrandingViewData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
