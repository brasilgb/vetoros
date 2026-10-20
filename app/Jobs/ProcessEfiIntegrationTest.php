<?php

namespace App\Jobs;

use App\Models\Admin\EfiIntegrationTest;
use App\Models\Admin\SaasPaymentEvent;
use App\Services\Payments\Efi\EfiException;
use App\Services\Payments\EfiIntegrationTestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Confirma na API uma cobrança de teste notificada pela Efí (VETOROS-EFI-TEST-01). Só toca o registro de teste. */
class ProcessEfiIntegrationTest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $testId, public readonly ?int $eventId = null) {}

    public function handle(EfiIntegrationTestService $tests): void
    {
        $event = $this->eventId ? SaasPaymentEvent::query()->find($this->eventId) : null;
        $event?->increment('attempts');

        $test = EfiIntegrationTest::query()->find($this->testId);
        if (! $test) {
            $event?->forceFill(['status' => SaasPaymentEvent::STATUS_IGNORED, 'processed_at' => now()])->save();

            return;
        }

        try {
            $tests->reconcile($test);
            $event?->forceFill(['status' => SaasPaymentEvent::STATUS_PROCESSED, 'error' => null, 'processed_at' => now()])->save();
        } catch (EfiException $exception) {
            $event?->forceFill(['status' => SaasPaymentEvent::STATUS_FAILED, 'error' => Str::limit($exception->getMessage(), 500, '')])->save();
            $test->forceFill(['error' => Str::limit($exception->getMessage(), 500, '')])->save();

            if ($exception->temporary) {
                throw $exception;
            }
        }
    }
}
