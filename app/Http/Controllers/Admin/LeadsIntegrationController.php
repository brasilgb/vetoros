<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendLeadsIntegrationEvent;
use App\Models\LeadsIntegrationEvent;
use App\Models\LeadsIntegrationSetting;
use App\Services\LeadsIntegration\LeadsIntegrationClient;
use App\Services\LeadsIntegration\LeadsIntegrationEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * RootAdmin → Configurações → Acompanhamento VetorLeads (VETOROS-LEADS-INTEGRATION). Modo Manual ou API, endpoint e token.
 * O token é só de escrita: a tela informa apenas se está configurado.
 */
class LeadsIntegrationController extends Controller
{
    public function index(): Response
    {
        $settings = LeadsIntegrationSetting::current();

        return Inertia::render('admin/leads-integration/index', [
            'settings' => [
                'mode' => $settings->mode,
                'endpoint_url' => $settings->endpoint_url,
                'token_configured' => filled($settings->token),
                'api_ready' => $settings->apiReady(),
                'last_test_at' => $settings->last_test_at?->toIso8601String(),
                'last_test_ok' => $settings->last_test_ok,
                'last_test_error' => $settings->last_test_error,
                'last_sync_at' => $settings->last_sync_at?->toIso8601String(),
                'last_sync_error' => $settings->last_sync_error,
            ],
            'counts' => [
                'pending' => LeadsIntegrationEvent::query()->where('status', LeadsIntegrationEvent::STATUS_PENDING)->count(),
                'failed' => LeadsIntegrationEvent::query()->where('status', LeadsIntegrationEvent::STATUS_FAILED)->count(),
                'sent' => LeadsIntegrationEvent::query()->where('status', LeadsIntegrationEvent::STATUS_SENT)->count(),
            ],
            'failures' => LeadsIntegrationEvent::query()
                ->with('tenant:id,company')
                ->where('status', LeadsIntegrationEvent::STATUS_FAILED)
                ->latest('updated_at')
                ->limit(20)
                ->get()
                ->map(fn (LeadsIntegrationEvent $event) => [
                    'id' => $event->id,
                    'type' => LeadsIntegrationEvent::TYPES[$event->type] ?? $event->type,
                    'company' => $event->tenant?->company,
                    'attempts' => $event->attempts,
                    'last_error' => $event->last_error,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ]),
            'mode_options' => [
                ['value' => LeadsIntegrationSetting::MODE_MANUAL, 'label' => 'Manual'],
                ['value' => LeadsIntegrationSetting::MODE_API, 'label' => 'API'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in([LeadsIntegrationSetting::MODE_MANUAL, LeadsIntegrationSetting::MODE_API])],
            'endpoint_url' => ['nullable', 'string', 'max:500'],
            'token' => ['nullable', 'string', 'min:20', 'max:255'],
            'clear_token' => ['boolean'],
        ]);

        $settings = LeadsIntegrationSetting::current();
        $endpoint = trim((string) ($data['endpoint_url'] ?? ''));

        if ($endpoint !== '' && ($error = LeadsIntegrationClient::urlError($endpoint)) !== null) {
            return back()->withErrors(['endpoint_url' => $error]);
        }

        if ($data['mode'] === LeadsIntegrationSetting::MODE_API && ($endpoint === '' || (! filled($data['token'] ?? null) && ! filled($settings->token)))) {
            return back()->withErrors(['mode' => 'No modo API informe o endpoint e o token.']);
        }

        $changes = ['mode' => $data['mode'], 'endpoint_url' => $endpoint !== '' ? $endpoint : null];

        if (! empty($data['clear_token'])) {
            $changes['token'] = null;
        } elseif (filled($data['token'] ?? null)) {
            $changes['token'] = $data['token'];
        }

        $settings->forceFill($changes)->save();

        return back()->with('success', 'Configuração do acompanhamento salva.');
    }

    public function test(): RedirectResponse
    {
        $settings = LeadsIntegrationSetting::current();

        if (! $settings->apiReady()) {
            return back()->withErrors(['test' => 'Configure o modo API, o endpoint e o token antes de testar.']);
        }

        $payload = [
            'event_id' => (string) Str::uuid(),
            'type' => 'connection_test',
            'occurred_at' => now()->toIso8601String(),
            'product' => LeadsIntegrationEvents::PRODUCT,
            'account_id' => 0,
        ];

        try {
            $response = app(LeadsIntegrationClient::class)->post($payload, $settings->endpoint_url, $settings->token);
            $ok = $response->successful();
            $error = $ok ? null : 'VetorLeads respondeu HTTP '.$response->status().'.';
        } catch (Throwable) {
            $ok = false;
            $error = 'Falha de conexão com o VetorLeads.';
        }

        $settings->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => $ok,
            'last_test_error' => $error,
        ])->save();

        return $ok
            ? back()->with('success', 'Conexão testada com sucesso.')
            : back()->withErrors(['test' => $error]);
    }

    public function retry(): RedirectResponse
    {
        $settings = LeadsIntegrationSetting::current();

        if (! $settings->apiReady()) {
            return back()->withErrors(['retry' => 'Configure o modo API antes de reenviar.']);
        }

        LeadsIntegrationEvent::query()
            ->where('status', LeadsIntegrationEvent::STATUS_FAILED)
            ->orderBy('id')
            ->get()
            ->each(function (LeadsIntegrationEvent $event) {
                $event->forceFill(['status' => LeadsIntegrationEvent::STATUS_PENDING, 'last_error' => null])->save();
                SendLeadsIntegrationEvent::dispatch($event->id);
            });

        return back()->with('success', 'Eventos com falha foram reenviados.');
    }
}
