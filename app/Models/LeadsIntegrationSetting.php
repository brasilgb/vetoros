<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuração do acompanhamento de cadastro com o VetorLeads (linha única). O token é gravado
 * cifrado e nunca sai do servidor (oculto em JSON e na tela).
 */
class LeadsIntegrationSetting extends Model
{
    public const MODE_MANUAL = 'manual';

    public const MODE_API = 'api';

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
            'last_sync_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['mode' => self::MODE_MANUAL]);
    }

    /** Modo API com endpoint e token: só então eventos são gravados e enviados. */
    public function apiReady(): bool
    {
        return $this->mode === self::MODE_API && filled($this->endpoint_url) && filled($this->token);
    }
}
