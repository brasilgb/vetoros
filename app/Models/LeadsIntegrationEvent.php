<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de cadastro/teste/assinatura enviado ao VetorLeads. event_id é a chave de idempotência.
 * status: pending (aguardando envio), sent (aceito), failed (recusado ou esgotou as tentativas).
 */
class LeadsIntegrationEvent extends Model
{
    public const TYPE_REGISTERED = 'registered';

    public const TYPE_TRIAL_STARTED = 'trial_started';

    public const TYPE_SUBSCRIPTION_ACTIVATED = 'subscription_activated';

    public const TYPE_TRIAL_EXPIRED = 'trial_expired';

    public const TYPES = [
        self::TYPE_REGISTERED => 'Cadastro realizado',
        self::TYPE_TRIAL_STARTED => 'Início do teste',
        self::TYPE_SUBSCRIPTION_ACTIVATED => 'Assinatura ativada',
        self::TYPE_TRIAL_EXPIRED => 'Teste encerrado sem contratação',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
