<?php

namespace App\Mail;

use App\Mail\Concerns\ResolvesTenantBranding;
use App\Models\App\FiscalDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Nota fiscal enviada ao cliente final pela empresa do tenant (VETOR-FISCAL-05). O SMTP do
 * tenant é aplicado por quem envia, antes de Mail::to() (FiscalDocumentDeliveryService).
 */
class FiscalDocumentMail extends Mailable
{
    use Queueable, ResolvesTenantBranding, SerializesModels;

    public function __construct(
        public FiscalDocument $document,
        public string $customerName,
        public string $description,
        private readonly ?string $pdf,
        private readonly ?string $xml,
    ) {
        $this->resolveTenantBranding((int) $document->tenant_id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Nota fiscal de serviço nº %s - %s', $this->document->number ?: $this->document->id, $this->companyName),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.fiscal-document',
            with: array_merge([
                'document' => $this->document,
                'customerName' => $this->customerName,
                'description' => $this->description,
            ], $this->tenantBrandingViewData()),
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $name = 'nfse-'.($this->document->number ?: $this->document->id);

        return array_values(array_filter([
            $this->pdf !== null ? Attachment::fromData(fn () => $this->pdf, $name.'.pdf')->withMime('application/pdf') : null,
            $this->xml !== null ? Attachment::fromData(fn () => $this->xml, $name.'.xml')->withMime('application/xml') : null,
        ]));
    }
}
