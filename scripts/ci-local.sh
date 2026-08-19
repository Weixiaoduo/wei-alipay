#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
./scripts/tests/run-tests.sh all
composer analyse
A="${TMPDIR:-/tmp}/wei-alipay-ci-build-a"
B="${TMPDIR:-/tmp}/wei-alipay-ci-build-b"
SOURCE_DATE_EPOCH="$(git log -1 --format=%ct)" ./scripts/build-release-candidate.sh "$A"
SOURCE_DATE_EPOCH="$(git log -1 --format=%ct)" ./scripts/build-release-candidate.sh "$B"
VERSION="$(awk -F': ' '/^[[:space:]]*\*[[:space:]]*Version:/ { print $2; exit }' wei-alipay.php)"
cmp "$A/wei-alipay-${VERSION}-candidate.zip" "$B/wei-alipay-${VERSION}-candidate.zip"
echo '[PASS] static tests, PHPStan and reproducible candidate build'
