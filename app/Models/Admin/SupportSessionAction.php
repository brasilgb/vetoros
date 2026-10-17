<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ação de escrita feita (ou bloqueada) durante uma sessão de suporte. Sem corpo da requisição. */
class SupportSessionAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['support_session_id', 'method', 'route_name', 'path', 'status', 'blocked'];

    protected $casts = [
        'blocked' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SupportSession::class, 'support_session_id');
    }
}
