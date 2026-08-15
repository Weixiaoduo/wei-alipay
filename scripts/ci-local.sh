#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
./scripts/tests/run-tests.sh all
composer analyse
A="${TMPDIR:-/tmp}/woo-alipay-ci-build-a"
B="${TMPDIR:-/tmp}/woo-alipay-ci-build-b"
SOURCE_DATE_EPOCH="$(git log -1 --format=%ct)" ./scripts/build-release-candidate.sh "$A"
SOURCE_DATE_EPOCH="$(git log -1 --format=%ct)" ./scripts/build-release-candidate.sh "$B"
cmp "$A/woo-alipay-3.2.0-candidate.zip" "$B/woo-alipay-3.2.0-candidate.zip"
echo '[PASS] static tests, PHPStan and reproducible candidate build'
