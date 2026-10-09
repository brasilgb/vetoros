<?php

namespace App\Models\App;

use App\Models\User;
use App\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Envio de uma nota fiscal ao cliente final. Independente da emissão: falhar aqui não gera outra nota. */
class FiscalDocumentDelivery extends Model
{
    use Tenantable;

    public const UPDATED_AT = null;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const ORIGIN_AUTOMATIC = 'automatic';

    public const ORIGIN_MANUAL = 'manual';

    protected $fillable = ['tenant_id', 'fiscal_document_id', 'email', 'status', 'origin', 'error', 'sent_by'];

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
