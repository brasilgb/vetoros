<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Emitente próprio da plataforma (ABrasil Sistemas) para as NFS-e do SaaS.
 * Independente dos dados fiscais das empresas clientes; a chave da empresa
 * emissora na Spedy fica criptografada em `api_token`.
 */
class AdminFiscalSetting extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['api_token', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'default_iss_rate' => 'decimal:4',
            'registered_at' => 'datetime',
            'production_released_at' => 'datetime',
            'certificate_expires_at' => 'datetime',
            'tax_settings_confirmed_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOrCreate([], ['enabled' => false, 'provider' => 'spedy']);
    }

    public function isRegisteredOnSpedy(): bool
    {
        return $this->registration_status === 'registered' && filled($this->spedy_company_id) && filled($this->api_token);
    }

    public function isProductionEnvironment(): bool
    {
        return $this->emission_environment === 'production';
    }
}
