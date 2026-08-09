#!/usr/bin/env bash
# Safe production deploy: updates code + public/build, never wipes uploads/.env/storage link.
#
# Usage:
#   ./scripts/hostinger-deploy.sh USER@HOST:~/domains/DOMAIN/rankwayai
#   ./scripts/hostinger-deploy.sh USER@HOST:~/path --skip-build
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

TARGET="${1:-}"
SKIP_BUILD=0
if [[ "${2:-}" == "--skip-build" ]] || [[ "${1:-}" == "--skip-build" ]]; then
  SKIP_BUILD=1
  if [[ "${1:-}" == "--skip-build" ]]; then
    TARGET="${2:-}"
  fi
fi

if [[ -z "$TARGET" ]]; then
  echo "Usage: $0 USER@HOST:~/domains/DOMAIN/rankwayai [--skip-build]"
  exit 1
fi

EXCLUDES_FILE="$ROOT/scripts/deploy-excludes.txt"
if [[ ! -f "$EXCLUDES_FILE" ]]; then
  echo "ERROR: missing $EXCLUDES_FILE"
  exit 1
fi

if [[ "$SKIP_BUILD" -eq 0 ]]; then
  echo "==> composer install --no-dev"
  composer install --no-dev --optimize-autoloader --no-interaction

  echo "==> npm ci && npm run build"
  npm ci
  npm run build

  if [[ ! -f public/build/manifest.json ]]; then
    echo "ERROR: public/build/manifest.json missing after build"
    exit 1
  fi
fi

echo "==> rsync → $TARGET"
echo "    Protected (not overwritten): .env, storage/app/public, public/storage"
rsync -avz --delete \
  --exclude-from="$EXCLUDES_FILE" \
  ./ "$TARGET"

echo ""
echo "Done sync. On the server run:"
echo "  cd <app-path> && bash scripts/hostinger-post-deploy.sh"
echo "Or: ssh and run php artisan storage:link && php artisan migrate --force && ..."
