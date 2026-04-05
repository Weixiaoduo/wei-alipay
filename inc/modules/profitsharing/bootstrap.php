<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WOO_ALIPAY_PS_PLUGIN_FILE' ) ) {
    define( 'WOO_ALIPAY_PS_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WOO_ALIPAY_PS_PLUGIN_PATH' ) ) {
    define( 'WOO_ALIPAY_PS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WOO_ALIPAY_PS_PLUGIN_URL' ) ) {
    define( 'WOO_ALIPAY_PS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Load when core plugins exist
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'Woo_Alipay' ) && class_exists( 'WooCommerce' ) ) {
        $service = WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-service.php';
        $admin   = WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-admin.php';
        $oadmin  = WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-order-admin.php';
        $jobsadm = WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-jobs-admin.php';
        $compadm = WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-comp-admin.php';
        if ( file_exists( $service ) ) { require_once $service; }
        if ( file_exists( $admin ) )   { require_once $admin;   new Woo_Alipay_PS_Admin(); }
        if ( file_exists( $oadmin ) )  { require_once $oadmin;  new Woo_Alipay_PS_Order_Admin(); }
        if ( file_exists( $jobsadm ) ) { require_once $jobsadm; new Woo_Alipay_PS_Jobs_Admin(); }
        if ( file_exists( $compadm ) ) { require_once $compadm; new Woo_Alipay_PS_Comp_Admin(); }

// Default: delayed settlement on order completed -> enqueue job or sync
        add_action( 'woocommerce_order_status_completed', function( $order_id ) {
            if ( ! class_exists( 'Woo_Alipay_PS_Service' ) ) { return; }
            $order = wc_get_order( $order_id );
            if ( ! $order ) { return; }
            $settings = get_option( 'woo_alipay_ps_settings', array() );
            $policy   = isset( $settings['execution_policy'] ) ? $settings['execution_policy'] : 'delayed';
            if ( 'delayed' !== $policy ) { return; }
            $mode     = isset( $settings['process_mode'] ) ? $settings['process_mode'] : 'queue';
            if ( 'sync' === $mode ) {
                $jobs = Woo_Alipay_PS_Service::compute_jobs_for_order( $order );
                if ( ! empty( $jobs ) ) {
                    $res = Woo_Alipay_PS_Service::settle_order( $order, $jobs, array( 'reason' => 'auto_on_completed' ) );
                    if ( empty( $res['error'] ) ) {
                        $ci = isset( $settings['confirm_interval'] ) ? max( 30, (int) $settings['confirm_interval'] ) : 60;
                        Woo_Alipay_PS_Service::schedule_as_job( $order_id, 'confirm', $ci );
                    }
                }
                return;
            }
            Woo_Alipay_PS_Service::enqueue_settle_job( $order_id, 'auto_on_completed' );
        } );

        // Immediate settlement on payment complete (if enabled) -> enqueue job or sync
        add_action( 'woocommerce_payment_complete', function( $order_id ) {
            if ( ! class_exists( 'Woo_Alipay_PS_Service' ) ) { return; }
            $order = wc_get_order( $order_id );
            if ( ! $order ) { return; }
            $settings = get_option( 'woo_alipay_ps_settings', array() );
            $policy   = isset( $settings['execution_policy'] ) ? $settings['execution_policy'] : 'delayed';
            if ( 'immediate' !== $policy ) { return; }
            if ( (int) $order->get_meta( '_alipay_settle_done' ) ) { return; }
            $mode     = isset( $settings['process_mode'] ) ? $settings['process_mode'] : 'queue';
            if ( 'sync' === $mode ) {
                $jobs = Woo_Alipay_PS_Service::compute_jobs_for_order( $order );
                if ( ! empty( $jobs ) ) {
                    $res = Woo_Alipay_PS_Service::settle_order( $order, $jobs, array( 'reason' => 'auto_on_payment_complete' ) );
                    if ( empty( $res['error'] ) ) {
                        $ci = isset( $settings['confirm_interval'] ) ? max( 30, (int) $settings['confirm_interval'] ) : 60;
                        Woo_Alipay_PS_Service::schedule_as_job( $order_id, 'confirm', $ci );
                    }
                }
                return;
            }
            Woo_Alipay_PS_Service::enqueue_settle_job( $order_id, 'auto_on_payment_complete' );
        }, 10, 1 );
    }
}, 15 );

// Admin post handlers
add_action( 'admin_post_woo_alipay_ps_save_settings', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_save_settings' );

    $settings = array();
    $settings['execution_policy'] = isset( $_POST['execution_policy'] ) && 'immediate' === $_POST['execution_policy'] ? 'immediate' : 'delayed';
    $settings['process_mode']     = isset( $_POST['process_mode'] ) && 'sync' === $_POST['process_mode'] ? 'sync' : 'queue';
    $settings['base']             = isset( $_POST['base'] ) ? sanitize_text_field( wp_unslash( $_POST['base'] ) ) : 'order_total_minus_shipping';
    $settings['round']            = isset( $_POST['round'] ) ? max( 0, min( 4, (int) $_POST['round'] ) ) : 2;
    $settings['min_amount']       = isset( $_POST['min_amount'] ) ? number_format( (float) $_POST['min_amount'], 2, '.', '' ) : '0.01';
    $settings['out_prefix']       = isset( $_POST['out_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['out_prefix'] ) ) : 'PS';
    $settings['desc_template']    = isset( $_POST['desc_template'] ) ? sanitize_text_field( wp_unslash( $_POST['desc_template'] ) ) : 'Woo Profit Sharing';
    $settings['tail_strategy']    = isset( $_POST['tail_strategy'] ) ? sanitize_text_field( wp_unslash( $_POST['tail_strategy'] ) ) : 'largest';
    $settings['tail_receiver_id'] = isset( $_POST['tail_receiver_id'] ) ? sanitize_key( wp_unslash( $_POST['tail_receiver_id'] ) ) : '';
    $settings['confirm_interval']    = isset( $_POST['confirm_interval'] ) ? max( 30, (int) $_POST['confirm_interval'] ) : 60;
    $settings['confirm_max_checks']  = isset( $_POST['confirm_max_checks'] ) ? max( 1, (int) $_POST['confirm_max_checks'] ) : 10;

    update_option( 'woo_alipay_ps_settings', $settings, false );
    set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '设置已保存。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );

add_action( 'admin_post_woo_alipay_ps_receivers_upsert', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_receivers_upsert' );

    $label         = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
    $identity      = isset( $_POST['identity'] ) ? sanitize_text_field( wp_unslash( $_POST['identity'] ) ) : '';
    $identity_type = isset( $_POST['identity_type'] ) && 'ALIPAY_USER_ID' === $_POST['identity_type'] ? 'ALIPAY_USER_ID' : 'ALIPAY_LOGON_ID';
    $enabled       = isset( $_POST['enabled'] ) ? ( '1' === $_POST['enabled'] ? 1 : 0 ) : 1;
    $id            = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';

    if ( empty( $identity ) || empty( $label ) ) {
        set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '请填写标签与账号。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
        exit;
    }

    $list = get_option( 'woo_alipay_ps_receivers', array() );

    if ( empty( $id ) ) {
        $id = sanitize_key( preg_replace( '/[^a-z0-9\-]+/i', '-', strtolower( $label ) ) . '-' . wp_generate_password( 4, false, false ) );
    }

    $list[ $id ] = array(
        'id'            => $id,
        'label'         => $label,
        'identity'      => $identity,
        'identity_type' => $identity_type,
        'enabled'       => (int) $enabled,
    );

    update_option( 'woo_alipay_ps_receivers', $list, false );
    set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '收款方已保存。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );

add_action( 'admin_post_woo_alipay_ps_receivers_delete', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_receivers_delete' );

    $id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
    $list = get_option( 'woo_alipay_ps_receivers', array() );
    if ( isset( $list[ $id ] ) ) { unset( $list[ $id ] ); }
    update_option( 'woo_alipay_ps_receivers', $list, false );
    set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '收款方已删除。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );

add_action( 'admin_post_woo_alipay_ps_rules_save', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_rules_save' );

    $saved = get_option( 'woo_alipay_ps_rules', array( 'default' => array(), 'category' => array() ) );
    if ( ! isset( $saved['category'] ) || ! is_array( $saved['category'] ) ) { $saved['category'] = array(); }

    $default_percent = isset( $_POST['default_percent'] ) && is_array( $_POST['default_percent'] ) ? $_POST['default_percent'] : array();
    $rules = array( 'default' => array(), 'category' => $saved['category'] );
    $sum = 0.0;
    foreach ( $default_percent as $rid => $pct ) {
        $pct = (float) $pct;
        if ( $pct > 0 ) {
            $rules['default'][] = array( 'receiver_id' => sanitize_key( $rid ), 'percent' => $pct );
            $sum += $pct;
        }
    }
    if ( $sum > 100.0001 ) {
        set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '规则总比例超过 100%，请调整。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    } else {
        update_option( 'woo_alipay_ps_rules', $rules, false );
        set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '规则已保存。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );

// Category rule add
add_action( 'admin_post_woo_alipay_ps_category_rule_add', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_category_rule_add' );

    $cat_id      = isset( $_POST['category_id'] ) ? (string) absint( $_POST['category_id'] ) : '';
    $receiver_id = isset( $_POST['receiver_id'] ) ? sanitize_key( wp_unslash( $_POST['receiver_id'] ) ) : '';
    $percent     = isset( $_POST['percent'] ) ? (float) $_POST['percent'] : 0;

    if ( empty( $cat_id ) || empty( $receiver_id ) || $percent <= 0 ) {
        set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '请填写类目、收款方与比例。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
        exit;
    }

    $rules = get_option( 'woo_alipay_ps_rules', array( 'default' => array(), 'category' => array() ) );
    if ( ! isset( $rules['category'][ $cat_id ] ) || ! is_array( $rules['category'][ $cat_id ] ) ) {
        $rules['category'][ $cat_id ] = array();
    }

    // Validate sum <= 100%
    $sum = 0.0;
    foreach ( $rules['category'][ $cat_id ] as $r ) {
        $sum += (float) ( $r['percent'] ?? 0 );
    }
    if ( $sum + $percent > 100.0001 ) {
        set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '该类目下的分账比例累计超过 100%，请调整。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
        exit;
    }

    $rules['category'][ $cat_id ][] = array( 'receiver_id' => $receiver_id, 'percent' => $percent );
    update_option( 'woo_alipay_ps_rules', $rules, false );

    set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '类目规则已添加。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );

// Product rule add
add_action( 'admin_post_woo_alipay_ps_product_rule_add', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_product_rule_add' );

    $raw_product = isset( $_POST['product_id'] ) ? wp_unslash( $_POST['product_id'] ) : '';
    if ( is_string( $raw_product ) && preg_match( '/(\d{1,10})/', $raw_product, $m ) ) {
        $product_id = (string) absint( $m[1] );
    } else {
        $product_id = (string) absint( $raw_product );
    }
    $receiver_id = isset( $_POST['receiver_id'] ) ? sanitize_key( wp_unslash( $_POST['receiver_id'] ) ) : '';
    $percent     = isset( $_POST['percent'] ) ? (float) $_POST['percent'] : 0;

    if ( empty( $product_id ) || empty( $receiver_id ) || $percent <= 0 ) {
        set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '请填写商品、收款方与比例。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
        exit;
    }

    $rules = get_option( 'woo_alipay_ps_rules', array( 'default' => array(), 'category' => array(), 'product' => array() ) );
    if ( ! isset( $rules['product'][ $product_id ] ) || ! is_array( $rules['product'][ $product_id ] ) ) {
        $rules['product'][ $product_id ] = array();
    }
    // Validate sum <= 100% for product as well
    $sum = 0.0;
    foreach ( $rules['product'][ $product_id ] as $r ) { $sum += (float) ( $r['percent'] ?? 0 ); }
    if ( $sum + $percent > 100.0001 ) {
        set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '该商品下的分账比例累计超过 100%，请调整。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
        exit;
    }

    $rules['product'][ $product_id ][] = array( 'receiver_id' => $receiver_id, 'percent' => $percent );
    update_option( 'woo_alipay_ps_rules', $rules, false );

    set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '商品规则已添加。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );

// Product rule delete
add_action( 'admin_post_woo_alipay_ps_product_rule_delete', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_product_rule_delete' );

    $product_id  = isset( $_POST['product_id'] ) ? (string) absint( $_POST['product_id'] ) : '';
    $receiver_id = isset( $_POST['receiver_id'] ) ? sanitize_key( wp_unslash( $_POST['receiver_id'] ) ) : '';

    $rules = get_option( 'woo_alipay_ps_rules', array( 'default' => array(), 'category' => array(), 'product' => array() ) );
    if ( isset( $rules['product'][ $product_id ] ) && is_array( $rules['product'][ $product_id ] ) ) {
        $rules['product'][ $product_id ] = array_values( array_filter( $rules['product'][ $product_id ], function( $r ) use ( $receiver_id ) {
            return ( $r['receiver_id'] ?? '' ) !== $receiver_id;
        } ) );
        if ( empty( $rules['product'][ $product_id ] ) ) { unset( $rules['product'][ $product_id ] ); }
    }
    update_option( 'woo_alipay_ps_rules', $rules, false );

    set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '商品规则已删除。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );

// Category rule delete
add_action( 'admin_post_woo_alipay_ps_category_rule_delete', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_category_rule_delete' );

    $cat_id      = isset( $_POST['category_id'] ) ? (string) absint( $_POST['category_id'] ) : '';
    $receiver_id = isset( $_POST['receiver_id'] ) ? sanitize_key( wp_unslash( $_POST['receiver_id'] ) ) : '';

    $rules = get_option( 'woo_alipay_ps_rules', array( 'default' => array(), 'category' => array() ) );
    if ( isset( $rules['category'][ $cat_id ] ) && is_array( $rules['category'][ $cat_id ] ) ) {
        $rules['category'][ $cat_id ] = array_values( array_filter( $rules['category'][ $cat_id ], function( $r ) use ( $receiver_id ) {
            return ( $r['receiver_id'] ?? '' ) !== $receiver_id;
        } ) );
        if ( empty( $rules['category'][ $cat_id ] ) ) { unset( $rules['category'][ $cat_id ] ); }
    }
    update_option( 'woo_alipay_ps_rules', $rules, false );

    set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '类目规则已删除。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );

add_action( 'admin_post_woo_alipay_ps_settle_order', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_settle_order' );

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $order    = $order_id ? wc_get_order( $order_id ) : false;
    if ( ! $order ) {
        wp_safe_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
        exit;
    }

    if ( ! class_exists( 'Woo_Alipay_PS_Service' ) ) {
        require_once WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-service.php';
    }

    $settings = get_option( 'woo_alipay_ps_settings', array() );
    $mode     = isset( $settings['process_mode'] ) ? $settings['process_mode'] : 'queue';
    if ( 'sync' === $mode ) {
        $jobs = Woo_Alipay_PS_Service::compute_jobs_for_order( $order );
        if ( empty( $jobs ) ) {
            set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '没有可结算的分账项。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        } else {
            $res = Woo_Alipay_PS_Service::settle_order( $order, $jobs, array( 'reason' => 'manual' ) );
            if ( ! empty( $res['error'] ) ) {
                set_transient( 'woo_alipay_ps_notice', array( 'error' => (string) $res['error'] ), 5 * MINUTE_IN_SECONDS );
            } else {
                // Schedule confirm
                $ci = isset( $settings['confirm_interval'] ) ? max( 30, (int) $settings['confirm_interval'] ) : 60;
                Woo_Alipay_PS_Service::schedule_as_job( $order_id, 'confirm', $ci );
                set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '已发起结算并安排确认。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
            }
        }
    } else {
        $jid = Woo_Alipay_PS_Service::enqueue_settle_job( $order_id, 'manual' );
        if ( ! $jid ) {
            set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '未能加入队列（可能因币种/支付方式不匹配）。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        } else {
            set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '已加入后台队列处理。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        }
    }

    wp_safe_redirect( get_edit_post_link( $order_id, 'raw' ) );
    exit;
} );

// Cron schedules for job processor
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['one_minute'] ) ) {
        $schedules['one_minute'] = array( 'interval' => 60, 'display' => __( 'Every Minute', 'woo-alipay-profitsharing' ) );
    }
    return $schedules;
} );

// Ensure processors are scheduled (option-queue fallback only)
add_action( 'init', function() {
    if ( function_exists( 'as_schedule_recurring_action' ) ) {
        // When Action Scheduler is available, use per-order actions instead of option queue processor
    } else {
        if ( ! wp_next_scheduled( 'woo_alipay_ps_process_jobs' ) ) {
            wp_schedule_event( time() + 60, 'one_minute', 'woo_alipay_ps_process_jobs' );
        }
    }
} );

// AJAX: product search for admin autocomplete
add_action( 'wp_ajax_woo_alipay_ps_search_products', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_send_json_success( array() );
    }
    $term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => 20,
        's'              => $term,
        'post_status'    => array( 'publish', 'private' ),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    );
    $q = new WP_Query( $args );
    $out = array();
    if ( $q->have_posts() ) {
        foreach ( $q->posts as $pid ) {
            $title = get_the_title( $pid );
            $out[] = array( 'label' => sprintf( '%s (#%d)', $title, $pid ), 'value' => sprintf( '%s (#%d)', $title, $pid ) );
        }
    }
    wp_send_json_success( $out );
} );

// AJAX: category search for admin autocomplete
add_action( 'wp_ajax_woo_alipay_ps_search_categories', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_send_json_success( array() );
    }
    $term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
    $cats = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'number'     => 20,
        'search'     => $term,
    ) );
    $out = array();
    if ( ! is_wp_error( $cats ) ) {
        foreach ( $cats as $c ) {
            $out[] = array( 'label' => sprintf( '%s (#%d)', $c->name, $c->term_id ), 'value' => (string) $c->term_id );
        }
    }
    wp_send_json_success( $out );
} );

// Auto-assisted compensation suggestions on refund
add_action( 'woocommerce_order_refunded', function( $order_id, $refund_id ) {
    $order  = wc_get_order( $order_id );
    $refund = wc_get_order( $refund_id );
    if ( ! $order || ! $refund ) { return; }
    if ( ! class_exists( 'Woo_Alipay_PS_Service' ) ) {
        require_once WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-service.php';
    }
    if ( ! method_exists( 'Woo_Alipay_PS_Service', 'compute_jobs_for_refund' ) ) { return; }
    $jobs = Woo_Alipay_PS_Service::compute_jobs_for_refund( $order, $refund );
    if ( empty( $jobs ) ) { return; }
    $sugs = (array) $order->get_meta( '_alipay_ps_suggestions', true );
    foreach ( $jobs as $j ) {
        $sugs[] = array(
            'id'            => 'SUG-' . $refund_id . '-' . wp_rand( 1000, 9999 ),
            'refund_id'     => (int) $refund_id,
            'receiver_id'   => (string) ( $j['receiver_id'] ?? '' ),
            'identity'      => (string) ( $j['identity'] ?? '' ),
            'identity_type' => (string) ( $j['identity_type'] ?? 'ALIPAY_LOGON_ID' ),
            'amount'        => (float) ( $j['amount'] ?? 0 ),
            'desc'          => (string) ( $j['desc'] ?? 'Refund compensation' ),
            'created'       => current_time( 'mysql' ),
            'status'        => 'pending',
        );
    }
    $order->update_meta_data( '_alipay_ps_suggestions', $sugs );
    $order->save();
}, 10, 2 );

// Convert suggestion into compensation and send
add_action( 'admin_post_woo_alipay_ps_compensate_from_suggestion', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_compensate_from_suggestion' );
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $key      = isset( $_POST['suggest_key'] ) ? absint( $_POST['suggest_key'] ) : -1;
    $order    = $order_id ? wc_get_order( $order_id ) : false;
    if ( ! $order || $key < 0 ) { wp_safe_redirect( admin_url( 'edit.php?post_type=shop_order' ) ); exit; }

    $sugs = (array) $order->get_meta( '_alipay_ps_suggestions', true );
    if ( ! isset( $sugs[ $key ] ) ) { wp_safe_redirect( get_edit_post_link( $order_id, 'raw' ) ); exit; }
    $s = $sugs[ $key ];

    if ( ! class_exists( 'Woo_Alipay_PS_Service' ) ) {
        require_once WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-service.php';
    }

    $jobs = array( array(
        'receiver_id'   => (string) ( $s['receiver_id'] ?? '' ),
        'label'         => (string) ( $s['receiver_id'] ?? '' ),
        'identity'      => (string) ( $s['identity'] ?? '' ),
        'identity_type' => (string) ( $s['identity_type'] ?? 'ALIPAY_LOGON_ID' ),
        'amount'        => (float) ( $s['amount'] ?? 0 ),
        'desc'          => (string) ( $s['desc'] ?? 'Refund compensation' ),
    ) );

    $prefix = (string) ( Woo_Alipay_PS_Service::settings()['out_prefix'] ?? 'PS' );
    $manual_out = sprintf( '%s-%s%d-SUG-%s', $prefix, is_multisite() ? get_current_blog_id() . '-' : '', $order_id, gmdate( 'YmdHis' ) );
    $res = Woo_Alipay_PS_Service::settle_order( $order, $jobs, array( 'reason' => 'suggestion', 'out_request_no' => $manual_out ) );
    if ( empty( $res['error'] ) ) {
        // mark suggestion
        $sugs[ $key ]['status'] = 'sent';
        $sugs[ $key ]['out_request_no'] = $manual_out;
        $order->update_meta_data( '_alipay_ps_suggestions', $sugs );
        $order->save();
        if ( Woo_Alipay_PS_Service::has_as() ) {
            Woo_Alipay_PS_Service::schedule_as_job( $order_id, 'confirm', max( 30, (int) ( Woo_Alipay_PS_Service::settings()['confirm_interval'] ?? 60 ) ) );
        }
    }
    wp_safe_redirect( get_edit_post_link( $order_id, 'raw' ) );
    exit;
} );

// Action Scheduler per-order job runner
add_action( 'woo_alipay_ps_run_job', function( $order_id, $phase = 'settle' ) {
    if ( class_exists( 'Woo_Alipay_PS_Service' ) ) {
        Woo_Alipay_PS_Service::run_as_job( (int) $order_id, (string) $phase );
    }
}, 10, 2 );

// Processors
add_action( 'woo_alipay_ps_process_jobs', function() {
    if ( class_exists( 'Woo_Alipay_PS_Service' ) ) {
        Woo_Alipay_PS_Service::process_jobs( 5 );
    }
} );
if ( function_exists( 'add_action' ) ) {
    add_action( 'woo_alipay_ps_process_jobs_async', function() {
        if ( class_exists( 'Woo_Alipay_PS_Service' ) ) {
            Woo_Alipay_PS_Service::process_jobs( 5 );
        }
    } );
}

// Clear succeeded jobs
add_action( 'admin_post_woo_alipay_ps_jobs_clear', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_jobs_clear' );
    $jobs = get_option( 'woo_alipay_ps_jobs', array() );
    if ( ! empty( $jobs ) && is_array( $jobs ) ) {
        foreach ( $jobs as $id => $j ) {
            if ( ( $j['status'] ?? '' ) === 'succeeded' ) {
                unset( $jobs[ $id ] );
            }
        }
        update_option( 'woo_alipay_ps_jobs', $jobs, false );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-ps-jobs' ) );
    exit;
} );

// Clear failed jobs older than N days
add_action( 'admin_post_woo_alipay_ps_jobs_clear_old_failed', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_jobs_clear_old_failed' );
    $days = isset( $_POST['days'] ) ? max( 1, (int) $_POST['days'] ) : 7;
    $cut  = time() - $days * DAY_IN_SECONDS;
    $jobs = get_option( 'woo_alipay_ps_jobs', array() );
    if ( ! empty( $jobs ) && is_array( $jobs ) ) {
        foreach ( $jobs as $id => $j ) {
            if ( ( $j['status'] ?? '' ) === 'pending' || ( $j['status'] ?? '' ) === 'processing' ) { continue; }
            if ( ( $j['status'] ?? '' ) !== 'succeeded' && (int) ( $j['updated'] ?? 0 ) < $cut ) {
                unset( $jobs[ $id ] );
            }
        }
        update_option( 'woo_alipay_ps_jobs', $jobs, false );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-ps-jobs' ) );
    exit;
} );

// Export jobs CSV
add_action( 'admin_post_woo_alipay_ps_jobs_export', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
    $error_only = isset( $_GET['error_only'] ) && '1' === $_GET['error_only'];

    $jobs = get_option( 'woo_alipay_ps_jobs', array() );
    if ( $status ) {
        $jobs = array_filter( $jobs, function( $j ) use ( $status ) {
            return ( $j['status'] ?? '' ) === $status;
        } );
    }
    if ( $error_only ) {
        $jobs = array_filter( $jobs, function( $j ) {
            return ! empty( $j['last_error'] );
        } );
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=alipay_ps_jobs_' . gmdate( 'Ymd_His' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'id', 'order_id', 'phase', 'status', 'attempts', 'confirm_checks', 'next_at', 'updated', 'reason', 'last_error' ) );
    foreach ( $jobs as $j ) {
        fputcsv( $out, array(
            (string) ( $j['id'] ?? '' ),
            (int) ( $j['order_id'] ?? 0 ),
            (string) ( $j['phase'] ?? '' ),
            (string) ( $j['status'] ?? '' ),
            (int) ( $j['attempts'] ?? 0 ),
            (int) ( $j['confirm_checks'] ?? 0 ),
            ( ! empty( $j['next_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $j['next_at'] ) : '' ),
            ( ! empty( $j['updated'] ) ? gmdate( 'Y-m-d H:i:s', (int) $j['updated'] ) : '' ),
            (string) ( $j['reason'] ?? '' ),
            (string) ( $j['last_error'] ?? '' ),
        ) );
    }
    fclose( $out );
    exit;
} );

// New round: clear out_request_no and settled flag, then enqueue
add_action( 'admin_post_woo_alipay_ps_settle_order_new_round', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_settle_order_new_round' );

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $order    = $order_id ? wc_get_order( $order_id ) : false;
    if ( ! $order ) {
        wp_safe_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
        exit;
    }
    $order->delete_meta_data( '_alipay_ps_out_request_no' );
    $order->delete_meta_data( '_alipay_settle_done' );
    $order->save();

    if ( ! class_exists( 'Woo_Alipay_PS_Service' ) ) {
        require_once WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-service.php';
    }

    $settings = get_option( 'woo_alipay_ps_settings', array() );
    $mode     = isset( $settings['process_mode'] ) ? $settings['process_mode'] : 'queue';
    if ( 'sync' === $mode ) {
        $jobs = Woo_Alipay_PS_Service::compute_jobs_for_order( $order );
        if ( empty( $jobs ) ) {
            set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '没有可结算的分账项。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        } else {
            $res = Woo_Alipay_PS_Service::settle_order( $order, $jobs, array( 'reason' => 'manual_new_round' ) );
            if ( ! empty( $res['error'] ) ) {
                set_transient( 'woo_alipay_ps_notice', array( 'error' => (string) $res['error'] ), 5 * MINUTE_IN_SECONDS );
            } else {
                $ci = isset( $settings['confirm_interval'] ) ? max( 30, (int) $settings['confirm_interval'] ) : 60;
                Woo_Alipay_PS_Service::schedule_as_job( $order_id, 'confirm', $ci );
                set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '已发起新一轮结算并安排确认。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
            }
        }
    } else {
        $jid = Woo_Alipay_PS_Service::enqueue_settle_job( $order_id, 'manual_new_round' );
        if ( ! $jid ) {
            set_transient( 'woo_alipay_ps_notice', array( 'error' => __( '未能加入队列（可能因币种/支付方式不匹配）。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        } else {
            set_transient( 'woo_alipay_ps_notice', array( 'success' => __( '已开始新一轮结算并加入队列。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
        }
    }

    wp_safe_redirect( get_edit_post_link( $order_id, 'raw' ) );
    exit;
} );

// Query settlement result
add_action( 'admin_post_woo_alipay_ps_query', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
    }
    check_admin_referer( 'woo_alipay_ps_query' );

    $out_request_no = isset( $_POST['out_request_no'] ) ? sanitize_text_field( wp_unslash( $_POST['out_request_no'] ) ) : '';
    $trade_no       = isset( $_POST['trade_no'] ) ? sanitize_text_field( wp_unslash( $_POST['trade_no'] ) ) : '';

    if ( ! class_exists( 'Woo_Alipay_PS_Service' ) ) {
        require_once WOO_ALIPAY_PS_PLUGIN_PATH . 'inc/class-woo-alipay-ps-service.php';
    }

    if ( empty( $out_request_no ) && empty( $trade_no ) ) {
        set_transient( 'woo_alipay_ps_query_result', array( 'error' => __( '请提供 out_request_no 或 trade_no。', 'woo-alipay-profitsharing' ) ), 5 * MINUTE_IN_SECONDS );
    } else {
        $res = Woo_Alipay_PS_Service::query_settle( array( 'out_request_no' => $out_request_no, 'trade_no' => $trade_no ) );
        set_transient( 'woo_alipay_ps_query_result', $res, 10 * MINUTE_IN_SECONDS );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-profitsharing' ) );
    exit;
} );
