<?php

namespace App\Listeners;

use App\Models\Admin\SupportSession;
use App\Services\SupportSessionService;

/** Logout encerra a sessão de suporte (VETOR-STABILIZE-03), registrando o motivo "logout". */
class EndSupportSessionOnLogout
{
    public function __construct(private readonly SupportSessionService $support) {}

    public function handle(object $event): void
    {
        $request = request();

        if ($request->hasSession() && $request->session()->has(SupportSessionService::SESSION_KEY)) {
            $this->support->end($request, SupportSession::END_LOGOUT);
        }
    }
}
