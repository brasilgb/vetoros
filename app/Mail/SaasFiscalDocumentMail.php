<?php

namespace App\Mail;

use App\Models\Admin\AdminFiscalDocument;
use App\Support\TenantMailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** NFS-e da assinatura VetorOS enviada pelo RootAdmin ao cliente contratante. */
class SaasFiscalDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AdminFiscalDocument $document, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        TenantMailConfig::applySystemDefault();

        return new Envelope(subject: 'Nota fiscal de serviço VetorOS nº '.($this->document->number ?: $this->document->id));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.saas-fiscal-document', with: ['document' => $this->document]);
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, 'nfse-vetoros-'.($this->document->number ?: $this->document->id).'.pdf')->withMime('application/pdf'),
        ];
    }
}
