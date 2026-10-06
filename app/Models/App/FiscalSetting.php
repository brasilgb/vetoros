<?php

namespace App\Models\App;

use App\Services\Fiscal\Spedy\SpedyClient;
use App\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FiscalSetting extends Model
{
    use HasFactory, Tenantable;

    public const PROVIDER_MANUAL = 'manual';

    public const PROVIDER_SPEDY = 'spedy';

    public const REGISTRATION_PENDING = 'pending';

    public const REGISTRATION_REGISTERED = 'registered';

    public const REGISTRATION_ERROR = 'error';

    public const ENVIRONMENT_HOMOLOGATION = 'homologation';

    public const ENVIRONMENT_PRODUCTION = 'production';

    protected $guarded = ['id'];

    protected $hidden = ['api_token', 'webhook_secret', 'nfce_csc'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'nfe_enabled' => 'boolean',
            'nfse_enabled' => 'boolean',
            'nfce_enabled' => 'boolean',
            'nfse_simple_option' => 'integer',
            'nfse_special_tax_regime' => 'integer',
            'default_iss_rate' => 'decimal:4',
            'api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'nfce_csc' => 'encrypted',
            'registered_at' => 'datetime',
            'certificate_expires_at' => 'datetime',
            'tax_settings_confirmed_at' => 'datetime',
        ];
    }

    public function usesNationalNfse(): bool
    {
        return $this->nfse_enabled && ($this->nfse_mode ?? 'national') === 'national';
    }

    public function isRegisteredOnSpedy(): bool
    {
        return $this->registration_status === self::REGISTRATION_REGISTERED
            && filled($this->spedy_company_id)
            && filled($this->api_token);
    }

    public function hasValidCertificate(): bool
    {
        return $this->certificate_expires_at !== null && $this->certificate_expires_at->isFuture();
    }

    /**
     * Habilitação da emissão nativa: a plataforma precisa estar configurada,
     * o tenant liberado pela administração, a empresa cadastrada, o certificado
     * vigente e o modelo de nota ativado pelo próprio tenant.
     */
    public function nativeEmissionBlocker(string $model, ?bool $tenantAllowed): ?string
    {
        $modelEnabled = match ($model) {
            SpedyClient::MODEL_NFE => $this->nfe_enabled,
            SpedyClient::MODEL_NFCE => $this->nfce_enabled,
            SpedyClient::MODEL_NFSE => $this->nfse_enabled,
            default => false,
        };

        return match (true) {
            ! SpedyClient::isConfigured() => 'A emissão fiscal automática ainda não está disponível na plataforma.',
            ! $tenantAllowed => 'A emissão fiscal automática não está liberada para esta conta.',
            ! $this->enabled => 'Ative o módulo fiscal nas configurações.',
            ! $this->isRegisteredOnSpedy() => 'Conclua o cadastro da empresa emissora nas configurações fiscais.',
            ! $modelEnabled => 'Este tipo de nota não está habilitado nas configurações fiscais.',
            ! $this->hasValidCertificate() => 'Envie um certificado digital A1 válido nas configurações fiscais.',
            $this->tax_settings_confirmed_at === null => 'Confirme nas configurações fiscais que os dados tributários foram validados pela contabilidade.',
            $model === SpedyClient::MODEL_NFSE && blank($this->nfse_taxation_type) => 'Informe o tipo de tributação da NFS-e nas configurações fiscais.',
            $model === SpedyClient::MODEL_NFCE && (blank($this->nfce_csc_id) || blank($this->nfce_csc)) => 'Informe o ID e o CSC da NFC-e nas configurações fiscais.',
            default => null,
        };
    }

    public function isProductionEnvironment(): bool
    {
        return $this->emission_environment === self::ENVIRONMENT_PRODUCTION;
    }
}
