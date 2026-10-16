<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesSystemMailConfig;
use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\AdminFiscalSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/** NFS-e autorizada da assinatura VetorOS, com links assinados e temporários para PDF e XML. */
class SaasFiscalNoticeMail extends Mailable
{
    use AppliesSystemMailConfig, Queueable, SerializesModels;

    /** @param  array{pdf: string, xml: string, expires_at: Carbon}  $links */
    public function __construct(public AdminFiscalDocument $document, public AdminFiscalSetting $issuer, public array $links) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nota fiscal de serviço VetorOS nº '.($this->document->number ?: $this->document->id));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.saas-fiscal-notice', with: [
            'document' => $this->document,
            'tenant' => $this->document->tenant,
            'charge' => $this->document->subscriptionCharge,
            'issuer' => $this->issuer,
            'links' => $this->links,
        ]);
    }
}
