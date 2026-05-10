#!/usr/bin/env bash
# Build WooBolSync distributable zip for WordPress (in-place update).
# Run from the plugin root: bash build-release.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
# Plugin header lines look like " * Version: 1.2.3" — match any line containing Version: after the docblock star.
VERSION="$(grep -m1 'Version:' "$ROOT/woo-bol-sync.php" | sed -E 's/.*Version:[[:space:]]+//;s/[[:space:]]*$//')"
OUT_DIR="$ROOT/dist"
STAGE="$OUT_DIR/woo-bol-sync"
ZIP_NAME="woo-bol-sync-${VERSION}.zip"
ZIP_PATH="$OUT_DIR/$ZIP_NAME"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE"

rsync -a \
  --exclude='dist/' \
  --exclude='.cursor/' \
  --exclude='.git/' \
  --exclude='.DS_Store' \
  --exclude='build-release.sh' \
  --exclude='*.zip' \
  "$ROOT/" "$STAGE/"

mkdir -p "$OUT_DIR"
(
  cd "$OUT_DIR"
  zip -rq "$ZIP_NAME" woo-bol-sync
)

echo "Built: $ZIP_PATH"
set +o pipefail
unzip -l "$ZIP_PATH" | head -20
set -o pipefail
