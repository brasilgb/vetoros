<?php

namespace App\Services\Fiscal\Spedy;

use App\Models\App\Company;
use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * RootAdmin (Admin\\FiscalCompanyController). Idempotente.
     */
    public function sync(FiscalSetting $setting): FiscalSetting
    {
        $tenant = Tenant::query()->findOrFail($setting->tenant_id);
        $company = Company::query()->withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenant->id]);
        $payload = $this->payloads->company($company, $tenant, $setting);

        try {
            if (blank($setting->spedy_company_id)) {
                $this->create($setting, $payload);
            } else {
                SpedyClient::forOwner()->updateCompany($setting->spedy_company_id, $payload);
            }

            SpedyClient::forCompany($setting->api_token)
                ->updateCompanySettings($setting->spedy_company_id, $this->payloads->companySettings($setting));
        } catch (SpedyException $exception) {
            $setting->forceFill([
                'registration_status' => filled($setting->spedy_company_id) && filled($setting->api_token)
                    ? $setting->registration_status
                    : FiscalSetting::REGISTRATION_ERROR,
                'registration_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $setting->forceFill([
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED,
            'registration_error' => null,
            'registered_at' => $setting->registered_at ?? now(),
        ])->save();

        return $setting;
    }

    /**
     * Reenvia só as configurações de emissão (série, ambiente, CSC) com a
     * chave da própria empresa. É o que o tenant pode disparar ao salvar.
     */
    public function syncSettings(FiscalSetting $setting): FiscalSetting
    {
        if (! $setting->isRegisteredOnSpedy()) {
            throw new SpedyException('A empresa emissora ainda não foi cadastrada pela administração.');
        }

        SpedyClient::forCompany($setting->api_token)
            ->updateCompanySettings($setting->spedy_company_id, $this->payloads->companySettings($setting));

        return $setting;
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

        $certificate = SpedyClient::forCompany($setting->api_token)->uploadCertificate(
            $setting->spedy_company_id,
            (string) $file->get(),
            'certificado.pfx',
            $password,
        );

        $setting->forceFill([
            'certificate_subject' => isset($certificate['subject']) ? mb_substr((string) $certificate['subject'], 0, 255) : null,
            'certificate_expires_at' => isset($certificate['expirationAt']) ? Carbon::parse($certificate['expirationAt']) : null,
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

            $response = SpedyClient::forOwner()->createCompany($payload);
            $companyId = $response['id'] ?? null;
            $apiKey = $response['apiCredentials']['apiKey'] ?? null;

            if (blank($companyId) || blank($apiKey)) {
                throw new SpedyException('O serviço de emissão fiscal não retornou as credenciais da empresa. Contate o suporte.');
            }

            // A chave só é exibida uma vez pela Spedy: gravar antes de qualquer outra chamada.
            $locked->forceFill([
                'spedy_company_id' => $companyId,
                'api_token' => $apiKey,
                'provider' => FiscalSetting::PROVIDER_SPEDY,
            ])->save();

            $setting->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
