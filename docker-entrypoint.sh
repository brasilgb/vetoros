#!/bin/sh
set -eu
mkdir -p /var/www/html/public/build
cp -a /opt/public-build/. /var/www/html/public/build/
# Cache de configuração, rotas, eventos e views: gerado no início do container, quando as
# variáveis de ambiente de produção já existem. Uma falha aqui não impede a subida.
php artisan optimize >/dev/null 2>&1 || echo "infra-entrypoint: aviso: php artisan optimize falhou; seguindo sem cache" >&2
# O entrypoint roda como root; os processos do FPM (www-data) precisam ler e regravar os caches.
chown -R www-data:www-data /var/www/html/bootstrap/cache /var/www/html/storage/framework 2>/dev/null || true
exec "$@"
