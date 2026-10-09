<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administração fiscal central: só RootAdmin (usuário sem tenant e com papel
 * root). Responde 403 diretamente, sem redirecionar para o app do tenant, para
 * que a recusa seja explícita também em requisições web.
 */
class RootAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isRootAdmin()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Acesso restrito ao RootAdmin.'], 403)
                : response('Acesso restrito ao RootAdmin.', 403);
        }

        return $next($request);
    }
}
