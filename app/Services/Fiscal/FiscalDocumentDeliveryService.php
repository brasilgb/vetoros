<?php

namespace App\Services\Fiscal;

use App\Mail\MaintenanceInvoiceMail;
use App\Models\App\AccountReceivable;
use App\Models\App\Customer;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalDocumentDelivery;
use App\Models\App\MaintenanceContract;
use App\Support\Fiscal\FiscalDocumentLinks;
use App\Support\TenantMailConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envio da fatura com a NFS-e autorizada ao cliente final, pelo SMTP da empresa do tenant, com
 * links assinados e temporários para o PDF/XML (VETOR-FISCAL-05.3).
 * Cada tentativa vira um registro (enviado ou falha, com motivo seguro). Nunca emite nota:
 * reenvio e falha de e-mail não tocam no documento fiscal.
 */
class FiscalDocumentDeliveryService
{
    public function send(FiscalDocument $document, string $origin, ?int $userId = null): FiscalDocumentDelivery
    {
        // Nunca anuncia nota que ainda não foi autorizada.
        if ($document->status !== FiscalDocument::STATUS_AUTHORIZED) {
            throw new FiscalEmissionException('Somente notas autorizadas podem ser enviadas ao cliente.');
        }

        [$receivable, $contract, $customer] = $this->contextFor($document);
        $email = trim((string) $customer?->email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->record($document, $origin, $userId, $email ?: null, 'O cliente não tem e-mail válido no cadastro.');
        }

        if (! TenantMailConfig::hasConfiguredForTenantId((int) $document->tenant_id)) {
            return $this->record($document, $origin, $userId, $email, 'O SMTP da empresa não está configurado em Sistema e módulos.');
        }

        $links = FiscalDocumentLinks::for($document);

        // SMTP do tenant aplicado antes de Mail::to(): o mailer é resolvido nessa chamada.
        TenantMailConfig::applyForTenantId((int) $document->tenant_id);

        try {
            Mail::to($email)->send(new MaintenanceInvoiceMail($document, $receivable, $contract, $customer, $links['pdf'], $links['xml'], $links['expires_at']));
        } catch (\Throwable $exception) {
            Log::warning('Falha ao enviar fatura com NFS-e ao cliente.', ['fiscal_document_id' => $document->id, 'exception' => $exception::class]);

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

    /** @return array{0: AccountReceivable, 1: MaintenanceContract, 2: ?Customer} */
    private function contextFor(FiscalDocument $document): array
    {
        $receivable = $document->documentable_type === AccountReceivable::class
            ? AccountReceivable::query()->withoutGlobalScopes()->where('tenant_id', $document->tenant_id)->find($document->documentable_id)
            : null;
        $contract = $receivable?->maintenanceContract();

        if (! $receivable || ! $contract) {
            throw new FiscalEmissionException('Envio ao cliente disponível apenas para cobranças de contrato.');
        }

        $customer = $receivable->customer_id
            ? Customer::query()->withoutGlobalScopes()->where('tenant_id', $document->tenant_id)->find($receivable->customer_id)
            : null;

        return [$receivable, $contract, $customer];
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
