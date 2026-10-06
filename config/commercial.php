<?php

return [
    /*
    | Exibição pública dos preços dos planos. Desligada por decisão comercial
    | (VETOROS-COMERCIAL-01): o visitante solicita orçamento pelo WhatsApp.
    | Ao ligar, os valores vêm da tabela `plans`, administrada pelo RootAdmin.
    */
    'public_prices_enabled' => (bool) env('PUBLIC_PRICES_ENABLED', false),
];
