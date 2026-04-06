#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-all}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WENPAI_WP_PATH="${WENPAI_WP_PATH:-/home/parallels/.local/share/Trash/files/linuxjoy.com_dZWxE(1)/linuxjoy.com}"
REMOTE_WORKDIR="${REMOTE_WORKDIR:-/tmp/woo-alipay-work-remote}"

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

pass() {
  echo -e "${GREEN}[PASS]${NC} $1"
}

fail() {
  echo -e "${RED}[FAIL]${NC} $1"
  exit 1
}

info() {
  echo -e "${YELLOW}[INFO]${NC} $1"
}

run_static_lint() {
  info "Running PHP syntax checks"
  local lint_fail=0
  while IFS= read -r -d '' file; do
    if ! php -l "$file" >/dev/null 2>&1; then
      echo "LINT_FAIL:$file"
      lint_fail=1
    fi
  done < <(find "$ROOT_DIR" -type f -name '*.php' -print0)

  if [[ "$lint_fail" -ne 0 ]]; then
    fail "PHP lint failed"
  fi
  pass "PHP lint all ok"
}

deploy_to_wenpai() {
  info "Deploying current working tree to wenpai"
  COPYFILE_DISABLE=1 tar --no-xattrs --exclude='.git' -C "$ROOT_DIR" -cf - . | ssh -T wenpai "set -e; rm -rf '$REMOTE_WORKDIR'; mkdir -p '$REMOTE_WORKDIR'; tar -xf - -C '$REMOTE_WORKDIR'"
  ssh -T wenpai "set -e; TARGET='$WENPAI_WP_PATH/wp-content/plugins/woo-alipay'; rm -rf \"\$TARGET\"; mkdir -p \"\$TARGET\"; cp -R '$REMOTE_WORKDIR'/* \"\$TARGET\"/; wp --path='$WENPAI_WP_PATH' plugin deactivate woo-alipay >/dev/null 2>&1 || true; wp --path='$WENPAI_WP_PATH' plugin activate woo-alipay >/dev/null"
  pass "Plugin deployed and activated on wenpai"
}

run_runtime_checks() {
  info "Running runtime gateway/class checks on wenpai"
  ssh -T wenpai 'bash -s' <<'BASH'
set -e
WENPAI_WP_PATH='/home/parallels/.local/share/Trash/files/linuxjoy.com_dZWxE(1)/linuxjoy.com'
cat > /tmp/woo-alipay-runtime-check.php <<'PHP'
<?php
if ( ! function_exists( 'WC' ) ) { echo 'woocommerce_runtime:missing\n'; return; }
$container = WC()->payment_gateways();
if ( ! $container || ! method_exists( $container, 'payment_gateways' ) ) { echo 'gateway_container:missing\n'; return; }
$g = $container->payment_gateways();
echo isset( $g['alipay'] ) ? 'alipay_gateway:ok\n' : 'alipay_gateway:missing\n';
echo class_exists( 'Woo_Alipay_Mini_Program_Gateway' ) ? 'mini_class:ok\n' : 'mini_class:missing\n';
echo class_exists( 'Woo_Alipay_Cross_Border_Gateway' ) ? 'cross_class:ok\n' : 'cross_class:missing\n';
PHP
wp --path="$WENPAI_WP_PATH" eval-file /tmp/woo-alipay-runtime-check.php
BASH

  local output
  output="$(ssh -T wenpai "wp --path='$WENPAI_WP_PATH' eval-file /tmp/woo-alipay-runtime-check.php")"
  echo "$output"
  [[ "$output" == *"alipay_gateway:ok"* ]] || fail "alipay gateway missing"
  [[ "$output" == *"mini_class:ok"* ]] || fail "mini class missing"
  [[ "$output" == *"cross_class:ok"* ]] || fail "cross class missing"
  pass "Runtime checks passed"
}

run_notify_replay() {
  info "Running mini/cross notify replay checks on wenpai"
  ssh -T wenpai 'bash -s' <<'BASH' > /tmp/woo-alipay-replay.out
set -e
WENPAI_WP_PATH='/home/parallels/.local/share/Trash/files/linuxjoy.com_dZWxE(1)/linuxjoy.com'

cat > /tmp/alipay-e2e-prepare.php <<'PHP'
<?php
$private_path = '/tmp/alipay-test-private.pem';
$public_path  = '/tmp/alipay-test-public.pem';
if ( ! file_exists( $private_path ) || ! file_exists( $public_path ) ) {
    exec( 'openssl genrsa -out /tmp/alipay-test-private.pem 2048 >/dev/null 2>&1' );
    exec( 'openssl rsa -in /tmp/alipay-test-private.pem -pubout -out /tmp/alipay-test-public.pem >/dev/null 2>&1' );
}
$private_pem = file_get_contents( $private_path );
$public_pem  = file_get_contents( $public_path );
$private_body = trim( str_replace( array( '-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', "\r", "\n" ), '', $private_pem ) );
$public_body  = trim( str_replace( array( '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n" ), '', $public_pem ) );
update_option( 'woocommerce_alipay_settings', array(
    'enabled'     => 'yes',
    'title'       => 'Alipay',
    'appid'       => '2026040600000001',
    'private_key' => $private_body,
    'public_key'  => $public_body,
    'sandbox'     => 'yes',
) );

$build_notify = static function( $prefix, $trade_prefix ) use ( $private_pem ) {
    $order = wc_create_order();
    $fee = new WC_Order_Item_Fee();
    $fee->set_name( 'Replay' );
    $fee->set_amount( 0.01 );
    $fee->set_total( 0.01 );
    $order->add_item( $fee );
    $order->calculate_totals();
    $order->update_status( 'pending', 'Replay order' );
    $out_trade_no = $prefix . $order->get_id() . '-' . time();
    $order->update_meta_data( '_alipay_out_trade_no', $out_trade_no );
    $order->save();

    $params = array(
        'notify_time'  => gmdate( 'Y-m-d H:i:s' ),
        'notify_type'  => 'trade_status_sync',
        'notify_id'    => 'SIM' . wp_generate_password( 16, false, false ),
        'out_trade_no' => $out_trade_no,
        'trade_no'     => $trade_prefix . time(),
        'trade_status' => 'TRADE_SUCCESS',
        'total_amount' => '0.01',
        'seller_id'    => '2088100000000000',
        'charset'      => 'UTF-8',
        'sign_type'    => 'RSA2',
    );

    $work = $params;
    unset( $work['sign'], $work['sign_type'] );
    ksort( $work );
    $parts = array();
    foreach ( $work as $k => $v ) {
        if ( '' === (string) $v ) {
            continue;
        }
        $parts[] = $k . '=' . $v;
    }
    openssl_sign( implode( '&', $parts ), $raw_sign, $private_pem, OPENSSL_ALGO_SHA256 );
    $params['sign'] = base64_encode( $raw_sign );

    return array( $order->get_id(), $params );
};

list( $mini_order_id, $mini_notify ) = $build_notify( 'WAP-', 'SIMWAP' );
list( $cross_order_id, $cross_notify ) = $build_notify( 'CB-', 'SIMCB' );

file_put_contents( '/tmp/alipay-mini-order-id.txt', (string) $mini_order_id );
file_put_contents( '/tmp/alipay-cross-order-id.txt', (string) $cross_order_id );
file_put_contents( '/tmp/alipay-mini-notify.json', wp_json_encode( $mini_notify ) );
file_put_contents( '/tmp/alipay-cross-notify.json', wp_json_encode( $cross_notify ) );
echo "prepare:ok\n";
PHP

cat > /tmp/alipay-e2e-mini-trigger.php <<'PHP'
<?php
$_POST = json_decode( file_get_contents( '/tmp/alipay-mini-notify.json' ), true ) ?: array();
$gateway = new Woo_Alipay_Mini_Program_Gateway();
$gateway->check_alipay_response();
PHP

cat > /tmp/alipay-e2e-cross-trigger.php <<'PHP'
<?php
$_POST = json_decode( file_get_contents( '/tmp/alipay-cross-notify.json' ), true ) ?: array();
$gateway = new Woo_Alipay_Cross_Border_Gateway();
$gateway->check_alipay_response();
PHP

cat > /tmp/alipay-e2e-check.php <<'PHP'
<?php
$mini_order_id = absint( trim( file_get_contents( '/tmp/alipay-mini-order-id.txt' ) ) );
$cross_order_id = absint( trim( file_get_contents( '/tmp/alipay-cross-order-id.txt' ) ) );
$mini_order = wc_get_order( $mini_order_id );
$cross_order = wc_get_order( $cross_order_id );
echo 'mini_status:' . $mini_order->get_status() . ':paid:' . ( $mini_order->is_paid() ? 'yes' : 'no' ) . '\n';
echo 'cross_status:' . $cross_order->get_status() . ':paid:' . ( $cross_order->is_paid() ? 'yes' : 'no' ) . '\n';
PHP

wp --path="$WENPAI_WP_PATH" eval-file /tmp/alipay-e2e-prepare.php
wp --path="$WENPAI_WP_PATH" eval-file /tmp/alipay-e2e-mini-trigger.php || true
wp --path="$WENPAI_WP_PATH" eval-file /tmp/alipay-e2e-cross-trigger.php || true
wp --path="$WENPAI_WP_PATH" eval-file /tmp/alipay-e2e-check.php
BASH

  cat /tmp/woo-alipay-replay.out
  rg -q 'mini_status:completed:paid:yes' /tmp/woo-alipay-replay.out || fail "mini replay failed"
  rg -q 'cross_status:completed:paid:yes' /tmp/woo-alipay-replay.out || fail "cross replay failed"
  pass "Notify replay checks passed"
}

case "$MODE" in
  static)
    run_static_lint
    ;;
  runtime)
    deploy_to_wenpai
    run_runtime_checks
    ;;
  replay)
    deploy_to_wenpai
    run_notify_replay
    ;;
  all)
    run_static_lint
    deploy_to_wenpai
    run_runtime_checks
    run_notify_replay
    ;;
  *)
    echo "Usage: $0 [static|runtime|replay|all]"
    exit 2
    ;;
esac

pass "run-tests.sh ($MODE) completed"
