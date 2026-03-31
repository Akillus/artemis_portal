#!/usr/bin/env bash

set -euo pipefail

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache database
touch database/database.sqlite
chmod -R ug+rw storage bootstrap/cache database

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force --no-interaction
fi

echo "Waiting for OpenSearch at ${OPENSEARCH_URL:-http://opensearch:9200}..."
until curl -fsS "${OPENSEARCH_URL:-http://opensearch:9200}" >/dev/null; do
  sleep 2
done

php artisan optimize:clear
php artisan migrate --force --seed
php artisan portal:bootstrap-opensearch

exec php artisan serve --host=0.0.0.0 --port=8000
