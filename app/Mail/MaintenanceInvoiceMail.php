<?php

namespace App\Mail;

use App\Mail\Concerns\ResolvesTenantBranding;
use App\Models\App\AccountReceivable;
use App\Models\App\Customer;
use App\Models\App\FiscalDocument;
use App\Models\App\MaintenanceContract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Fatura de manutenção com a NFS-e autorizada (VETOR-FISCAL-05.3). Os documentos vão por links
 * assinados e temporários (FiscalDocumentLinks), não como anexo nem por caminho de armazenamento.
 * O SMTP do tenant é aplicado por quem envia, antes de Mail::to() (FiscalDocumentDeliveryService).
 */
class MaintenanceInvoiceMail extends Mailable
{
    use Queueable, ResolvesTenantBranding, SerializesModels;

    public const PAYMENT_LABELS = [
        AccountReceivable::STATUS_PENDING => 'Em aberto',
        AccountReceivable::STATUS_PARTIAL => 'Parcialmente pago',
        AccountReceivable::STATUS_PAID => 'Pago',
        AccountReceivable::STATUS_CANCELLED => 'Cancelada',
    ];

    public function __construct(
        public FiscalDocument $document,
        public AccountReceivable $receivable,
        public MaintenanceContract $contract,
        public ?Customer $customer,
        public string $pdfUrl,
        public string $xmlUrl,
        public Carbon $linksExpireAt,
    ) {
        $this->resolveTenantBranding((int) $document->tenant_id);
    }

    public function competence(): string
    {
        return ($this->receivable->competence_start ?? $this->receivable->due_date)?->format('m/Y') ?? '-';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Fatura de manutenção — Contrato nº %s — %s', $this->contract->contract_number ?: $this->contract->id, $this->competence()),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.maintenance-invoice',
            with: array_merge([
                'document' => $this->document,
                'receivable' => $this->receivable,
                'contract' => $this->contract,
                'customerName' => $this->customer?->name ?: 'cliente',
                'competence' => $this->competence(),
                'paymentStatus' => self::PAYMENT_LABELS[$this->receivable->status] ?? $this->receivable->status,
                'pdfUrl' => $this->pdfUrl,
                'xmlUrl' => $this->xmlUrl,
                'linksExpireAt' => $this->linksExpireAt,
            ], $this->tenantBrandingViewData()),
        );
    }
}
