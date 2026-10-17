<?php

namespace App\Http\Middleware;

use App\Models\Admin\SupportSession;
use App\Models\Admin\SupportSessionAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SupportSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modo Suporte (VETOR-STABILIZE-03). Roda antes da ligação de rotas com modelos (SubstituteBindings),
 * para que `resolveCurrentTenantId()`, o TenantScope e o route model binding já usem a empresa do
 * suporte.
 *
 * - A empresa vem do registro `support_sessions` (dono = usuário logado, aberto, não expirado).
 * - O RootAdmin continua sendo ele mesmo: só o `tenant_id` do usuário desta requisição é fixado em
 *   memória (marcado como original, nunca gravado), então todos os registros saem com o id real dele.
 * - Escrita bloqueada por padrão: só GET/HEAD/OPTIONS e as rotas de `support.writable_routes`
 *   (DELETE nunca). Cada escrita, permitida ou bloqueada, é registrada sem o corpo da requisição.
 * - Painéis do RootAdmin ficam inacessíveis durante o suporte (o usuário deixa de ser RootAdmin nesta
 *   requisição), o que também impede sessões encadeadas.
 */
class ApplySupportSession
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const ALWAYS_ALLOWED = ['support.end', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(SupportSessionService::SESSION_KEY)) {
            return $next($request);
        }

        $user = $request->user();
        $session = SupportSession::query()->find($request->session()->get(SupportSessionService::SESSION_KEY));
        $tenant = $session ? Tenant::query()->find($session->tenant_id) : null;

        $valid = $user
            && $session
            && $tenant
            && $user->isRoot()
            // No banco, não na instância (que pode já ter o tenant do suporte em memória).
            && User::query()->whereKey($user->getKey())->value('tenant_id') === null
            && (int) $session->root_user_id === (int) $user->id
            && $session->ended_at === null;

        if (! $valid) {
            app(SupportSessionService::class)->end($request, SupportSession::END_INVALID);

            return $next($request);
        }

        if (! $session->expires_at->isFuture()) {
            app(SupportSessionService::class)->end($request, SupportSession::END_EXPIRED);

            return $request->expectsJson()
                ? response()->json(['message' => 'A sessão de suporte expirou.'], 401)
                : redirect()->route('admin.tenants.index')->with('error', 'A sessão de suporte expirou. Inicie um novo acesso, se necessário.');
        }

        // Empresa do suporte só nesta requisição; marcado como original para nunca ir ao banco.
        $user->setAttribute('tenant_id', $tenant->id);
        $user->syncOriginalAttribute('tenant_id');
        $user->setRelation('tenant', $tenant);
        $request->attributes->set('support_session', $session);

        if (in_array($request->method(), self::READ_METHODS, true)) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $allowed = in_array($routeName, self::ALWAYS_ALLOWED, true)
            || ($request->method() !== 'DELETE' && in_array($routeName, (array) config('support.writable_routes', []), true));

        if (! $allowed) {
            $this->record($session, $request, $routeName, Response::HTTP_FORBIDDEN, true);
            $message = 'Operação bloqueada no Modo Suporte (financeiro, fiscal, usuários, exclusões e pagamentos não são alterados pelo suporte).';

            return $request->expectsJson()
                ? response()->json(['message' => $message], Response::HTTP_FORBIDDEN)
                : back()->with('error', $message);
        }

        $response = $next($request);

        if (! in_array($routeName, self::ALWAYS_ALLOWED, true)) {
            $this->record($session, $request, $routeName, $response->getStatusCode(), false);
        }

        return $response;
    }

    private function record(SupportSession $session, Request $request, ?string $routeName, int $status, bool $blocked): void
    {
        SupportSessionAction::query()->create([
            'support_session_id' => $session->id,
            'method' => $request->method(),
            'route_name' => $routeName ? mb_substr($routeName, 0, 150) : null,
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
            'status' => $status,
            'blocked' => $blocked,
        ]);
    }
}
