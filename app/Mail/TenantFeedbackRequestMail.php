<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesSystemMailConfig;
use App\Models\Tenant;
use App\Models\TenantFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantFeedbackRequestMail extends Mailable
{
    use AppliesSystemMailConfig, Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public TenantFeedback $feedback,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Como esta sua experiencia com o VetorOS?',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant-feedback-request',
            with: [
                'tenant' => $this->tenant,
                'feedback' => $this->feedback,
                'feedbackUrl' => route('tenant.feedback.show', $this->feedback->feedback_token),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
