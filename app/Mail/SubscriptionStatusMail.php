<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesSystemMailConfig;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionStatusMail extends Mailable
{
    use AppliesSystemMailConfig, Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public array $notice
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) ($this->notice['subject'] ?? 'Atualização da sua assinatura'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-status',
            with: [
                'tenant' => $this->tenant,
                'notice' => $this->notice,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
