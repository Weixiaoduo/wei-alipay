#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-/tmp/woo-alipay-release-candidate}"
STAGE="$OUT/stage/woo-alipay"
mkdir -p "$STAGE" "$OUT"

rsync -a --delete \
  --exclude='.git' --exclude='.git/' --exclude='.gitignore' --exclude='.wp-env.json' \
  --exclude='vendor/' --exclude='node_modules/' --exclude='composer.json' --exclude='composer.lock' \
  --exclude='.phpstan.cache' --exclude='phpstan*.neon*' --exclude='tests/' --exclude='scripts/' \
  --exclude='docs/audits/' --exclude='build/' \
  "$ROOT/" "$STAGE/"

(
  cd "$ROOT"
  find lib/alipay -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum
) | sha256sum | awk '{print $1"  lib-alipay-tree"}' > "$OUT/sdk-tree.sha256"

(
  cd "$OUT/stage"
  find woo-alipay -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum
) > "$OUT/release-files.sha256"

cp "$ROOT/docs/sbom/woo-alipay.spdx.json" "$OUT/woo-alipay.spdx.json"
( cd "$OUT/stage" && zip -X -qr "$OUT/woo-alipay-3.2.0-candidate.zip" woo-alipay )
sha256sum "$OUT/woo-alipay-3.2.0-candidate.zip" > "$OUT/woo-alipay-3.2.0-candidate.zip.sha256"
unzip -t "$OUT/woo-alipay-3.2.0-candidate.zip" >/dev/null
printf 'candidate=%s\n' "$OUT/woo-alipay-3.2.0-candidate.zip"
