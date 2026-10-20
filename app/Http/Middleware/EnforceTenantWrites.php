<?php

namespace App\Http\Middleware;

use App\Support\TenantWriteGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durante a requisição, nenhum registro com empresa é gravado em outra empresa, mesmo que
 * a requisição envie `tenant_id` (ver App\Tenantable).
 */
class EnforceTenantWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        return TenantWriteGuard::enforce(fn () => $next($request));
    }
}
