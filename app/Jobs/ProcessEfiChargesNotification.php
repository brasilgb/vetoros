<?php

namespace App\Jobs;

use App\Models\Admin\SaasPaymentEvent;
use App\Services\Payments\Efi\EfiException;
use App\Services\Payments\SaasPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Lê na API Cobranças os eventos de uma notificação e concilia cada cobrança (VETOR-SAAS-EFI-02). */
class ProcessEfiChargesNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $token, public readonly ?int $eventId = null) {}

    public function handle(SaasPaymentService $payments): void
    {
        $event = $this->eventId ? SaasPaymentEvent::query()->find($this->eventId) : null;
        $event?->increment('attempts');

        try {
            $payments->processChargesNotification($this->token);
            $event?->forceFill(['status' => SaasPaymentEvent::STATUS_PROCESSED, 'error' => null, 'processed_at' => now()])->save();
        } catch (EfiException $exception) {
            $event?->forceFill(['status' => SaasPaymentEvent::STATUS_FAILED, 'error' => Str::limit($exception->getMessage(), 500, '')])->save();

            if ($exception->temporary) {
                throw $exception;
            }
        }
    }
}
