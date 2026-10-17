<?php

/*
| Modo Suporte (VETOR-STABILIZE-03): o RootAdmin entra no painel de uma empresa com a própria
| identidade, sem a senha do cliente. Escrita é bloqueada por padrão; só as rotas abaixo aceitam
| POST/PUT/PATCH (nunca DELETE). Financeiro, fiscal, usuários, segurança e pagamentos ficam de
| fora: incluir uma rota aqui é a "autorização específica" e fica registrado no histórico do git.
*/

return [
    // Duração máxima de uma sessão de suporte, em minutos.
    'ttl_minutes' => (int) env('SUPPORT_SESSION_TTL_MINUTES', 60),

    // Retenção dos registros de suporte (sessões e ações), em dias.
    'retention_days' => (int) env('SUPPORT_SESSION_RETENTION_DAYS', 365),

    // Correções administrativas permitidas no suporte (nomes exatos de rota; DELETE nunca é aceito).
    'writable_routes' => [
        'app.company.store',
        'app.company.update',
        'app.other-settings.update',
        'app.other-settings.test-mail',
        'app.whatsapp-message.store',
        'app.whatsapp-message.update',
        'app.receipts.store',
        'app.receipts.update',
        'app.register-checklists.store',
        'app.register-checklists.update',
        'app.register-equipments.store',
        'app.register-equipments.update',
        'app.register-services.store',
        'app.register-services.update',
        'app.services.store',
        'app.services.update',
    ],
];
