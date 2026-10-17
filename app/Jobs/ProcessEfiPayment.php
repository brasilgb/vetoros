<?php

namespace App\Jobs;

use App\Models\Admin\SaasPaymentEvent;
use App\Models\App\Payment;
use App\Services\Payments\Efi\EfiException;
use App\Services\Payments\SaasPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Confirma uma transação da Efí consultando a API (VETOR-SAAS-EFI-02). Falha temporária volta para a
 * fila com espera crescente; a conciliação periódica cobre o que esgotar as tentativas.
 */
class ProcessEfiPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $paymentId, public readonly ?int $eventId = null) {}

    public function handle(SaasPaymentService $payments): void
    {
        $event = $this->eventId ? SaasPaymentEvent::query()->find($this->eventId) : null;
        $event?->increment('attempts');

        $payment = Payment::query()->find($this->paymentId);
        if (! $payment) {
            $event?->forceFill(['status' => SaasPaymentEvent::STATUS_IGNORED, 'processed_at' => now()])->save();

            return;
        }

        try {
            $payments->reconcile($payment);
            $event?->forceFill(['status' => SaasPaymentEvent::STATUS_PROCESSED, 'error' => null, 'processed_at' => now()])->save();
        } catch (EfiException $exception) {
            $event?->forceFill(['status' => SaasPaymentEvent::STATUS_FAILED, 'error' => Str::limit($exception->getMessage(), 500, '')])->save();

            if ($exception->temporary) {
                throw $exception;
            }
        }
    }
}
