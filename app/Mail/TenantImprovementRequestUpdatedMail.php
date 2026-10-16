<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesSystemMailConfig;
use App\Models\TenantImprovementRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantImprovementRequestUpdatedMail extends Mailable
{
    use AppliesSystemMailConfig, Queueable, SerializesModels;

    public function __construct(
        public TenantImprovementRequest $requestItem,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Atualizacao da sua solicitacao no VetorOS',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant-improvement-request-updated',
            with: [
                'requestItem' => $this->requestItem,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
