<?php

namespace App\Services\Fiscal\Spedy;

use App\Models\App\Company;
use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use App\Services\Fiscal\FiscalValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cadastro do tenant como empresa emissora na conta Spedy da plataforma.
 * O tenant fornece apenas dados fiscais e o certificado; chaves e conta ficam
 * com o VetorOS.
 */
class SpedyCompanyService
{
    public function __construct(private readonly SpedyPayloadBuilder $payloads) {}

    /**
     * Cria (primeira vez) ou atualiza a empresa emissora e reenvia as
     * configurações de emissão. Usa a chave titular: comandado só pelo
     * RootAdmin (Admin\FiscalCompanyController). Idempotente: nunca cria uma
     * segunda empresa para o mesmo vínculo nem para um CNPJ já presente na conta.
     *
     * Toda falha fica gravada em registration_error, para o painel não exibir
     * apenas "Pendente" sem explicar o motivo.
     */
    public function sync(FiscalSetting $setting): FiscalSetting
    {
        $tenant = Tenant::query()->findOrFail($setting->tenant_id);
        $company = Company::query()->withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenant->id]);

        try {
            $this->guardEnvironment($setting);
            $payload = $this->payloads->company($company, $tenant, $setting);

            if (blank($setting->spedy_company_id)) {
                $this->create($setting, $payload);
            } else {
                SpedyClient::forOwner()->updateCompany($setting->spedy_company_id, $payload);
            }

            $response = SpedyClient::forOwner()
                ->updateCompanySettings($setting->spedy_company_id, $this->payloads->companySettings($setting));
            $this->recordSpedyNumbering($setting, $response);
        } catch (SpedyException|FiscalValidationException $exception) {
            $linked = filled($setting->spedy_company_id) && filled($setting->api_token) && ! $setting->hasEnvironmentMismatch();

            $setting->forceFill([
                // Falha de sincronização de um vínculo válido não desfaz o cadastro existente.
                'registration_status' => $linked ? $setting->registration_status : FiscalSetting::REGISTRATION_ERROR,
                'registration_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            throw $exception;
        }

        $setting->forceFill([
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'spedy_environment' => SpedyPlatformConfig::environment(),
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED,
            'registration_error' => null,
            'registered_at' => $setting->registered_at ?? now(),
            'last_synced_at' => now(),
        ])->save();

        return $setting;
    }

    /**
     * Reenvia só as configurações de emissão (série, ambiente, CSC). É o que o
     * tenant pode disparar ao salvar. Usa a chave titular: na Spedy, a chave da
     * empresa emissora só acessa notas; /companies/* responde 403 para ela.
     */
    public function syncSettings(FiscalSetting $setting): FiscalSetting
    {
        if (! $setting->isRegisteredOnSpedy()) {
            throw new SpedyException('A empresa emissora ainda não foi cadastrada pela administração.');
        }

        $response = SpedyClient::forOwner()
            ->updateCompanySettings($setting->spedy_company_id, $this->payloads->companySettings($setting));

        $this->recordSpedyNumbering($setting, $response);
        $this->logNumberingDivergence($setting, $response);

        return $setting;
    }

    /** Atualiza o diagnóstico somente por leitura; nunca envia nextNumber. */
    public function checkNfseNumbering(FiscalSetting $setting): FiscalSetting
    {
        if (! $setting->isRegisteredOnSpedy()) {
            throw new SpedyException('Empresa ainda não cadastrada no ambiente atual da Spedy.');
        }

        $response = SpedyClient::forOwner()->getCompanySettings($setting->spedy_company_id);
        if ($this->spedyNextNumber($response) === null) {
            throw new SpedyException('A Spedy não informou o contador atual da DPS. O último valor conhecido permanece histórico.');
        }

        $this->recordSpedyNumbering($setting, $response);

        return $setting;
    }

    /**
     * Define o próximo número da DPS na Spedy e, só depois da confirmação dela, grava o número
     * localmente. Chamado apenas quando o tenant altera a numeração: as sincronizações comuns não
     * enviam nextNumber, para não fazer a Spedy reutilizar um número já emitido.
     *
     * A confirmação é o próprio número na resposta do PUT (CompanySettingsDto.serviceInvoice.nextNumber):
     * um 2xx sem esse número, ou com outro, não prova que a Spedy vai usar o número informado.
     */
    public function syncNfseNumbering(FiscalSetting $setting, int $nextNumber): FiscalSetting
    {
        if (! $setting->isRegisteredOnSpedy()) {
            throw new SpedyException('A empresa emissora ainda não foi cadastrada pela administração.');
        }

        $response = SpedyClient::forOwner()
            ->updateCompanySettings($setting->spedy_company_id, $this->payloads->companySettings($setting, $nextNumber));

        if ($this->spedyNextNumber($response) !== $nextNumber) {
            throw new SpedyException('A Spedy não confirmou o próximo número da DPS na resposta. O número não foi gravado no VetorOS, mas a Spedy pode ter aplicado a alteração: consulte a numeração na Spedy antes de tentar de novo.');
        }

        $setting->forceFill([
            'nfse_next_number' => $nextNumber,
            'nfse_numbering_synced_at' => now(),
        ])->save();

        $this->recordSpedyNumbering($setting, $response);

        return $setting;
    }

    /**
     * Número da DPS que a Spedy devolveu na resposta do PUT de configurações, ou null se não veio.
     */
    private function spedyNextNumber(array $response): ?int
    {
        $number = data_get($response, 'serviceInvoice.nextNumber');

        return filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false && $number !== null ? (int) $number : null;
    }

    /**
     * Guarda o número que a Spedy informou e quando. Um PUT sem número na resposta não apaga o último
     * valor conhecido: fica nulo só quando ainda não havia nenhum. O bloqueio por zero depende disso.
     */
    private function recordSpedyNumbering(FiscalSetting $setting, array $response): void
    {
        $spedyNumber = $this->spedyNextNumber($response);

        if ($spedyNumber === null) {
            return;
        }

        $setting->forceFill([
            'nfse_spedy_next_number' => $spedyNumber,
            'nfse_spedy_checked_at' => now(),
        ])->save();
    }

    /**
     * Sincronização comum não envia a numeração. Se a Spedy responder outro número que o gravado
     * localmente, o registro fica para diagnóstico: a emissão usa o número da Spedy, não o daqui.
     */
    private function logNumberingDivergence(FiscalSetting $setting, array $response): void
    {
        if ($setting->nfse_next_number === null) {
            return;
        }

        $spedyNumber = $this->spedyNextNumber($response);

        if ($spedyNumber === $setting->nfse_next_number) {
            return;
        }

        Log::warning('Numeração da DPS divergente da Spedy.', [
            'tenant_id' => $setting->tenant_id,
            'local_next_number' => $setting->nfse_next_number,
            'spedy_next_number' => $spedyNumber,
        ]);
    }

    /**
     * O certificado e a senha vão direto para a Spedy e não são guardados no
     * VetorOS; guardamos apenas titular e validade para alertas.
     */
    public function uploadCertificate(FiscalSetting $setting, UploadedFile $file, string $password): FiscalSetting
    {
        if (! $setting->isRegisteredOnSpedy()) {
            throw new SpedyException('Conclua o cadastro da empresa emissora antes de enviar o certificado.');
        }

        $certificate = SpedyClient::forOwner()->uploadCertificate(
            $setting->spedy_company_id,
            (string) $file->get(),
            'certificado.pfx',
            $password,
        );

        // Um HTTP 2xx sozinho não confirma que o certificado foi aceito e ativado.
        if (! is_string($certificate['id'] ?? null) || blank($certificate['id']) || ($certificate['isActive'] ?? null) !== true) {
            throw new SpedyException('O emissor não confirmou a ativação do certificado. Consulte a situação com o suporte antes de tentar novamente.');
        }

        try {
            $expiresAt = filled($certificate['expirationAt'] ?? null)
                ? Carbon::parse($certificate['expirationAt']) : null;
        } catch (\Throwable) {
            throw new SpedyException('O emissor retornou uma validade inválida para o certificado. Contate o suporte.');
        }

        if ($expiresAt === null || $expiresAt->isPast()) {
            throw new SpedyException('O emissor não confirmou um certificado vigente. Confira o arquivo e consulte o suporte.');
        }

        $setting->forceFill([
            'certificate_subject' => is_string($certificate['subject'] ?? null) ? mb_substr($certificate['subject'], 0, 255) : null,
            'certificate_expires_at' => $expiresAt,
        ])->save();

        return $setting;
    }

    private function create(FiscalSetting $setting, array $payload): void
    {
        // Trava a linha: dois cliques simultâneos não podem criar duas empresas.
        DB::transaction(function () use ($setting, $payload) {
            $locked = FiscalSetting::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($setting->id);

            if (filled($locked->spedy_company_id)) {
                $setting->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $owner = SpedyClient::forOwner();

            // Um POST anterior pode ter criado a empresa e caído por timeout. A chave de uma
            // empresa existente vem ofuscada nas consultas, então não há como religá-la: avisar.
            $existing = $owner->findCompanyByFederalTaxNumber($payload['federalTaxNumber']);
            if ($existing !== null) {
                throw new SpedyException(sprintf(
                    'O CNPJ já está cadastrado na conta Spedy (%s, empresa %s). A chave dessa empresa só é exibida na criação e não pode ser recuperada pela API: exclua o cadastro duplicado no painel da Spedy ou contate o suporte da Spedy antes de cadastrar de novo.',
                    SpedyPlatformConfig::environment(),
                    (string) ($existing['id'] ?? '?'),
                ));
            }

            $response = $owner->createCompany($payload);
            $companyId = $response['id'] ?? null;
            $apiKey = $response['apiCredentials']['apiKey'] ?? null;

            if (blank($companyId) || blank($apiKey)) {
                throw new SpedyException('O serviço de emissão fiscal não retornou as credenciais da empresa. Contate o suporte.');
            }

            // A chave só é exibida uma vez pela Spedy: gravar antes de qualquer outra chamada.
            $locked->forceFill([
                'spedy_company_id' => $companyId,
                'api_token' => $apiKey,
                'spedy_environment' => SpedyPlatformConfig::environment(),
                'provider' => FiscalSetting::PROVIDER_SPEDY,
            ])->save();

            $setting->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Sandbox e produção são contas Spedy separadas. Um vínculo de sandbox é descartado
     * quando o RootAdmin cadastra a empresa em produção; um vínculo de produção nunca é
     * descartado aqui, pois a chave da empresa não pode ser recuperada.
     */
    private function guardEnvironment(FiscalSetting $setting): void
    {
        if (! $setting->hasEnvironmentMismatch()) {
            return;
        }

        if ($setting->spedy_environment === 'production') {
            throw new SpedyException('O cadastro desta empresa pertence à conta de produção da Spedy, mas a plataforma está em sandbox. Volte a plataforma para produção; o vínculo de produção não será descartado.');
        }

        $setting->forceFill([
            'spedy_company_id' => null,
            'api_token' => null,
            'spedy_environment' => null,
            'registration_status' => FiscalSetting::REGISTRATION_PENDING,
            'certificate_subject' => null,
            'certificate_expires_at' => null,
            'production_released_at' => null,
            'production_released_by' => null,
            'registered_at' => null,
            'last_synced_at' => null,
        ])->save();
    }
}
