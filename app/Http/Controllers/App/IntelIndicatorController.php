<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\Intel\OperationalIndicatorsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Indicadores operacionais e comerciais (VETOR-INTEL-04), somente leitura.
 * Permissão de relatórios; tenant sempre do usuário autenticado.
 */
class IntelIndicatorController extends Controller
{
    public const MAX_PERIOD_DAYS = 366;

    /**
     * Mesma rota para a tela e para a API: o navegador recebe a página "Indicadores"
     * (que busca os dados neste endpoint em JSON); requisições JSON recebem os dados.
     */
    public function __invoke(Request $request, OperationalIndicatorsService $indicators): JsonResponse|InertiaResponse
    {
        Gate::authorize('reports.view');

        $tenantId = (int) Auth::user()?->tenant_id;
        abort_unless($tenantId > 0, 403);

        if (! $request->expectsJson()) {
            return Inertia::render('app/intel/indicators', [
                'defaults' => [
                    'from' => now()->subDays(29)->toDateString(),
                    'to' => now()->toDateString(),
                    'stalled_days' => 7,
                    'expiring_days' => 3,
                    'max_period_days' => self::MAX_PERIOD_DAYS,
                ],
            ]);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'stalled_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'expiring_days' => ['nullable', 'integer', 'min:0', 'max:90'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays(29);

        if ($from->diffInDays($to) > self::MAX_PERIOD_DAYS) {
            return response()->json(['message' => 'O período máximo é de 366 dias.', 'errors' => ['from' => ['O período máximo é de 366 dias.']]], 422);
        }

        return response()->json($indicators->all(
            $tenantId,
            $from,
            $to,
            (int) ($validated['stalled_days'] ?? 7),
            (int) ($validated['expiring_days'] ?? 3),
        ));
    }
}
