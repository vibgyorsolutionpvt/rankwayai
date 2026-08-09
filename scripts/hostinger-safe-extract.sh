#!/usr/bin/env bash
# Run ON THE SERVER. Extract a release zip without wiping uploads or .env.
#
# Usage:
#   bash scripts/hostinger-safe-extract.sh /path/to/rankwayai-release.zip
#   bash scripts/hostinger-safe-extract.sh /path/to/release.zip /path/to/app
#
set -euo pipefail

ZIP="${1:-}"
APP_ROOT="${2:-}"

if [[ -z "$ZIP" || ! -f "$ZIP" ]]; then
  echo "Usage: $0 /path/to/release.zip [/path/to/app]"
  exit 1
fi

if [[ -z "$APP_ROOT" ]]; then
  APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fi

APP_ROOT="$(cd "$APP_ROOT" && pwd)"
EXCLUDES_FILE="$APP_ROOT/scripts/deploy-excludes.txt"

# If extracting into a fresh tree, excludes file may only exist inside the zip.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "==> unzip → temp"
unzip -q "$ZIP" -d "$TMP"

# Support zip rooted at project files or a single top-level folder.
SRC="$TMP"
if [[ ! -f "$TMP/artisan" ]]; then
  # one top-level directory
  ENTRY="$(find "$TMP" -mindepth 1 -maxdepth 1 -type d | head -1)"
  if [[ -n "$ENTRY" && -f "$ENTRY/artisan" ]]; then
    SRC="$ENTRY"
  fi
fi

if [[ ! -f "$SRC/artisan" ]]; then
  echo "ERROR: artisan not found inside zip"
  exit 1
fi

# Prefer excludes from the new release; fall back to current app.
RELEASE_EXCLUDES="$SRC/scripts/deploy-excludes.txt"
if [[ -f "$RELEASE_EXCLUDES" ]]; then
  EXCLUDES_FILE="$RELEASE_EXCLUDES"
elif [[ ! -f "$EXCLUDES_FILE" ]]; then
  echo "ERROR: deploy-excludes.txt not found"
  exit 1
fi

mkdir -p "$APP_ROOT"

echo "==> rsync into $APP_ROOT (preserving .env + uploads + public/storage)"
rsync -a --delete \
  --exclude-from="$EXCLUDES_FILE" \
  "$SRC"/ "$APP_ROOT"/

cd "$APP_ROOT"
bash scripts/hostinger-post-deploy.sh
