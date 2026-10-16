<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesSystemMailConfig;
use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\TenantSubscriptionCharge;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/** Fatura de um ciclo do contrato SaaS personalizado (ABrasil Sistemas → cliente contratante). */
class SaasChargeInvoiceMail extends Mailable
{
    use AppliesSystemMailConfig, Queueable, SerializesModels;

    /** @param  array{pdf: string, xml: string, expires_at: Carbon}|null  $links */
    public function __construct(
        public TenantSubscriptionCharge $charge,
        public AdminFiscalSetting $issuer,
        public ?array $links,
        public string $paymentInstructions,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Fatura VetorOS - contrato '.$this->charge->contract->contract_number.' - vencimento '.$this->charge->due_date->format('d/m/Y'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.saas-charge-invoice', with: [
            'charge' => $this->charge,
            'tenant' => $this->charge->tenant,
            'contract' => $this->charge->contract,
            'issuer' => $this->issuer,
            'links' => $this->links,
            'paymentInstructions' => $this->paymentInstructions,
        ]);
    }
}
