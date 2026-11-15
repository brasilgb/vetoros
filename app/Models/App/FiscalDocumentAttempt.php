<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma tentativa ou atualização de uma nota fiscal (VETOR-FISCAL-AVULSA-01). Registro
 * somente de acréscimo: serve de histórico e de suporte, sem alterar a nota.
 */
class FiscalDocumentAttempt extends Model
{
    use Tenantable;

    public const ACTION_SEND = 'send';

    public const ACTION_SYNC = 'sync';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'fiscal_document_id',
        'action',
        'status',
        'provider_status',
        'error_code',
        'message',
        'user_id',
    ];

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
