#!/usr/bin/env bash
set -euo pipefail
MODE="${1:-all}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
pass() { printf '[PASS] %s\n' "$1"; }
info() { printf '[INFO] %s\n' "$1"; }
fail() { printf '[FAIL] %s\n' "$1" >&2; exit 1; }

run_static() {
  info 'PHP syntax checks (first-party PHP; bundled SDK excluded)'
  local count
  count="$(find "$ROOT_DIR" -path "$ROOT_DIR/.git" -prune -o -path "$ROOT_DIR/vendor" -prune -o -path "$ROOT_DIR/lib" -prune -o -path "$ROOT_DIR/inc/modules/mini-program/lib" -prune -o -path "$ROOT_DIR/inc/modules/cross-border/lib" -prune -o -type f -name '*.php' -print | wc -l | tr -d ' ')"
  if ! find "$ROOT_DIR" -path "$ROOT_DIR/.git" -prune -o -path "$ROOT_DIR/vendor" -prune -o -path "$ROOT_DIR/lib" -prune -o -path "$ROOT_DIR/inc/modules/mini-program/lib" -prune -o -path "$ROOT_DIR/inc/modules/cross-border/lib" -prune -o -type f -name '*.php' -print0 | xargs -0 -n 1 -P 4 php -l >/tmp/wei-alipay-php-lint.txt; then
    grep -v '^No syntax errors detected' /tmp/wei-alipay-php-lint.txt >&2 || true
    fail "one or more of $count PHP files failed syntax checks"
  fi
  if [[ "${WEI_ALIPAY_SKIP_JS_SYNTAX:-0}" == "1" ]]; then
    info 'JavaScript syntax SKIP in the PHP compatibility lane; the modern PHP lane must cover it'
    js_summary='PHP files checked; JavaScript syntax SKIP'
  else
    while IFS= read -r -d '' file; do node --check "$file" >/dev/null; done < <(find "$ROOT_DIR/js" "$ROOT_DIR/inc/modules" -type f -name '*.js' ! -name '*.min.js' -print0)
    js_summary='PHP files and first-party JavaScript syntax checked'
  fi
  git -C "$ROOT_DIR" diff --check
  pass "$count $js_summary"
}

run_contracts() {
  php "$ROOT_DIR/tests/rename-contracts.php"
  php "$ROOT_DIR/tests/security-contracts.php"
  php "$ROOT_DIR/tests/multisite-lifecycle-contracts.php"
  php "$ROOT_DIR/tests/uninstall-retention-contracts.php"
  php "$ROOT_DIR/tests/uninstall-purge-contracts.php"
  ! grep -RInE "(get|update|delete)_post_meta\(" "$ROOT_DIR/inc/modules" --include='*.php' --exclude-dir=lib >/tmp/wei-alipay-direct-order-meta.txt || {
    cat /tmp/wei-alipay-direct-order-meta.txt >&2
    fail 'direct post-meta access remains in payment modules (HPOS contract)'
  }
  ! grep -RIn "str_starts_with(" "$ROOT_DIR/includes" --include='*.php' >/tmp/wei-alipay-php8-only.txt || {
    cat /tmp/wei-alipay-php8-only.txt >&2
    fail 'PHP 8-only updater call remains'
  }
  pass 'security and compatibility contracts'
}

run_runtime() {
  local wp_path="${WEI_ALIPAY_TEST_WP_PATH:-}"
  [[ -n "$wp_path" && -f "$wp_path/wp-config.php" ]] || fail 'set WEI_ALIPAY_TEST_WP_PATH to an isolated test WordPress installation'
  [[ "$wp_path" != *'.local/share/Trash/'* ]] || fail 'refusing trashed/historical WordPress path'
  wp --path="$wp_path" eval 'if (!class_exists("WooCommerce")) { throw new Exception("WooCommerce missing"); } $g=WC()->payment_gateways()->payment_gateways(); foreach (["alipay","alipay_facetopay","alipay_installment","alipay_barcode"] as $id) { if (!isset($g[$id])) { throw new Exception("gateway missing: ".$id); } } echo "runtime gateways:ok\n";'
  pass 'isolated WordPress runtime gateway registration'
}
case "$MODE" in
  static) run_static ;;
  contracts) run_contracts ;;
  runtime) run_runtime ;;
  all) run_static; run_contracts ;;
  *) echo "Usage: $0 [static|contracts|runtime|all]" >&2; exit 2 ;;
esac
pass "run-tests.sh ($MODE) completed"
