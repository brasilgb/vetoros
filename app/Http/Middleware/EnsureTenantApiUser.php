<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API dos apps (auth:sanctum) só para usuários de uma empresa. Sem tenant, o TenantScope
 * não filtra nada e as consultas devolveriam dados de todas as empresas.
 */
class EnsureTenantApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->tenant_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso disponível apenas para usuários de uma empresa.',
            ], 403);
        }

        return $next($request);
    }
}
