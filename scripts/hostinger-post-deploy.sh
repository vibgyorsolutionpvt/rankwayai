#!/usr/bin/env bash
# Run ON THE SERVER after code sync/unzip.
# Recreates storage link; does not touch uploaded files in storage/app/public.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f artisan ]]; then
  echo "ERROR: run this from the Laravel app root (artisan not found)"
  exit 1
fi

if [[ ! -f .env ]]; then
  echo "ERROR: .env missing — do not deploy without production .env"
  exit 1
fi

mkdir -p storage/app/public \
  storage/framework/{cache/data,sessions,views} \
  storage/logs \
  bootstrap/cache

echo "==> storage:link (safe if already linked)"
php artisan storage:link

echo "==> migrate"
php artisan migrate --force

echo "==> caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> queue restart (if workers running)"
php artisan queue:restart || true

echo "==> permissions"
chmod -R ug+rwx storage bootstrap/cache || true

echo ""
echo "Post-deploy OK."
echo "Check: public/storage → storage/app/public"
ls -la public/storage 2>/dev/null || echo "WARN: public/storage missing"
