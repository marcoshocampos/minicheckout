#!/bin/sh
# Entrypoint do app e do worker. Idempotente de propósito: pode rodar quantas
# vezes quiser (restart, scale) sem efeito duplicado.
set -e

cd /var/www/html

log() { echo "[entrypoint] $*" >&2; }

if [ ! -f composer.json ]; then
  log "ERRO: ./src está vazio. Instale o Laravel antes de subir a stack (veja o README)."
  exit 1
fi

if [ ! -d vendor ]; then
  log "vendor/ ausente, rodando composer install..."
  composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f .env ]; then
  log ".env ausente, copiando de .env.example e gerando APP_KEY..."
  cp .env.example .env
  php artisan key:generate --force
fi

mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions \
         storage/framework/views bootstrap/cache
chmod -R a+rwX storage bootstrap/cache 2>/dev/null || true

if [ -n "$DB_HOST" ]; then
  log "esperando ${DB_HOST}:${DB_PORT:-5432}..."
  until pg_isready -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-minicheckout}" -q; do
    sleep 1
  done
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
  log "rodando migrations..."
  php artisan migrate --force --no-interaction
fi

# Em dev nenhum cache de config/rota: o que está no arquivo é o que vale.
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear  >/dev/null 2>&1 || true

log "pronto: $*"
exec "$@"
