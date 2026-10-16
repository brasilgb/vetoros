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

class OrderStatusUpdatedMail extends Mailable
{
    use AppliesTenantMailConfig, Queueable, ResolvesTenantBranding, SerializesModels;

    public Order $order;

    public string $statusLabel;

    public ?string $note;

    public ?int $tenantId;

    public function __construct(Order $order, string $statusLabel, ?string $note = null)
    {
        $this->order = $order;
        $this->statusLabel = $statusLabel;
        $this->note = $note;
        $this->tenantId = $order->tenant_id ? (int) $order->tenant_id : null;
        $this->resolveTenantBranding($this->tenantId);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Atualização da sua ordem #'.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-status-updated',
            with: array_merge([
                'order' => $this->order,
                'statusLabel' => $this->statusLabel,
                'note' => $this->note,
            ], $this->tenantBrandingViewData()),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
