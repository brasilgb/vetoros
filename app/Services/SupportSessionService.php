<?php

namespace App\Services;

use App\Models\Admin\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Modo Suporte (VETOR-STABILIZE-03). A sessão Laravel do RootAdmin guarda só o id do registro em
 * `support_sessions`; empresa, dono e validade vêm sempre do banco (nunca da URL nem do navegador).
 */
class SupportSessionService
{
    public const SESSION_KEY = 'support_session_id';

    public function start(Request $request, User $root, Tenant $tenant, string $reason): SupportSession
    {
        $session = SupportSession::query()->create([
            'root_user_id' => $root->id,
            'tenant_id' => $tenant->id,
            'reason' => trim($reason),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'started_at' => now(),
            'expires_at' => now()->addMinutes(max(5, (int) config('support.ttl_minutes', 60))),
        ]);

        // Novo id de sessão ao mudar de contexto (fixação de sessão).
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $session->id);

        return $session;
    }

    /** Encerra a sessão de suporte desta sessão Laravel, se houver. */
    public function end(Request $request, string $reason): ?SupportSession
    {
        $id = $request->session()->pull(self::SESSION_KEY);
        $request->session()->forget('tenant_id');

        $session = $id ? SupportSession::query()->find($id) : null;
        $session?->end($reason);

        return $session;
    }
}
