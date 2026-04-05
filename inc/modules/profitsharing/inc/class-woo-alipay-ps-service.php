<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Woo_Alipay_PS_Service {
    protected static $logger = null;
    const JOBS_OPTION = 'woo_alipay_ps_jobs';
    const AS_GROUP    = 'woo_alipay_ps';

    // Common error hint catalog (extend as needed)
    protected static $error_hints = array(
        'ACQ.SYSTEM_ERROR'           => '网关系统繁忙，请稍后重试。',
        'ACQ.INVALID_PARAMETER'      => '参数不正确，请检查身份信息与金额格式。',
        'ACQ.ACCESS_FORBIDDEN'       => '无权限调用接口，请检查商户资质与授权。',
        'ACQ.TRADE_NOT_EXIST'        => '交易不存在，请确认 trade_no 是否正确。',
        'ACQ.PAYMENT_INFO_INCONSISTENT' => '交易信息不一致，请检查金额与订单信息。',
    );

    public static function logger() {
        if ( null === self::$logger && function_exists( 'wc_get_logger' ) ) {
            self::$logger = wc_get_logger();
        }
        return self::$logger;
    }

    public static function log( $level, $message, $context = array() ) {
        $logger = self::logger();
        $msg = is_string( $message ) ? $message : wp_json_encode( $message, JSON_UNESCAPED_UNICODE );
        if ( $logger ) {
            $logger->log( $level, $msg, array_merge( array( 'source' => 'alipay_profitsharing' ), $context ) );
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            @error_log( '[alipay_profitsharing][' . strtoupper( (string) $level ) . '] ' . $msg );
        }
    }

    public static function get_aop() {
        // WooCommerce includes Action Scheduler; we may use it if functions exist.
        // For queue fallback we use WP-Cron below.
        if ( ! defined( 'WOO_ALIPAY_PLUGIN_PATH' ) ) {
            self::log( 'error', 'WOO_ALIPAY_PLUGIN_PATH not defined; Woo Alipay core not active?' );
            return null;
        }
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeOrderSettleRequest.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayTradeOrderSettleQueryRequest.php';

        $core   = get_option( 'woocommerce_alipay_settings', array() );
        $config = Alipay_SDK_Helper::get_alipay_config( array(
            'appid'       => $core['appid'] ?? '',
            'private_key' => $core['private_key'] ?? '',
            'public_key'  => $core['public_key'] ?? '',
            'sandbox'     => $core['sandbox'] ?? 'no',
        ) );
        $aop = Alipay_SDK_Helper::create_alipay_service( $config );
        if ( ! $aop ) {
            self::log( 'error', 'Failed to create AOP client; check credentials.' );
        }
        return $aop;
    }

    public static function settings() {
        $defaults = array(
            'execution_policy' => 'delayed',
            'process_mode'     => 'queue', // queue|sync
            'base'             => 'order_total_minus_shipping',
            'round'            => 2,
            'min_amount'       => '0.01',
            'out_prefix'       => 'PS',
            'desc_template'    => 'Woo Profit Sharing',
            'tail_strategy'    => 'largest', // none|largest|first|receiver
            'tail_receiver_id' => '',
        );
        $settings = get_option( 'woo_alipay_ps_settings', array() );
        return wp_parse_args( $settings, $defaults );
    }

    public static function generate_out_request_no( $prefix = 'PS' ) {
        // Legacy random generator (kept for backward compatibility or ad-hoc use)
        return sprintf( '%s-%s%s-%d-%04d', $prefix, is_multisite() ? get_current_blog_id() . '-' : '', get_current_user_id(), current_time( 'timestamp' ), wp_rand( 1000, 9999 ) );
    }

    protected static function get_order_trade_no( WC_Order $order ) {
        $tx = $order->get_transaction_id();
        if ( ! empty( $tx ) ) { return $tx; }
        $candidates = array( '_alipay_trade_no', '_wooalipay_trade_no', '_transaction_id', 'alipay_trade_no' );
        foreach ( $candidates as $key ) {
            $v = $order->get_meta( $key, true );
            if ( ! empty( $v ) ) { return (string) $v; }
        }
        return '';
    }

    public static function compute_jobs_for_order( WC_Order $order ) {
        $settings  = self::settings();
        $round     = (int) $settings['round'];
        $min_amt   = (float) $settings['min_amount'];
        $base_mode = (string) $settings['base'];

        // Base: order total minus shipping by default
        $base_amount = (float) $order->get_total();
        if ( 'order_total_minus_shipping' === $base_mode ) {
            $base_amount -= (float) $order->get_shipping_total();
            $base_amount -= (float) $order->get_shipping_tax();
        }
        if ( $base_amount <= 0 ) { return array(); }

        $receivers = get_option( 'woo_alipay_ps_receivers', array() );
        $rules     = get_option( 'woo_alipay_ps_rules', array( 'default' => array(), 'category' => array(), 'product' => array() ) );
        $default   = isset( $rules['default'] ) && is_array( $rules['default'] ) ? $rules['default'] : array();
        $cat_rules = isset( $rules['category'] ) && is_array( $rules['category'] ) ? $rules['category'] : array();
        $prd_rules = isset( $rules['product'] ) && is_array( $rules['product'] ) ? $rules['product'] : array();

        // Build desc from template with placeholders
        $tpl  = (string) ( $settings['desc_template'] ?? 'Woo Profit Sharing' );
        $desc = str_replace( array( '{order_id}', '{order_number}' ), array( (string) $order->get_id(), (string) $order->get_order_number() ), $tpl );

        // Distribute base_amount proportionally by item subtotals (incl. tax)
        // Priority: product rules > category rules > default
        $items = $order->get_items( 'line_item' );
        $items_sum = 0.0;
        $cat_sums  = array(); // cat_id => sum for items without product rules
        $cat_keys_with_rules = array_map( 'strval', array_keys( $cat_rules ) );
        $alloc = array(); // receiver_id => amount
        $used_by_product = 0.0;

        foreach ( $items as $item ) {
            $line_amount = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
            if ( $line_amount <= 0 ) { continue; }
            $items_sum += $line_amount;
            $product = $item->get_product();

            // Product-level rules highest priority
            $pid_str = '';
            if ( $product && method_exists( $product, 'get_id' ) ) {
                $pid_str = (string) $product->get_id();
            }
            if ( $pid_str && isset( $prd_rules[ $pid_str ] ) && is_array( $prd_rules[ $pid_str ] ) ) {
                foreach ( $prd_rules[ $pid_str ] as $rule ) {
                    $rid = isset( $rule['receiver_id'] ) ? (string) $rule['receiver_id'] : '';
                    $pct = isset( $rule['percent'] ) ? (float) $rule['percent'] : 0;
                    if ( $pct <= 0 || empty( $rid ) || empty( $receivers[ $rid ] ) ) { continue; }
                    if ( ! isset( $alloc[ $rid ] ) ) { $alloc[ $rid ] = 0.0; }
                    $alloc[ $rid ] += $line_amount * $pct / 100;
                }
                $used_by_product += $line_amount;
                continue; // skip category/default for this item
            }

            // Otherwise consider category rules
            $assigned = false;
            if ( $product && method_exists( $product, 'get_category_ids' ) ) {
                $cids = $product->get_category_ids();
                if ( is_array( $cids ) && ! empty( $cids ) ) {
                    // Pick the first category that has rules
                    foreach ( $cids as $cid ) {
                        $cid_str = (string) $cid;
                        if ( in_array( $cid_str, $cat_keys_with_rules, true ) ) {
                            if ( ! isset( $cat_sums[ $cid_str ] ) ) { $cat_sums[ $cid_str ] = 0.0; }
                            $cat_sums[ $cid_str ] += $line_amount;
                            $assigned = true;
                            break;
                        }
                    }
                }
            }
            // Unassigned lines will fall back to default later
        }

        if ( $items_sum > 0 && ! empty( $cat_sums ) ) {
            $scale = $base_amount / $items_sum;
            $used  = 0.0;
            foreach ( $cat_sums as $cid => $sum ) {
                $cat_base = $sum * $scale;
                $used += $cat_base;
                $rules_for_cat = isset( $cat_rules[ $cid ] ) && is_array( $cat_rules[ $cid ] ) ? $cat_rules[ $cid ] : array();
                foreach ( $rules_for_cat as $rule ) {
                    $rid = isset( $rule['receiver_id'] ) ? (string) $rule['receiver_id'] : '';
                    $pct = isset( $rule['percent'] ) ? (float) $rule['percent'] : 0;
                    if ( $pct <= 0 || empty( $rid ) || empty( $receivers[ $rid ] ) ) { continue; }
                    $amount = $cat_base * $pct / 100;
                    if ( ! isset( $alloc[ $rid ] ) ) { $alloc[ $rid ] = 0.0; }
                    $alloc[ $rid ] += $amount;
                }
            }
            $remaining = max( 0.0, $base_amount - $used );
            if ( $remaining > 0 && ! empty( $default ) ) {
                foreach ( $default as $rule ) {
                    $rid = isset( $rule['receiver_id'] ) ? (string) $rule['receiver_id'] : '';
                    $pct = isset( $rule['percent'] ) ? (float) $rule['percent'] : 0;
                    if ( $pct <= 0 || empty( $rid ) || empty( $receivers[ $rid ] ) ) { continue; }
                    if ( ! isset( $alloc[ $rid ] ) ) { $alloc[ $rid ] = 0.0; }
                    $alloc[ $rid ] += $remaining * $pct / 100;
                }
            }
        } else {
            // No category rules or no item sums => apply default to whole base
            foreach ( $default as $rule ) {
                $rid = isset( $rule['receiver_id'] ) ? (string) $rule['receiver_id'] : '';
                $pct = isset( $rule['percent'] ) ? (float) $rule['percent'] : 0;
                if ( $pct <= 0 || empty( $rid ) || empty( $receivers[ $rid ] ) ) { continue; }
                if ( ! isset( $alloc[ $rid ] ) ) { $alloc[ $rid ] = 0.0; }
                $alloc[ $rid ] += $base_amount * $pct / 100;
            }
        }

        // Apply threshold filter first, then round and allocate tail difference
        $kept = array(); // rid => unrounded amount
        foreach ( $alloc as $rid => $amount ) {
            $rcv = $receivers[ $rid ] ?? null;
            if ( ! $rcv || empty( $rcv['enabled'] ) ) { continue; }
            if ( (float) $amount + 1e-8 < $min_amt ) { continue; }
            $kept[ $rid ] = (float) $amount;
        }
        if ( empty( $kept ) ) { return array(); }

        $sum_kept = 0.0;
        foreach ( $kept as $a ) { $sum_kept += (float) $a; }
        $unit = pow( 10, -$round );

        $rounded = array();
        $sum_rounded = 0.0;
        foreach ( $kept as $rid => $amount ) {
            $r = round( (float) $amount, $round );
            $rounded[ $rid ] = $r;
            $sum_rounded += $r;
        }
        $target = round( $sum_kept, $round );
        $residual = $target - $sum_rounded; // could be negative/positive up to a few units

        if ( abs( $residual ) >= 0.5 * $unit ) {
            $strat = isset( $settings['tail_strategy'] ) ? (string) $settings['tail_strategy'] : 'largest';
            $assign_rid = '';
            if ( 'receiver' === $strat && ! empty( $settings['tail_receiver_id'] ) && isset( $rounded[ $settings['tail_receiver_id'] ] ) ) {
                $assign_rid = (string) $settings['tail_receiver_id'];
            } elseif ( 'first' === $strat ) {
                $keys = array_keys( $rounded );
                $assign_rid = (string) reset( $keys );
            } elseif ( 'largest' === $strat ) {
                $maxv = null; $maxrid = '';
                foreach ( $rounded as $rid => $v ) {
                    if ( null === $maxv || $v > $maxv ) { $maxv = $v; $maxrid = (string) $rid; }
                }
                $assign_rid = $maxrid;
            }
            if ( $assign_rid && isset( $rounded[ $assign_rid ] ) ) {
                $rounded[ $assign_rid ] = round( $rounded[ $assign_rid ] + $residual, $round );
            }
        }

        $jobs = array();
        foreach ( $rounded as $rid => $amt ) {
            $rcv = $receivers[ $rid ] ?? null;
            if ( ! $rcv ) { continue; }
            if ( $amt + 1e-8 < $min_amt ) { continue; }
            $jobs[] = array(
                'receiver_id'   => (string) $rid,
                'label'         => (string) ( $rcv['label'] ?? '' ),
                'identity'      => (string) ( $rcv['identity'] ?? '' ),
                'identity_type' => (string) ( $rcv['identity_type'] ?? 'ALIPAY_LOGON_ID' ),
                'amount'        => (float) $amt,
                'desc'          => (string) $desc,
            );
        }

        return $jobs;
    }

    public static function compute_jobs_for_refund( WC_Order $order, WC_Order $refund ) {
        $settings  = self::settings();
        $round     = (int) $settings['round'];
        $min_amt   = (float) $settings['min_amount'];

        $receivers = get_option( 'woo_alipay_ps_receivers', array() );
        $rules     = get_option( 'woo_alipay_ps_rules', array( 'default' => array(), 'category' => array(), 'product' => array() ) );
        $default   = isset( $rules['default'] ) && is_array( $rules['default'] ) ? $rules['default'] : array();
        $cat_rules = isset( $rules['category'] ) && is_array( $rules['category'] ) ? $rules['category'] : array();
        $prd_rules = isset( $rules['product'] ) && is_array( $rules['product'] ) ? $rules['product'] : array();

        $tpl  = (string) ( $settings['desc_template'] ?? 'Woo Profit Sharing' );
        $desc = str_replace( array( '{order_id}', '{order_number}' ), array( (string) $order->get_id(), (string) $order->get_order_number() ), $tpl );

        $items = $refund->get_items( 'line_item' );
        $items_sum = 0.0;
        $cat_sums  = array();
        $alloc     = array();
        $cat_keys_with_rules = array_map( 'strval', array_keys( $cat_rules ) );

        foreach ( $items as $item ) {
            $line_amount = abs( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax() );
            if ( $line_amount <= 0 ) { continue; }
            $items_sum += $line_amount;
            $product = $item->get_product();

            $pid_str = '';
            if ( $product && method_exists( $product, 'get_id' ) ) { $pid_str = (string) $product->get_id(); }
            if ( $pid_str && isset( $prd_rules[ $pid_str ] ) && is_array( $prd_rules[ $pid_str ] ) ) {
                foreach ( $prd_rules[ $pid_str ] as $rule ) {
                    $rid = isset( $rule['receiver_id'] ) ? (string) $rule['receiver_id'] : '';
                    $pct = isset( $rule['percent'] ) ? (float) $rule['percent'] : 0;
                    if ( $pct <= 0 || empty( $rid ) || empty( $receivers[ $rid ] ) ) { continue; }
                    if ( ! isset( $alloc[ $rid ] ) ) { $alloc[ $rid ] = 0.0; }
                    $alloc[ $rid ] += $line_amount * $pct / 100;
                }
                continue;
            }

            $assigned = false;
            if ( $product && method_exists( $product, 'get_category_ids' ) ) {
                $cids = $product->get_category_ids();
                if ( is_array( $cids ) && ! empty( $cids ) ) {
                    foreach ( $cids as $cid ) {
                        $cid_str = (string) $cid;
                        if ( in_array( $cid_str, $cat_keys_with_rules, true ) ) {
                            if ( ! isset( $cat_sums[ $cid_str ] ) ) { $cat_sums[ $cid_str ] = 0.0; }
                            $cat_sums[ $cid_str ] += $line_amount;
                            $assigned = true;
                            break;
                        }
                    }
                }
            }
            // Unassigned lines fall back to default later
        }

        if ( $items_sum > 0 && ! empty( $cat_sums ) ) {
            $scale = 1.0; // already items-only base
            foreach ( $cat_sums as $cid => $sum ) {
                $cat_base = $sum * $scale;
                $rules_for_cat = isset( $cat_rules[ $cid ] ) && is_array( $cat_rules[ $cid ] ) ? $cat_rules[ $cid ] : array();
                foreach ( $rules_for_cat as $rule ) {
                    $rid = isset( $rule['receiver_id'] ) ? (string) $rule['receiver_id'] : '';
                    $pct = isset( $rule['percent'] ) ? (float) $rule['percent'] : 0;
                    if ( $pct <= 0 || empty( $rid ) || empty( $receivers[ $rid ] ) ) { continue; }
                    $amount = $cat_base * $pct / 100;
                    if ( ! isset( $alloc[ $rid ] ) ) { $alloc[ $rid ] = 0.0; }
                    $alloc[ $rid ] += $amount;
                }
            }
        }

        // Default share for remaining amount if default rules exist and alloc total < items_sum
        $allocated_sum = 0.0; foreach ( $alloc as $v ) { $allocated_sum += (float) $v; }
        $remaining = max( 0.0, $items_sum - $allocated_sum );
        if ( $remaining > 0 && ! empty( $default ) ) {
            foreach ( $default as $rule ) {
                $rid = isset( $rule['receiver_id'] ) ? (string) $rule['receiver_id'] : '';
                $pct = isset( $rule['percent'] ) ? (float) $rule['percent'] : 0;
                if ( $pct <= 0 || empty( $rid ) || empty( $receivers[ $rid ] ) ) { continue; }
                if ( ! isset( $alloc[ $rid ] ) ) { $alloc[ $rid ] = 0.0; }
                $alloc[ $rid ] += $remaining * $pct / 100;
            }
        }

        // Threshold and rounding similar to order compute
        $kept = array(); foreach ( $alloc as $rid => $amount ) {
            $rcv = $receivers[ $rid ] ?? null; if ( ! $rcv || empty( $rcv['enabled'] ) ) { continue; }
            if ( (float) $amount + 1e-8 < $min_amt ) { continue; }
            $kept[ $rid ] = (float) $amount;
        }
        if ( empty( $kept ) ) { return array(); }
        $sum_kept = 0.0; foreach ( $kept as $a ) { $sum_kept += (float) $a; }
        $rounded = array(); $sum_rounded = 0.0;
        foreach ( $kept as $rid => $amount ) { $r = round( (float) $amount, $round ); $rounded[ $rid ] = $r; $sum_rounded += $r; }
        $target = round( $sum_kept, $round ); $residual = $target - $sum_rounded; $unit = pow(10, -$round);
        if ( abs( $residual ) >= 0.5 * $unit ) {
            $strat = isset( $settings['tail_strategy'] ) ? (string) $settings['tail_strategy'] : 'largest'; $assign_rid = '';
            if ( 'receiver' === $strat && ! empty( $settings['tail_receiver_id'] ) && isset( $rounded[ $settings['tail_receiver_id'] ] ) ) { $assign_rid = (string) $settings['tail_receiver_id']; }
            elseif ( 'first' === $strat ) { $keys = array_keys( $rounded ); $assign_rid = (string) reset( $keys ); }
            elseif ( 'largest' === $strat ) { $maxv = null; $maxrid = ''; foreach ( $rounded as $rid => $v ) { if ( null === $maxv || $v > $maxv ) { $maxv = $v; $maxrid = (string) $rid; } } $assign_rid = $maxrid; }
            if ( $assign_rid && isset( $rounded[ $assign_rid ] ) ) { $rounded[ $assign_rid ] = round( $rounded[ $assign_rid ] + $residual, $round ); }
        }

        $jobs = array();
        foreach ( $rounded as $rid => $amt ) {
            $rcv = $receivers[ $rid ] ?? null; if ( ! $rcv ) { continue; }
            if ( $amt + 1e-8 < $min_amt ) { continue; }
            $jobs[] = array(
                'receiver_id'   => (string) $rid,
                'label'         => (string) ( $rcv['label'] ?? '' ),
                'identity'      => (string) ( $rcv['identity'] ?? '' ),
                'identity_type' => (string) ( $rcv['identity_type'] ?? 'ALIPAY_LOGON_ID' ),
                'amount'        => (float) $amt,
                'desc'          => (string) $desc,
            );
        }
        return $jobs;
    }

    public static function build_out_request_no_for_order( WC_Order $order, $round = 0 ) {
        $settings = self::settings();
        $prefix   = (string) ( $settings['out_prefix'] ?? 'PS' );
        $blog     = is_multisite() ? get_current_blog_id() . '-' : '';
        $base     = sprintf( '%s-%s%d', $prefix, $blog, $order->get_id() );
        if ( $round && (int) $round > 0 ) {
            $base .= '-r' . (int) $round;
        }
        return $base;
    }

    protected static function get_or_build_out_request_no_for_order( WC_Order $order ) {
        $existing = (string) $order->get_meta( '_alipay_ps_out_request_no', true );
        if ( ! empty( $existing ) ) { return $existing; }
        $built = self::build_out_request_no_for_order( $order, 0 );
        $order->update_meta_data( '_alipay_ps_out_request_no', $built );
        $order->save();
        return $built;
    }

    public static function settle_order( WC_Order $order, array $jobs, array $context = array() ) {
        $trade_no = self::get_order_trade_no( $order );
        if ( empty( $trade_no ) ) {
            self::log( 'error', 'Missing trade_no for order', array( 'order_id' => $order->get_id() ) );
            self::append_trace( $order, array( 'error' => 'missing_trade_no' ) );
            return array( 'error' => __( '未找到该订单的支付宝交易号（trade_no）。', 'woo-alipay-profitsharing' ) );
        }

        $aop = self::get_aop();
        if ( ! $aop ) {
            return array( 'error' => __( '无法创建支付宝客户端，请检查凭据。', 'woo-alipay-profitsharing' ) );
        }

        // Prefer deterministic out_request_no and keep stable across retries
        $out_request_no = ! empty( $context['out_request_no'] ) ? (string) $context['out_request_no'] : self::get_or_build_out_request_no_for_order( $order );

        $royalties = array();
        foreach ( $jobs as $job ) {
            $royalties[] = array(
                'royalty_type'   => 'transfer',
                'trans_in_type'  => ( 'ALIPAY_USER_ID' === (string) $job['identity_type'] ) ? 'userId' : 'loginName',
                'trans_in'       => (string) $job['identity'],
                'amount'         => number_format( (float) $job['amount'], 2, '.', '' ),
                'desc'           => (string) ( $job['desc'] ?? '' ),
            );
        }
        if ( empty( $royalties ) ) {
            return array( 'error' => __( '没有可结算的分账项。', 'woo-alipay-profitsharing' ) );
        }

        $biz = array(
            'out_request_no'    => $out_request_no,
            'trade_no'          => (string) $trade_no,
            'royalty_parameters'=> $royalties,
        );

        $req = new AlipayTradeOrderSettleRequest();
        $req->setBizContent( wp_json_encode( $biz, JSON_UNESCAPED_UNICODE ) );

        try {
            self::log( 'info', 'Submitting settle request', array( 'order_id' => $order->get_id(), 'out_request_no' => $out_request_no ) );
            $resp = $aop->execute( $req );
            $node = 'alipay_trade_order_settle_response';
            $res  = $resp->$node ?? null;
            if ( $res && isset( $res->code ) && '10000' === (string) $res->code ) {
                $data = array(
                    'success'        => true,
                    'out_request_no' => (string) $out_request_no,
                    'trade_no'       => (string) $trade_no,
                    'msg'            => (string) ( $res->msg ?? '' ),
                );
                self::append_trace( $order, array( 'ok' => $data ) );
                // Do NOT mark as settled yet; wait for query confirmation
                self::log( 'info', 'Settle accepted (waiting confirmation)', $data );
                return $data;
            }
            $sub_code = isset( $res->sub_code ) ? (string) $res->sub_code : '';
            $msg = ( $res->sub_msg ?? $res->msg ?? __( '分账结算失败', 'woo-alipay-profitsharing' ) );
            $err = array( 'error' => (string) $msg, 'out_request_no' => $out_request_no, 'trade_no' => $trade_no, 'hint' => self::hint_for_error( (string) $msg, $sub_code ) );
            self::append_trace( $order, array( 'error' => $err ) );
            self::log( 'error', 'Settle failed: ' . $msg, $err );
            return $err;
        } catch ( Exception $e ) {
            $err = array( 'error' => $e->getMessage(), 'out_request_no' => $out_request_no, 'trade_no' => $trade_no );
            self::append_trace( $order, array( 'exception' => $err ) );
            self::log( 'error', 'Settle exception: ' . $e->getMessage(), $err );
            return array( 'error' => $e->getMessage() );
        }
    }

    protected static function append_trace( WC_Order $order, array $entry ) {
        $trace = (array) $order->get_meta( '_alipay_settle_trace', true );
        $entry['time'] = current_time( 'mysql' );
        $trace[] = $entry;
        $order->update_meta_data( '_alipay_settle_trace', $trace );
        $order->save();
    }

    protected static function hint_for_error( $msg, $sub_code = '' ) {
        $sub_code = (string) $sub_code;
        if ( isset( self::$error_hints[ $sub_code ] ) ) {
            return self::$error_hints[ $sub_code ];
        }
        if ( stripos( $msg, '参数' ) !== false || stripos( $msg, 'invalid' ) !== false ) {
            return '请检查接口参数（账号类型/账号、金额、out_request_no）是否正确。';
        }
        return '';
    }

    public static function query_settle( $args ) {
        $args = wp_parse_args( $args, array( 'out_request_no' => '', 'trade_no' => '' ) );
        if ( empty( $args['out_request_no'] ) && empty( $args['trade_no'] ) ) {
            return array( 'error' => __( '需要提供 out_request_no 或 trade_no。', 'woo-alipay-profitsharing' ) );
        }
        $aop = self::get_aop();
        if ( ! $aop ) {
            return array( 'error' => __( '无法创建支付宝客户端，请检查凭据。', 'woo-alipay-profitsharing' ) );
        }
        $biz = array();
        if ( ! empty( $args['out_request_no'] ) ) { $biz['out_request_no'] = (string) $args['out_request_no']; }
        if ( ! empty( $args['trade_no'] ) )       { $biz['trade_no']       = (string) $args['trade_no']; }

        $req = new AlipayTradeOrderSettleQueryRequest();
        $req->setBizContent( wp_json_encode( $biz, JSON_UNESCAPED_UNICODE ) );
        try {
            self::log( 'info', 'Query settle', $biz );
            $resp = $aop->execute( $req );
            $node = 'alipay_trade_order_settle_query_response';
            $res  = $resp->$node ?? null;
            if ( $res && isset( $res->code ) && '10000' === (string) $res->code ) {
                $data = array(
                    'success'        => true,
                    'trade_no'       => (string) ( $res->trade_no ?? '' ),
                    'out_request_no' => (string) ( $res->out_request_no ?? '' ),
                    'result_code'    => (string) ( $res->result_code ?? '' ),
                    'msg'            => (string) ( $res->msg ?? '' ),
                    'sub_msg'        => (string) ( $res->sub_msg ?? '' ),
                );
                // Map royalty detail list if available
                if ( isset( $res->royalty_detail_list ) ) {
                    $data['details'] = json_decode( wp_json_encode( $res->royalty_detail_list ), true );
                } elseif ( isset( $res->royalty_parameters ) ) {
                    $data['details'] = json_decode( wp_json_encode( $res->royalty_parameters ), true );
                }
                self::log( 'info', 'Query settle success', $data );
                return $data;
            }
            $sub_code = isset( $res->sub_code ) ? (string) $res->sub_code : '';
            $msg = ( $res->sub_msg ?? $res->msg ?? __( '查询失败', 'woo-alipay-profitsharing' ) );
            self::log( 'error', 'Query settle failed: ' . $msg, $biz );
            return array( 'error' => (string) $msg, 'hint' => self::hint_for_error( (string) $msg, $sub_code ), 'raw' => $resp );
        } catch ( Exception $e ) {
            self::log( 'error', 'Query settle exception: ' . $e->getMessage(), $biz );
            return array( 'error' => $e->getMessage() );
        }
    }

    // Queue management (M2): enqueue and process jobs via Action Scheduler if available, otherwise WP-Cron fallback
    public static function has_as() {
        return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' );
    }

    public static function schedule_as_job( $order_id, $phase = 'settle', $delay = 0 ) {
        $args = array( 'order_id' => (int) $order_id, 'phase' => (string) $phase );
        if ( self::has_as() ) {
            if ( $delay > 0 ) {
                as_schedule_single_action( time() + (int) $delay, 'woo_alipay_ps_run_job', $args, self::AS_GROUP );
                return true;
            }
            if ( false === as_next_scheduled_action( 'woo_alipay_ps_run_job', $args, self::AS_GROUP ) ) {
                as_enqueue_async_action( 'woo_alipay_ps_run_job', $args, self::AS_GROUP );
            }
            return true;
        }
        // Fallback to WP-Cron single event
        if ( function_exists( 'wp_schedule_single_event' ) ) {
            $when = time() + ( $delay > 0 ? (int) $delay : 5 );
            wp_schedule_single_event( $when, 'woo_alipay_ps_run_job', $args );
            return true;
        }
        return false;
    }

    public static function run_as_job( $order_id, $phase = 'settle' ) {
        $order = wc_get_order( (int) $order_id );
        if ( ! $order ) { return; }
        // Guards
        $currency = $order->get_currency();
        $method   = (string) $order->get_payment_method();
        if ( strtoupper( $currency ) !== 'CNY' ) { return; }
        if ( false === stripos( $method, 'alipay' ) ) { return; }

        $settings = self::settings();
        $confirm_interval = isset( $settings['confirm_interval'] ) ? max( 30, (int) $settings['confirm_interval'] ) : 60;
        $maxc = isset( $settings['confirm_max_checks'] ) ? max( 1, (int) $settings['confirm_max_checks'] ) : 10;

        if ( 'confirm' === (string) $phase ) {
            // increment confirm checks
            $cc = (int) $order->get_meta( '_alipay_ps_confirm_checks', true );
            $cc++;
            $order->update_meta_data( '_alipay_ps_confirm_checks', $cc );
            $order->save();
            if ( $cc > $maxc ) {
                // give up
                return;
            }
            $out = self::get_or_build_out_request_no_for_order( $order );
            $qr  = self::query_settle( array( 'out_request_no' => $out, 'trade_no' => self::get_order_trade_no( $order ) ) );
            if ( ! empty( $qr['error'] ) ) {
                // retry later
                self::schedule_as_job( $order_id, 'confirm', $confirm_interval );
                return;
            }
            $rc = strtoupper( (string) ( $qr['result_code'] ?? '' ) );
            if ( in_array( $rc, array( 'SUCCESS' ), true ) ) {
                $order->update_meta_data( '_alipay_settle_done', 1 );
                $order->save();
                return;
            }
            if ( in_array( $rc, array( 'PROCESSING', 'IN_PROGRESS', 'PENDING', 'WAIT' ), true ) || '' === $rc ) {
                self::schedule_as_job( $order_id, 'confirm', $confirm_interval );
                return;
            }
            // other failures: retry confirm later as well
            self::schedule_as_job( $order_id, 'confirm', $confirm_interval );
            return;
        }

        // settle phase
        $attempts = (int) $order->get_meta( '_alipay_ps_attempts', true );
        $attempts++;
        $order->update_meta_data( '_alipay_ps_attempts', $attempts );
        $order->save();

        $jobspec = self::compute_jobs_for_order( $order );
        if ( empty( $jobspec ) ) { return; }
        $out   = self::get_or_build_out_request_no_for_order( $order );
        $res   = self::settle_order( $order, $jobspec, array( 'reason' => 'as', 'out_request_no' => $out ) );
        if ( ! empty( $res['error'] ) ) {
            // backoff retry
            $delay = min( 3600, 60 * ( $attempts * $attempts ) );
            self::schedule_as_job( $order_id, 'settle', $delay );
            return;
        }
        // accepted, schedule confirm
        $order->update_meta_data( '_alipay_ps_confirm_checks', 0 );
        $order->save();
        self::schedule_as_job( $order_id, 'confirm', $confirm_interval );
    }

    public static function enqueue_settle_job( $order_id, $reason = 'auto' ) {
        // Prefer Action Scheduler per-order actions
        if ( self::has_as() ) {
            self::schedule_as_job( (int) $order_id, 'settle', 0 );
            return 'as-' . (int) $order_id;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return false; }

        // Guards: currency and payment method
        $currency = $order->get_currency();
        $method   = (string) $order->get_payment_method();
        if ( strtoupper( $currency ) !== 'CNY' ) {
            self::log( 'info', 'Skip non-CNY order for PS', array( 'order_id' => $order_id, 'currency' => $currency ) );
            return false;
        }
        if ( false === stripos( $method, 'alipay' ) ) {
            self::log( 'info', 'Skip non-Alipay method for PS', array( 'order_id' => $order_id, 'method' => $method ) );
            return false;
        }

        $jobs = get_option( self::JOBS_OPTION, array() );
        // Reuse existing job for same order if not succeeded
        foreach ( $jobs as $jid => $j ) {
            if ( (int) ( $j['order_id'] ?? 0 ) === (int) $order_id && ( $j['status'] ?? '' ) !== 'succeeded' ) {
                $jobs[$jid]['status']   = 'pending';
                $jobs[$jid]['next_at']  = time();
                $jobs[$jid]['updated']  = time();
                $jobs[$jid]['phase']    = 'settle';
                update_option( self::JOBS_OPTION, $jobs, false );
                // Schedule processing ASAP
                if ( function_exists( 'as_enqueue_async_action' ) ) {
                    as_enqueue_async_action( 'woo_alipay_ps_process_jobs_async' );
                } else if ( function_exists( 'wp_schedule_single_event' ) ) {
                    wp_schedule_single_event( time() + 5, 'woo_alipay_ps_process_jobs' );
                }
                return $jid;
            }
        }
        $id   = 'job-' . $order_id . '-' . wp_generate_uuid4();
        $now  = time();
        $jobs[$id] = array(
            'id'        => $id,
            'order_id'  => (int) $order_id,
            'status'    => 'pending',
            'attempts'  => 0,
            'next_at'   => $now,
            'created'   => $now,
            'updated'   => $now,
            'reason'    => (string) $reason,
            'phase'     => 'settle',
            'last_error'=> '',
        );
        // Trim to last 500 jobs to avoid option bloat
        if ( count( $jobs ) > 500 ) {
            $jobs = array_slice( $jobs, -500, null, true );
        }
        update_option( self::JOBS_OPTION, $jobs, false );

        // Schedule processing ASAP
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'woo_alipay_ps_process_jobs_async' );
        } else if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + 5, 'woo_alipay_ps_process_jobs' );
        }
        return $id;
    }

    public static function process_jobs( $limit = 5 ) {
        $jobs = get_option( self::JOBS_OPTION, array() );
        if ( empty( $jobs ) || ! is_array( $jobs ) ) { return; }
        $now = time();
        $processed = 0;
        foreach ( $jobs as $id => $job ) {
            if ( $processed >= $limit ) { break; }
            if ( $job['status'] !== 'pending' && $job['status'] !== 'processing' ) { continue; }
            if ( $job['next_at'] > $now ) { continue; }

            $job['status']   = 'processing';
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['updated']  = $now;
            $jobs[$id]       = $job;
            update_option( self::JOBS_OPTION, $jobs, false );

            $res = self::process_single_job( $job );

            // Reload jobs (other processes might have updated)
            $jobs = get_option( self::JOBS_OPTION, array() );
            if ( $res === true ) {
                if ( isset( $jobs[$id] ) ) {
                    // Final confirmation success -> mark succeeded
                    $jobs[$id]['status']  = 'succeeded';
                    $jobs[$id]['phase']   = 'done';
                    $jobs[$id]['updated'] = time();
                    update_option( self::JOBS_OPTION, $jobs, false );
                }
            } else if ( is_string( $res ) && $res === 'scheduled_confirm' ) {
                // Settled accepted; already switched to confirm; keep pending
                if ( isset( $jobs[$id] ) ) {
                    $jobs[$id]['status']  = 'pending';
                    $jobs[$id]['updated'] = time();
                    update_option( self::JOBS_OPTION, $jobs, false );
                }
            } else {
                // Handle confirm pending specially: schedule by confirm interval without setting error
                $is_confirm_pending = is_string( $res ) && 0 === strpos( $res, 'confirm_pending' );
                $settings = self::settings();
                $confirm_interval = isset( $settings['confirm_interval'] ) ? max( 30, (int) $settings['confirm_interval'] ) : 60;
                $next_delay = $is_confirm_pending ? $confirm_interval : min( 3600, 60 * ( $job['attempts'] * $job['attempts'] ) );
                if ( isset( $jobs[$id] ) ) {
                    $jobs[$id]['status']    = 'pending';
                    $jobs[$id]['next_at']   = time() + $next_delay;
                    $jobs[$id]['last_error']= $is_confirm_pending ? '' : ( is_string( $res ) ? $res : 'failed' );
                    $jobs[$id]['updated']   = time();
                    update_option( self::JOBS_OPTION, $jobs, false );
                }
            }
            $processed++;
        }
    }

    protected static function process_single_job( array $job ) {
        $order = wc_get_order( (int) $job['order_id'] );
        if ( ! $order ) { return 'order_missing'; }

        $phase = isset( $job['phase'] ) ? (string) $job['phase'] : 'settle';

        if ( 'confirm' === $phase ) {
            // Confirm phase: increase confirmation checks and query final status
            $all = get_option( self::JOBS_OPTION, array() );
            if ( isset( $all[ $job['id'] ] ) ) {
                $cc = isset( $all[ $job['id'] ]['confirm_checks'] ) ? (int) $all[ $job['id'] ]['confirm_checks'] : 0;
                $cc++;
                $all[ $job['id'] ]['confirm_checks'] = $cc;
                update_option( self::JOBS_OPTION, $all, false );
                $settings = self::settings();
                $maxc = isset( $settings['confirm_max_checks'] ) ? max( 1, (int) $settings['confirm_max_checks'] ) : 10;
                if ( $cc > $maxc ) {
                    return 'confirm_timeout';
                }
            }
            $out = self::get_or_build_out_request_no_for_order( $order );
            $qr  = self::query_settle( array( 'out_request_no' => $out, 'trade_no' => self::get_order_trade_no( $order ) ) );
            if ( ! empty( $qr['error'] ) ) {
                return (string) $qr['error'];
            }
            $rc = strtoupper( (string) ( $qr['result_code'] ?? '' ) );
            if ( in_array( $rc, array( 'SUCCESS' ), true ) ) {
                // Final success
                $order->update_meta_data( '_alipay_settle_done', 1 );
                $order->save();
                return true;
            }
            if ( in_array( $rc, array( 'PROCESSING', 'IN_PROGRESS', 'PENDING', 'WAIT' ), true ) || '' === $rc ) {
                // Keep retrying confirmation
                return 'confirm_pending';
            }
            // Other result_code => failure
            return 'confirm_failed:' . $rc;
        }

        // Settle phase
        $jobspec = self::compute_jobs_for_order( $order );
        if ( empty( $jobspec ) ) { return 'no_jobs'; }

        $out   = self::get_or_build_out_request_no_for_order( $order );
        $res   = self::settle_order( $order, $jobspec, array( 'reason' => 'queued', 'out_request_no' => $out ) );

        if ( ! empty( $res['error'] ) ) {
            return (string) $res['error'];
        }
        // Accepted; switch to confirm phase by updating job record
        $all = get_option( self::JOBS_OPTION, array() );
        if ( isset( $all[ $job['id'] ] ) ) {
            $all[ $job['id'] ]['status']  = 'pending';
            $all[ $job['id'] ]['phase']   = 'confirm';
            $settings = self::settings();
            $confirm_interval = isset( $settings['confirm_interval'] ) ? max( 30, (int) $settings['confirm_interval'] ) : 60;
            $all[ $job['id'] ]['next_at'] = time() + $confirm_interval;
            $all[ $job['id'] ]['updated'] = time();
            // reset confirm_checks counter
            if ( ! isset( $all[ $job['id'] ]['confirm_checks'] ) ) {
                $all[ $job['id'] ]['confirm_checks'] = 0;
            }
            update_option( self::JOBS_OPTION, $all, false );
        }
        return 'scheduled_confirm';
    }
}
