<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Desativado (VETOROS-PERFORMANCE-OS-03): o VetorOS não envia mais e-mail automático de OS
 * criada. A classe continua existindo só para que eventuais jobs antigos ainda na fila (ou em
 * failed_jobs, se reprocessados) sejam descartados sem enviar nada e sem erro de classe ausente.
 */
class SendOrderCreatedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        Log::info('Job legado de e-mail de OS criada descartado: envio automático desativado.', ['order_id' => $this->orderId]);
    }
}
