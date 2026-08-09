#!/usr/bin/env bash
# Prepare a Hostinger-ready release locally (no .env uploaded).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> composer install --no-dev"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> npm ci && npm run build"
npm ci
npm run build

if [[ ! -f public/build/manifest.json ]]; then
  echo "ERROR: public/build/manifest.json missing after build"
  exit 1
fi

OUT="${1:-/tmp/rankwayai-release.zip}"
echo "==> zip → $OUT"
rm -f "$OUT"
zip -rq "$OUT" . \
  -x "*.git*" \
  -x "*node_modules*" \
  -x "*.env" \
  -x "*storage/logs/*" \
  -x "*storage/framework/cache/data/*" \
  -x "*storage/framework/sessions/*" \
  -x "*storage/framework/views/*" \
  -x "*tests*" \
  -x "*.phpunit*"

echo "Done."
echo "Upload $OUT to the server, unzip into APP path, then follow docs/11_HOSTINGER_DEPLOY.md"
