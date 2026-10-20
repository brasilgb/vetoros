<?php

return [

    /*
    | Pasta pública antiga (public/storage/orders/{número da OS}), compartilhada entre empresas.
    | Só é lida para fotos ainda não migradas (vetoros:migrate-order-files); assinaturas
    | antigas nunca são lidas daqui, porque a pasta é ambígua entre empresas.
    */
    'legacy_root' => env('ORDER_FILES_LEGACY_ROOT') ?: public_path('storage/orders'),

    // Validade das URLs assinadas de fotos e assinaturas.
    'url_ttl_minutes' => (int) env('ORDER_FILES_URL_TTL_MINUTES', 60),

];
