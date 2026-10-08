<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * Não registra requisições de dados (JSON) como "URL anterior" da sessão.
 *
 * As telas buscam dados com axios sem o cabeçalho X-Requested-With, então o Laravel
 * as tratava como navegação. Depois, um redirect()->back() (ex.: 403) levava o
 * usuário para o endpoint e ele via JSON cru.
 */
class StartSession extends BaseStartSession
{
    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->expectsJson()) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
