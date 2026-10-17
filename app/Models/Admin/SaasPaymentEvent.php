<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

/** Notificação recebida de um provedor de pagamento; a chave única impede processar o mesmo evento duas vezes. */
class SaasPaymentEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
