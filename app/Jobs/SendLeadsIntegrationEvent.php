<?php

namespace App\Jobs;

use App\Models\LeadsIntegrationEvent;
use App\Models\LeadsIntegrationSetting;
use App\Services\LeadsIntegration\LeadsIntegrationClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Envia um evento ao VetorLeads. Falhas transitórias (conexão, 429, 5xx) são repetidas com
 * espera crescente; recusas definitivas (4xx) marcam o evento como failed sem repetir. Nada aqui
 * altera cadastro, assinatura ou cobrança do VetorSales.
 */
class SendLeadsIntegrationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public int $eventId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(LeadsIntegrationClient $client): void
    {
        $event = LeadsIntegrationEvent::query()->find($this->eventId);

        if ($event === null || $event->status === LeadsIntegrationEvent::STATUS_SENT) {
            return;
        }

        $settings = LeadsIntegrationSetting::current();

        if (! $settings->apiReady()) {
            $this->markFailed($event, 'Integração em modo manual ou sem endpoint/token.');

            return;
        }

        $event->increment('attempts');

        try {
            $response = $client->post($event->payload, $settings->endpoint_url, $settings->token);
        } catch (ConnectionException) {
            throw new RuntimeException('Falha de conexão com o VetorLeads.');
        }

        if ($response->successful()) {
            $event->forceFill(['status' => LeadsIntegrationEvent::STATUS_SENT, 'sent_at' => now(), 'last_error' => null])->save();
            $settings->forceFill(['last_sync_at' => now(), 'last_sync_error' => null])->save();

            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('VetorLeads respondeu HTTP '.$response->status().'.');
        }

        $this->markFailed($event, 'VetorLeads recusou o evento (HTTP '.$response->status().').');
    }

    public function failed(?Throwable $exception): void
    {
        $event = LeadsIntegrationEvent::query()->find($this->eventId);

        if ($event !== null) {
            $this->markFailed($event, $exception?->getMessage() ?? 'Falha desconhecida.');
        }
    }

    private function markFailed(LeadsIntegrationEvent $event, string $message): void
    {
        $event->forceFill(['status' => LeadsIntegrationEvent::STATUS_FAILED, 'last_error' => mb_substr($message, 0, 255)])->save();
        LeadsIntegrationSetting::current()->forceFill(['last_sync_error' => mb_substr($message, 0, 255)])->save();
        Log::warning('Evento de acompanhamento não entregue ao VetorLeads.', ['event_id' => $event->event_id, 'type' => $event->type, 'error' => $message]);
    }
}
