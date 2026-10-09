<?php

namespace App\Services\Fiscal;

use App\Mail\FiscalDocumentMail;
use App\Models\App\AccountReceivable;
use App\Models\App\Customer;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalDocumentDelivery;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Support\TenantMailConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envio da nota fiscal autorizada ao cliente final, pelo SMTP da empresa do tenant.
 * Cada tentativa vira um registro (enviado ou falha, com motivo seguro). Nunca emite nota:
 * reenvio e falha de e-mail não tocam no documento fiscal.
 */
class FiscalDocumentDeliveryService
{
    public function __construct(private readonly NativeFiscalService $native) {}

    public function send(FiscalDocument $document, string $origin, ?int $userId = null): FiscalDocumentDelivery
    {
        if ($document->status !== FiscalDocument::STATUS_AUTHORIZED) {
            throw new FiscalEmissionException('Somente notas autorizadas podem ser enviadas ao cliente.');
        }

        [$customer, $description] = $this->recipientFor($document);
        $email = trim((string) $customer?->email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->record($document, $origin, $userId, $email ?: null, 'O cliente não tem e-mail válido no cadastro.');
        }

        if (! TenantMailConfig::hasConfiguredForTenantId((int) $document->tenant_id)) {
            return $this->record($document, $origin, $userId, $email, 'O SMTP da empresa não está configurado em Sistema e módulos.');
        }

        try {
            $pdf = $this->native->download($document, 'pdf');
            $xml = $this->native->download($document, 'xml');
        } catch (SpedyException|FiscalEmissionException) {
            return $this->record($document, $origin, $userId, $email, 'Arquivos da nota indisponíveis no momento. Tente reenviar mais tarde.');
        }

        // SMTP do tenant aplicado antes de Mail::to(): o mailer é resolvido nessa chamada.
        TenantMailConfig::applyForTenantId((int) $document->tenant_id);

        try {
            Mail::to($email)->send(new FiscalDocumentMail($document, (string) ($customer->name ?: 'cliente'), $description, $pdf, $xml));
        } catch (\Throwable $exception) {
            Log::warning('Falha ao enviar nota fiscal ao cliente.', ['fiscal_document_id' => $document->id, 'exception' => $exception::class]);

            return $this->record($document, $origin, $userId, $email, 'Falha no envio do e-mail. Confira o SMTP da empresa e tente reenviar.');
        }

        return $this->record($document, $origin, $userId, $email, null);
    }

    public function hasSuccessfulAutomaticDelivery(FiscalDocument $document): bool
    {
        return FiscalDocumentDelivery::query()->withoutGlobalScopes()
            ->where('fiscal_document_id', $document->id)
            ->where('origin', FiscalDocumentDelivery::ORIGIN_AUTOMATIC)
            ->where('status', FiscalDocumentDelivery::STATUS_SENT)
            ->exists();
    }

    /** @return array{0: ?Customer, 1: string} */
    private function recipientFor(FiscalDocument $document): array
    {
        if ($document->documentable_type === AccountReceivable::class) {
            $receivable = AccountReceivable::query()->withoutGlobalScopes()->find($document->documentable_id);
            $customer = $receivable?->customer_id
                ? Customer::query()->withoutGlobalScopes()->where('tenant_id', $document->tenant_id)->find($receivable->customer_id)
                : null;

            return [$customer, (string) ($receivable?->description ?: 'serviço prestado')];
        }

        throw new FiscalEmissionException('Envio ao cliente disponível apenas para cobranças de contrato.');
    }

    private function record(FiscalDocument $document, string $origin, ?int $userId, ?string $email, ?string $error): FiscalDocumentDelivery
    {
        return FiscalDocumentDelivery::query()->create([
            'tenant_id' => $document->tenant_id,
            'fiscal_document_id' => $document->id,
            'email' => $email,
            'status' => $error === null ? FiscalDocumentDelivery::STATUS_SENT : FiscalDocumentDelivery::STATUS_FAILED,
            'origin' => $origin,
            'error' => $error,
            'sent_by' => $userId,
        ]);
    }
}
