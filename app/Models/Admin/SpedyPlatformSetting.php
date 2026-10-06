<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

/** Configuração central da conta Spedy da plataforma (linha única, só RootAdmin). */
class SpedyPlatformSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['owner_api_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'owner_api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'owner_api_key_rotated_at' => 'datetime',
            'webhook_configured_at' => 'datetime',
            'last_diagnostic_at' => 'datetime',
            'last_diagnostic_ok' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOrCreate([]);
    }
}
