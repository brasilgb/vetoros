<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\SupportSession;
use App\Models\Tenant;
use App\Services\SupportSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Modo Suporte (VETOR-STABILIZE-03): o RootAdmin entra no painel de uma empresa com a própria
 * identidade, sem usar nem alterar a senha do cliente.
 */
class SupportSessionController extends Controller
{
    public function __construct(private readonly SupportSessionService $support) {}

    /** RootAdmin → Empresas → "Acessar painel": motivo e a senha do próprio RootAdmin (reautenticação). */
    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'password' => ['required', 'current_password:web'],
        ], [
            'reason.required' => 'Informe o motivo do acesso.',
            'reason.min' => 'Descreva o motivo do acesso (mínimo de 10 caracteres).',
            'password.current_password' => 'Senha incorreta.',
        ]);

        // A rota já é exclusiva do RootAdmin fora de suporte; isto é a defesa contra encadeamento.
        if ($request->session()->has(SupportSessionService::SESSION_KEY)) {
            return back()->with('error', 'Encerre o suporte em andamento antes de acessar outra empresa.');
        }

        $this->support->start($request, $request->user(), $tenant, $validated['reason']);

        return redirect()->route('app.dashboard');
    }

    /** "Encerrar suporte": fecha o registro, limpa a sessão e volta para a lista de empresas. */
    public function destroy(Request $request): RedirectResponse
    {
        $ended = $this->support->end($request, SupportSession::END_MANUAL);
        $request->session()->regenerateToken();

        return redirect()->route('admin.tenants.index')->with('success', $ended ? 'Suporte encerrado.' : 'Nenhum suporte em andamento.');
    }
}
