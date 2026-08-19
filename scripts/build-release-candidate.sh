#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-/tmp/wei-alipay-release-candidate}"
VERSION="$(awk -F': ' '/^[[:space:]]*\*[[:space:]]*Version:/ { print $2; exit }' "$ROOT/wei-alipay.php")"
SOURCE_SHA="$(git -C "$ROOT" rev-parse HEAD)"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$ROOT" log -1 --format=%ct)}"
STAGE="$OUT/stage/wei-alipay"
ZIP="$OUT/wei-alipay-${VERSION}-candidate.zip"
mkdir -p "$STAGE"
find "$OUT" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
mkdir -p "$STAGE"
rsync -a --delete \
  --exclude='.git' --exclude='.git/' --exclude='.gitignore' --exclude='.wp-env.json' \
  --exclude='AI原生配置.md' \
  --exclude='.forgejo/' --exclude='vendor/' --exclude='node_modules/' \
  --exclude='composer.json' --exclude='composer.lock' \
  --exclude='.phpstan.cache' --exclude='phpstan*.neon*' --exclude='tests/' --exclude='scripts/' \
  --exclude='docs/ai-context/' --exclude='docs/audits/' --exclude='docs/governance/' --exclude='build/' \
  "$ROOT/" "$STAGE/"
find "$OUT/stage" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +
(
  cd "$ROOT"
  find lib/alipay inc/modules/mini-program/lib inc/modules/cross-border/lib -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum
) > "$OUT/sdk-files.sha256"
sha256sum "$OUT/sdk-files.sha256" | awk '{print $1"  sdk-files.sha256"}' > "$OUT/sdk-tree.sha256"
(
  cd "$OUT/stage"
  find wei-alipay -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum
) > "$OUT/release-files.sha256"
cp "$ROOT/docs/sbom/wei-alipay.spdx.json" "$OUT/wei-alipay.spdx.json"
(
  cd "$OUT/stage"
  LC_ALL=C find wei-alipay -type f -print | LC_ALL=C sort | zip -X -q "$ZIP" -@
)
unzip -t "$ZIP" >/dev/null
ZIP_SHA="$(sha256sum "$ZIP" | awk '{print $1}')"
printf '%s  %s\n' "$ZIP_SHA" "$(basename "$ZIP")" > "$ZIP.sha256"
cat > "$OUT/manifest.txt" <<MANIFEST
plugin=wei-alipay
version=$VERSION
source_commit=$SOURCE_SHA
source_date_epoch=$SOURCE_DATE_EPOCH
zip=$(basename "$ZIP")
zip_sha256=$ZIP_SHA
release_files_sha256=$(sha256sum "$OUT/release-files.sha256" | awk '{print $1}')
sdk_files_sha256=$(sha256sum "$OUT/sdk-files.sha256" | awk '{print $1}')
sbom=wei-alipay.spdx.json
MANIFEST
printf 'candidate=%s\n' "$ZIP"
