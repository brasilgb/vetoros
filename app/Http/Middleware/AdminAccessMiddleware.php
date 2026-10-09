<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Painel RootAdmin (/admin). Usuário de tenant volta para o próprio app; os demais só
 * entram se forem RootAdmin (mesma política de RootAdminOnly). Antes, qualquer usuário
 * sem tenant passava, independentemente do papel.
 */
class AdminAccessMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id !== null) {
            return Redirect::to(config('app.url').'/app');
        }

        if (! $user?->isRootAdmin()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Acesso restrito ao RootAdmin.'], 403)
                : response('Acesso restrito ao RootAdmin.', 403);
        }

        return $next($request);
    }
}
