<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\PartImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modelo e importação CSV de produtos/peças. Mesma permissão do cadastro de produtos
 * (parts.access); o tenant é sempre o do usuário autenticado.
 */
class PartImportController extends Controller
{
    public function __construct(private readonly PartImportService $imports) {}

    public function template(): Response
    {
        Gate::authorize('parts.access');

        return response($this->imports->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modelo-importacao-produtos.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('parts.access');

        return response()->json($this->imports->preview($this->tenantId(), $this->content($request)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('parts.access');

        // O arquivo é enviado e validado de novo: a prévia do navegador nunca é confiável.
        return response()->json($this->imports->import($this->tenantId(), Auth::id(), $this->content($request)));
    }

    private function tenantId(): int
    {
        $tenantId = (int) Auth::user()?->tenant_id;
        abort_unless($tenantId > 0, 403, 'Importação disponível apenas para usuários de uma empresa.');

        return $tenantId;
    }

    private function content(Request $request): string
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:'.(PartImportService::MAX_BYTES / 1024), 'mimes:csv,txt'],
        ], [
            'arquivo.required' => 'Selecione o arquivo CSV.',
            'arquivo.max' => 'O arquivo excede 2 MB.',
            'arquivo.mimes' => 'Envie um arquivo .csv (separado por ponto e vírgula).',
        ]);

        return (string) file_get_contents($request->file('arquivo')->getRealPath());
    }
}
