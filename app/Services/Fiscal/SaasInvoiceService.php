<?php

namespace App\Services\Fiscal;

use App\Jobs\StoreFiscalDocumentFiles;
use App\Mail\SaasFiscalDocumentMail;
use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\AdminFiscalDocumentDelivery;
use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\Plan;
use App\Models\App\FiscalDocument;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Observers\CompanyIdentityObserver;
use App\Services\Fiscal\Spedy\SpedyClient;
use App\Services\Fiscal\Spedy\SpedyException;
use App\Services\Fiscal\Spedy\SpedyInvoiceState;
use App\Services\Fiscal\Spedy\SpedyPayloadBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * NFS-e da própria plataforma (ABrasil Sistemas → cliente contratante),
 * emitida manualmente pelo RootAdmin a partir de um pagamento aprovado.
 *
 * Emitente separado dos tenants (AdminFiscalSetting), documentos separados
 * (AdminFiscalDocument). O valor é sempre o do pagamento, nunca o da tabela
 * de planos. Uma nota ativa por pagamento.
 */
class SaasInvoiceService
{
    private const TAX_REGIMES = [
        '1' => 'simplesNacional',
        '2' => 'simplesNacionalExcessoSublimite',
        '3' => 'regimeNormal',
        '4' => 'simplesNacionalMEI',
    ];

    private const BLOCKING = [FiscalDocument::STATUS_PROCESSING, FiscalDocument::STATUS_CONTINGENCY, FiscalDocument::STATUS_AUTHORIZED];

    // ---- Emitente da plataforma ----------------------------------------

    public function issuerProblems(AdminFiscalSetting $issuer): array
    {
        $problems = [];
        $cnpj = $this->digits($issuer->cnpj);

        if (strlen($cnpj) !== 14) {
            $problems[] = 'Informe o CNPJ do emitente (14 dígitos).';
        }
        foreach (['legal_name' => 'a razão social', 'street' => 'o logradouro', 'number' => 'o número', 'district' => 'o bairro', 'zip_code' => 'o CEP', 'city' => 'a cidade', 'state' => 'a UF', 'municipal_registration' => 'a inscrição municipal', 'service_city_code' => 'o código IBGE do município', 'service_list_item' => 'o item da lista de serviços (LC 116)', 'nfse_taxation_type' => 'o tipo de tributação da NFS-e'] as $field => $label) {
            if (blank($issuer->{$field})) {
                $problems[] = "Informe {$label} do emitente.";
            }
        }
        if (mb_strlen(trim((string) $issuer->legal_name)) > SpedyPayloadBuilder::MAX_LEGAL_NAME) {
            $problems[] = sprintf('A razão social do emitente passa de %d caracteres; use a forma abreviada do cadastro na Receita.', SpedyPayloadBuilder::MAX_LEGAL_NAME);
        }
        if (mb_strlen(trim((string) $issuer->trade_name)) > SpedyPayloadBuilder::MAX_ISSUER_NAME) {
            $problems[] = sprintf('O nome fantasia do emitente passa de %d caracteres, limite do emissor.', SpedyPayloadBuilder::MAX_ISSUER_NAME);
        }
        if (mb_strlen(trim((string) $issuer->email)) > SpedyPayloadBuilder::MAX_ISSUER_EMAIL) {
            $problems[] = sprintf('O e-mail do emitente passa de %d caracteres, limite do emissor.', SpedyPayloadBuilder::MAX_ISSUER_EMAIL);
        }
        if ($issuer->default_iss_rate === null) {
            $problems[] = 'Informe a alíquota de ISS do emitente.';
        }
        if (! isset(self::TAX_REGIMES[(string) $issuer->tax_regime])) {
            $problems[] = 'Selecione o regime tributário do emitente.';
        }

        return $problems;
    }

    /** Bloqueio da emissão do SaaS, sem chamar a Spedy. */
    public function blocker(AdminFiscalSetting $issuer): ?string
    {
        return match (true) {
            ! SpedyClient::isConfigured() => 'Integração Spedy não configurada.',
            ! $issuer->enabled => 'Emissão das notas do SaaS desabilitada.',
            $this->issuerProblems($issuer) !== [] => 'Cadastro do emitente incompleto.',
            ! $issuer->isRegisteredOnSpedy() => 'Emitente ainda não cadastrado na Spedy.',
            $issuer->certificate_expires_at === null || $issuer->certificate_expires_at->isPast() => 'Envie um certificado A1 válido do emitente.',
            $issuer->tax_settings_confirmed_at === null => 'Confirme os dados tributários do emitente.',
            $issuer->isProductionEnvironment() && $issuer->production_released_at === null => 'Aprove a emissão em produção após a homologação.',
            default => null,
        };
    }

    public function registerIssuer(AdminFiscalSetting $issuer): AdminFiscalSetting
    {
        if ($problems = $this->issuerProblems($issuer)) {
            throw new FiscalValidationException($problems);
        }

        $payload = array_filter([
            'name' => trim((string) ($issuer->trade_name ?: $issuer->legal_name)),
            'legalName' => trim((string) $issuer->legal_name),
            'federalTaxNumber' => $this->digits($issuer->cnpj),
            'cityTaxNumber' => $this->digits($issuer->municipal_registration) ?: null,
            'email' => $issuer->email ?: null,
            'taxRegime' => self::TAX_REGIMES[(string) $issuer->tax_regime],
            'address' => array_filter([
                'street' => Str::limit((string) $issuer->street, 100, ''),
                'number' => Str::limit((string) $issuer->number, 10, ''),
                'district' => Str::limit((string) $issuer->district, 100, ''),
                'postalCode' => $this->digits($issuer->zip_code),
                'additionalInformation' => $issuer->complement ?: null,
                'city' => array_filter([
                    'code' => $this->digits($issuer->service_city_code) ?: null,
                    'name' => $issuer->city,
                    'state' => Str::lower((string) $issuer->state),
                ]),
            ]),
        ], fn ($value) => $value !== null);

        try {
            DB::transaction(function () use ($issuer, $payload) {
                $locked = AdminFiscalSetting::query()->lockForUpdate()->findOrFail($issuer->id);

                if (blank($locked->spedy_company_id)) {
                    $response = SpedyClient::forOwner()->createCompany($payload);

                    if (blank($response['id'] ?? null) || blank($response['apiCredentials']['apiKey'] ?? null)) {
                        throw new SpedyException('A Spedy não retornou as credenciais do emitente.');
                    }

                    $locked->forceFill(['spedy_company_id' => $response['id'], 'api_token' => $response['apiCredentials']['apiKey'], 'provider' => 'spedy'])->save();
                } else {
                    SpedyClient::forOwner()->updateCompany($locked->spedy_company_id, $payload);
                }

                $issuer->setRawAttributes($locked->getAttributes(), true);
            });

            SpedyClient::forCompany($issuer->api_token)->updateCompanySettings($issuer->spedy_company_id, [
                'serviceInvoice' => array_filter([
                    'series' => $issuer->default_nfse_series ?: '1',
                    'environmentType' => $issuer->isProductionEnvironment() ? 'production' : 'simulation',
                    'issueType' => $issuer->nfse_mode === 'municipal' ? 'normal' : 'annfs',
                ]),
            ]);
        } catch (SpedyException $exception) {
            $issuer->forceFill(['registration_error' => $exception->getMessage()])->save();

            throw $exception;
        }

        $issuer->forceFill(['registration_status' => 'registered', 'registration_error' => null, 'registered_at' => $issuer->registered_at ?? now()])->save();

        return $issuer;
    }

    public function uploadIssuerCertificate(AdminFiscalSetting $issuer, UploadedFile $file, string $password): AdminFiscalSetting
    {
        if (! $issuer->isRegisteredOnSpedy()) {
            throw new SpedyException('Cadastre o emitente na Spedy antes de enviar o certificado.');
        }

        $certificate = SpedyClient::forCompany($issuer->api_token)->uploadCertificate($issuer->spedy_company_id, (string) $file->get(), 'certificado.pfx', $password);

        $issuer->forceFill([
            'certificate_subject' => isset($certificate['subject']) ? mb_substr((string) $certificate['subject'], 0, 255) : null,
            'certificate_expires_at' => isset($certificate['expirationAt']) ? Carbon::parse($certificate['expirationAt']) : null,
        ])->save();

        return $issuer;
    }

    // ---- Vínculo financeiro --------------------------------------------

    /** Plano e período de referência sugeridos a partir do pagamento aprovado. */
    public function paymentContext(Payment $payment): array
    {
        $reference = json_decode((string) data_get($payment->raw_response, 'external_reference'), true);
        $plan = is_array($reference) && isset($reference['plan_id']) ? Plan::query()->find($reference['plan_id']) : null;
        $paidAt = Carbon::parse(data_get($payment->raw_response, 'date_approved') ?? $payment->updated_at ?? $payment->created_at);
        $months = $plan?->billingMonths() ?: 0;

        return [
            'plan' => $plan,
            'plan_name' => $plan?->name,
            'billing_months' => $months,
            'paid_at' => $paidAt,
            'suggested_start' => $paidAt->copy()->startOfDay(),
            'suggested_end' => $months > 0 ? $paidAt->copy()->addMonths($months)->subDay()->startOfDay() : null,
        ];
    }

    // ---- Emissão ---------------------------------------------------------

    public function emit(Payment $payment, Carbon $start, Carbon $end, ?string $description, ?int $userId): AdminFiscalDocument
    {
        $issuer = AdminFiscalSetting::current();

        if ($blocker = $this->blocker($issuer)) {
            throw new FiscalEmissionException($blocker);
        }
        if ($payment->status !== 'approved' || (float) $payment->amount <= 0) {
            throw new FiscalEmissionException('Só pagamentos aprovados e com valor podem gerar nota.');
        }
        if ($end->lt($start)) {
            throw new FiscalEmissionException('O fim do período de referência deve ser posterior ao início.');
        }

        $tenant = Tenant::query()->findOrFail($payment->tenant_id);
        $receiverProblems = $this->receiverProblems($tenant);
        if ($receiverProblems !== []) {
            throw new FiscalValidationException($receiverProblems);
        }

        $context = $this->paymentContext($payment);

        // Trava o pagamento: uma nota ativa por operação faturada.
        $document = DB::transaction(function () use ($payment, $tenant, $start, $end, $description, $userId, $context, $issuer) {
            Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            $existing = AdminFiscalDocument::query()
                ->where('payment_id', $payment->id)
                ->where('provider', 'spedy')
                ->whereIn('status', self::BLOCKING)
                ->first();

            if ($existing) {
                throw new FiscalEmissionException($existing->status === FiscalDocument::STATUS_AUTHORIZED
                    ? 'Este pagamento já possui NFS-e autorizada. Para substituí-la, cancele-a antes.'
                    : 'Já existe uma NFS-e em processamento para este pagamento.');
            }

            $text = $description ?: $this->defaultDescription($issuer, $context['plan_name'], $start, $end);
            $attributes = [
                'status' => FiscalDocument::STATUS_PROCESSING,
                'provider_status' => null,
                'error_message' => null,
                'amount' => round((float) $payment->amount, 2),
                'description' => Str::limit($text, 500, ''),
                'reference_start' => $start->toDateString(),
                'reference_end' => $end->toDateString(),
                'registered_by' => $userId,
                'environment' => $issuer->emission_environment,
            ];

            // Rejeitada/falha: corrige reenviando o mesmo integrationId (a Spedy atualiza a nota).
            $retry = AdminFiscalDocument::query()
                ->where('payment_id', $payment->id)
                ->where('provider', 'spedy')
                ->whereIn('status', [FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_FAILED])
                ->whereNotNull('integration_id')
                ->latest('id')
                ->first();

            if ($retry) {
                $retry->forceFill($attributes)->save();

                return $retry;
            }

            $document = AdminFiscalDocument::query()->create([
                ...$attributes,
                'tenant_id' => $tenant->id,
                'payment_id' => $payment->id,
                'type' => 'nfse',
                'provider' => 'spedy',
                'integration_id' => (string) Str::uuid(),
            ]);

            $payment->forceFill(['admin_fiscal_document_id' => $document->id])->save();

            return $document;
        });

        $payload = [
            'integrationId' => $document->integration_id,
            'description' => $document->description,
            'federalServiceCode' => trim((string) $issuer->service_list_item),
            'taxationType' => $issuer->nfse_taxation_type,
            'sendEmailToCustomer' => false,
            'receiver' => $this->receiver($tenant),
            'total' => [
                'invoiceAmount' => (float) $document->amount,
                'issRate' => (float) $issuer->default_iss_rate,
            ],
            'location' => ['code' => (int) $this->digits($issuer->service_city_code)],
        ];

        $document->forceFill(['request_payload' => SpedyInvoiceState::redactPayload($payload), 'submitted_at' => now()])->save();
        FiscalAdminAudit::record('saas.invoice_requested', $tenant->id, $document, ['payment_id' => $payment->id, 'amount' => (float) $document->amount]);

        try {
            $invoice = SpedyClient::forCompany($issuer->api_token)->createInvoice(SpedyClient::MODEL_NFSE, $payload);
        } catch (SpedyException $exception) {
            $document->forceFill([
                'status' => $exception->isTransient() ? FiscalDocument::STATUS_PROCESSING : FiscalDocument::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            if (! $exception->isTransient()) {
                throw $exception;
            }

            return $document->refresh();
        }

        $document->forceFill(['provider_reference' => $invoice['id'] ?? null])->save();

        return $this->applyInvoice($document, $invoice);
    }

    public function applyInvoice(AdminFiscalDocument $document, array $invoice): AdminFiscalDocument
    {
        $status = SpedyInvoiceState::localStatus($invoice['status'] ?? null);

        return DB::transaction(function () use ($document, $invoice, $status) {
            $document = AdminFiscalDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($status === null || SpedyInvoiceState::isRegression($document->status, $status)) {
                return $document;
            }

            $previous = $document->status;
            $document->forceFill(SpedyInvoiceState::attributes($document, $invoice, $status))->save();

            if (in_array($status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true)
                && ($previous !== $status || blank($document->xml_path))) {
                StoreFiscalDocumentFiles::dispatch($document->id, saas: true)->afterCommit();
            }

            return $document;
        });
    }

    public function refresh(AdminFiscalDocument $document): AdminFiscalDocument
    {
        $client = $this->issuerClient();

        if (blank($document->provider_reference)) {
            $invoice = filled($document->integration_id) ? $client->findInvoiceByIntegrationId(SpedyClient::MODEL_NFSE, $document->integration_id) : null;

            return $invoice ? $this->applyInvoice($document, $invoice) : $document;
        }

        return $this->applyInvoice($document, $client->getInvoice(SpedyClient::MODEL_NFSE, $document->provider_reference));
    }

    public function cancel(AdminFiscalDocument $document, string $reason): AdminFiscalDocument
    {
        $reason = trim($reason);

        if ($document->status !== FiscalDocument::STATUS_AUTHORIZED) {
            throw new FiscalEmissionException('Somente notas autorizadas podem ser canceladas.');
        }
        if (mb_strlen($reason) < 15 || mb_strlen($reason) > 255) {
            throw new FiscalEmissionException('A justificativa do cancelamento deve ter entre 15 e 255 caracteres.');
        }

        $invoice = $this->issuerClient()->cancelInvoice(SpedyClient::MODEL_NFSE, $document->provider_reference, $reason);
        $document->forceFill(['cancel_reason' => $reason])->save();
        FiscalAdminAudit::record('saas.invoice_cancel_requested', $document->tenant_id, $document);

        return $this->applyInvoice($document, $invoice);
    }

    public function download(AdminFiscalDocument $document, string $format): string
    {
        $format = $format === 'xml' ? 'xml' : 'pdf';

        if (! in_array($document->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true) || blank($document->provider_reference)) {
            throw new FiscalEmissionException('Arquivo disponível somente para notas autorizadas.');
        }

        $path = $format === 'xml' ? $document->xml_path : $document->pdf_path;
        if (filled($path) && Storage::disk('fiscal')->exists($path)) {
            return (string) Storage::disk('fiscal')->get($path);
        }

        return $this->issuerClient()->downloadInvoiceFile(SpedyClient::MODEL_NFSE, $document->provider_reference, $format)->body();
    }

    public function storeFiles(AdminFiscalDocument $document): AdminFiscalDocument
    {
        if (! in_array($document->status, [FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::STATUS_CANCELLED], true) || blank($document->provider_reference)) {
            return $document;
        }

        $client = $this->issuerClient();
        // Pasta separada das notas dos clientes.
        $base = sprintf('saas/%s/%d-%s', ($document->issued_at ?? now())->format('Y/m'), $document->id, $document->status);
        $xml = $client->downloadInvoiceFile(SpedyClient::MODEL_NFSE, $document->provider_reference, 'xml')->body();
        $pdf = $client->downloadInvoiceFile(SpedyClient::MODEL_NFSE, $document->provider_reference, 'pdf')->body();

        Storage::disk('fiscal')->put("{$base}.xml", $xml);
        Storage::disk('fiscal')->put("{$base}.pdf", $pdf);

        $document->forceFill(['xml_path' => "{$base}.xml", 'pdf_path' => "{$base}.pdf", 'xml_sha256' => hash('sha256', $xml)])->save();

        return $document;
    }

    /**
     * Envia o PDF autorizado por e-mail e registra a entrega. Uma falha de envio
     * fica registrada e nunca dispara nova emissão.
     */
    public function sendByEmail(AdminFiscalDocument $document, string $email, ?int $userId): AdminFiscalDocumentDelivery
    {
        if ($document->status !== FiscalDocument::STATUS_AUTHORIZED) {
            throw new FiscalEmissionException('Somente notas autorizadas podem ser enviadas.');
        }

        try {
            $pdf = $this->download($document, 'pdf');
            Mail::to($email)->send(new SaasFiscalDocumentMail($document->loadMissing('tenant'), $pdf));
            $status = AdminFiscalDocumentDelivery::STATUS_SENT;
            $error = null;
        } catch (\Throwable $exception) {
            Log::warning('Falha ao enviar NFS-e do SaaS por e-mail.', ['admin_fiscal_document_id' => $document->id, 'exception' => $exception::class]);
            $status = AdminFiscalDocumentDelivery::STATUS_FAILED;
            $error = Str::limit($exception->getMessage(), 1000, '');
        }

        $delivery = AdminFiscalDocumentDelivery::query()->create([
            'admin_fiscal_document_id' => $document->id,
            'email' => $email,
            'status' => $status,
            'error' => $error,
            'sent_by' => $userId,
            'created_at' => now(),
        ]);

        FiscalAdminAudit::record('saas.invoice_emailed', $document->tenant_id, $document, ['status' => $status]);

        return $delivery;
    }

    public function receiverProblems(Tenant $tenant): array
    {
        $problems = [];

        if (! in_array(strlen($this->digits($tenant->cnpj)), [11, 14], true)) {
            $problems[] = 'O cliente contratante não tem CPF/CNPJ válido no cadastro.';
        }
        if (blank($tenant->company) && blank($tenant->name)) {
            $problems[] = 'O cliente contratante não tem nome no cadastro.';
        }

        return $problems;
    }

    /**
     * Tomador exatamente como vai para a Spedy, para conferência antes da emissão B2B,
     * com o aviso de nome cortado e a última mudança de CNPJ/razão social auditada.
     *
     * @return array<string, mixed>
     */
    public function receiverPreview(Tenant $tenant): array
    {
        $receiver = $this->receiver($tenant);
        $lastChange = FiscalAdminAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', CompanyIdentityObserver::ACTION)
            ->latest('id')
            ->with('user:id,name')
            ->first();

        return [
            'name' => $receiver['name'] ?? '',
            'federal_tax_number' => $receiver['federalTaxNumber'] ?? '',
            'email' => $receiver['email'] ?? null,
            'address' => $receiver['address'] ?? null,
            'problems' => $this->receiverProblems($tenant),
            'identity_changed_at' => $lastChange?->created_at?->toIso8601String(),
            'identity_changed_by' => $lastChange?->user?->name,
            'identity_changes' => $lastChange ? array_keys((array) data_get($lastChange->data, 'changes', [])) : [],
        ];
    }

    private function receiver(Tenant $tenant): array
    {
        $address = array_filter([
            'street' => Str::limit((string) $tenant->street, 100, ''),
            'number' => Str::limit((string) $tenant->number, 10, ''),
            'district' => Str::limit((string) $tenant->district, 100, ''),
            'postalCode' => $this->digits($tenant->zip_code),
            'city' => array_filter(['name' => $tenant->city, 'state' => Str::upper((string) $tenant->state)]),
        ]);

        return array_filter([
            // NFS-e: o contrato da Spedy não limita receiver.name; o nome do tomador vai inteiro.
            'name' => trim((string) ($tenant->company ?: $tenant->name)),
            'federalTaxNumber' => $this->digits($tenant->cnpj),
            'email' => $tenant->email ?: null,
            'address' => filled($tenant->street) && filled($tenant->zip_code) ? $address : null,
        ]);
    }

    private function defaultDescription(AdminFiscalSetting $issuer, ?string $planName, Carbon $start, Carbon $end): string
    {
        $base = trim((string) ($issuer->default_service_description ?: 'Licença de uso do software VetorOS'));

        return sprintf('%s%s — referência %s a %s', $base, $planName ? " — plano {$planName}" : '', $start->format('d/m/Y'), $end->format('d/m/Y'));
    }

    private function issuerClient(): SpedyClient
    {
        return SpedyClient::forCompany(AdminFiscalSetting::current()->api_token);
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
