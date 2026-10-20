<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usuário inativado perde o acesso já na próxima requisição: a sessão web é encerrada e
 * o token da API é recusado, mesmo que tenha sido emitido antes da inativação.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        if ($request->hasSession() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = 'Seu usuário está inativo. Procure o administrador da empresa.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['success' => false, 'message' => $message], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
