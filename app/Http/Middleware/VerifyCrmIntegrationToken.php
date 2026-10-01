<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica integrações servidor-a-servidor do CRM ABrasil via
 * `Authorization: Bearer <token>`, com o token vindo de
 * CRM_REGISTRATION_CHECK_TOKEN. Sem token configurado, nega tudo.
 */
class VerifyCrmIntegrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.crm_abrasil.registration_check_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
