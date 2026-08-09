#!/usr/bin/env bash
set -euo pipefail
MODE="${1:-all}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
pass() { printf '[PASS] %s\n' "$1"; }
info() { printf '[INFO] %s\n' "$1"; }
fail() { printf '[FAIL] %s\n' "$1" >&2; exit 1; }

run_static() {
  info 'PHP syntax checks (all plugin PHP files)'
  local count
  count="$(find "$ROOT_DIR" -path "$ROOT_DIR/.git" -prune -o -type f -name '*.php' -print | wc -l | tr -d ' ')"
  if ! find "$ROOT_DIR" -path "$ROOT_DIR/.git" -prune -o -type f -name '*.php' -print0 | xargs -0 -n 1 -P 4 php -l >/tmp/woo-alipay-php-lint.txt; then
    grep -v '^No syntax errors detected' /tmp/woo-alipay-php-lint.txt >&2 || true
    fail "one or more of $count PHP files failed syntax checks"
  fi
  while IFS= read -r -d '' file; do node --check "$file" >/dev/null; done < <(find "$ROOT_DIR/js" "$ROOT_DIR/inc/modules" -type f -name '*.js' ! -name '*.min.js' -print0)
  git -C "$ROOT_DIR" diff --check
  pass "$count PHP files and first-party JavaScript syntax checked"
}

run_contracts() {
  php "$ROOT_DIR/tests/security-contracts.php"
  ! grep -RInE "(get|update|delete)_post_meta\(" "$ROOT_DIR/inc/modules" --include='*.php' --exclude-dir=lib >/tmp/woo-alipay-direct-order-meta.txt || {
    cat /tmp/woo-alipay-direct-order-meta.txt >&2
    fail 'direct post-meta access remains in payment modules (HPOS contract)'
  }
  ! grep -RIn "str_starts_with(" "$ROOT_DIR/includes" --include='*.php' >/tmp/woo-alipay-php8-only.txt || {
    cat /tmp/woo-alipay-php8-only.txt >&2
    fail 'PHP 8-only updater call remains'
  }
  pass 'security and compatibility contracts'
}

run_runtime() {
  local wp_path="${WOO_ALIPAY_TEST_WP_PATH:-}"
  [[ -n "$wp_path" && -f "$wp_path/wp-config.php" ]] || fail 'set WOO_ALIPAY_TEST_WP_PATH to an isolated test WordPress installation'
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
