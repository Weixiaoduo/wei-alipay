<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Woo_Alipay_PS_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Alipay Profit Sharing', 'woo-alipay-profitsharing' ),
            __( 'Alipay Profit Sharing', 'woo-alipay-profitsharing' ),
            'manage_woocommerce',
            'woo-alipay-profitsharing',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        // Only load on our settings page
        if ( 'woocommerce_page_woo-alipay-profitsharing' !== $hook ) {
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( ! $screen || false === strpos( (string) $screen->id, 'woocommerce_page_woo-alipay-profitsharing' ) ) {
                return;
            }
        }
        wp_enqueue_script( 'jquery-ui-autocomplete' );
        wp_register_script( 'woo-alipay-ps-admin', WOO_ALIPAY_PS_PLUGIN_URL . 'inc/js/ps-admin.js', array( 'jquery', 'jquery-ui-autocomplete' ), '1.0', true );
        wp_localize_script( 'woo-alipay-ps-admin', 'WooAlipayPSAdmin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'actions'  => array(
                'products'   => 'woo_alipay_ps_search_products',
                'categories' => 'woo_alipay_ps_search_categories',
            ),
        ) );
        wp_enqueue_script( 'woo-alipay-ps-admin' );
    }

    private function notice_panel() {
        $notice = get_transient( 'woo_alipay_ps_notice' );
        if ( $notice ) {
            delete_transient( 'woo_alipay_ps_notice' );
            if ( ! empty( $notice['error'] ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( (string) $notice['error'] ) . '</p></div>';
            } elseif ( ! empty( $notice['success'] ) ) {
                echo '<div class="notice notice-success"><p>' . esc_html( (string) $notice['success'] ) . '</p></div>';
            }
        }
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) { return; }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Alipay Profit Sharing', 'woo-alipay-profitsharing' ) . '</h1>';

        $this->notice_panel();

        $settings  = get_option( 'woo_alipay_ps_settings', array( 'execution_policy' => 'delayed', 'base' => 'order_total_minus_shipping', 'round' => 2, 'min_amount' => '0.01', 'out_prefix' => 'PS', 'desc_template' => 'Woo Profit Sharing' ) );
        $receivers = get_option( 'woo_alipay_ps_receivers', array() );
        $rules     = get_option( 'woo_alipay_ps_rules', array( 'default' => array() ) );
        $default   = isset( $rules['default'] ) ? $rules['default'] : array();

        echo '<h2>' . esc_html__( 'Settings', 'woo-alipay-profitsharing' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:880px;">';
        wp_nonce_field( 'woo_alipay_ps_save_settings' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_save_settings" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__( 'Execution policy', 'woo-alipay-profitsharing' ) . '</th><td>';
        echo '<label><input type="radio" name="execution_policy" value="delayed"' . checked( $settings['execution_policy'] ?? 'delayed', 'delayed', false ) . '> ' . esc_html__( 'Delayed (on order completed)', 'woo-alipay-profitsharing' ) . '</label><br/>';
        echo '<label><input type="radio" name="execution_policy" value="immediate"' . checked( $settings['execution_policy'] ?? 'delayed', 'immediate', false ) . '> ' . esc_html__( 'Immediate (on pay success)', 'woo-alipay-profitsharing' ) . '</label>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Processing mode', 'woo-alipay-profitsharing' ) . '</th><td>';
        echo '<label><input type="radio" name="process_mode" value="queue"' . checked( $settings['process_mode'] ?? 'queue', 'queue', false ) . '> ' . esc_html__( 'Queue (recommended)', 'woo-alipay-profitsharing' ) . '</label><br/>';
        echo '<label><input type="radio" name="process_mode" value="sync"' . checked( $settings['process_mode'] ?? 'queue', 'sync', false ) . '> ' . esc_html__( 'Sync (execute immediately)', 'woo-alipay-profitsharing' ) . '</label>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Base amount', 'woo-alipay-profitsharing' ) . '</th><td>';
        echo '<select name="base"><option value="order_total_minus_shipping"' . selected( $settings['base'] ?? '', 'order_total_minus_shipping', false ) . '>' . esc_html__( 'Order total minus shipping', 'woo-alipay-profitsharing' ) . '</option></select>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Rounding digits', 'woo-alipay-profitsharing' ) . '</th><td><input type="number" min="0" max="4" name="round" value="' . esc_attr( (int) ( $settings['round'] ?? 2 ) ) . '" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Minimum amount', 'woo-alipay-profitsharing' ) . '</th><td><input type="number" step="0.01" min="0" name="min_amount" value="' . esc_attr( (string) ( $settings['min_amount'] ?? '0.01' ) ) . '" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Out request prefix', 'woo-alipay-profitsharing' ) . '</th><td><input type="text" name="out_prefix" value="' . esc_attr( (string) ( $settings['out_prefix'] ?? 'PS' ) ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Description template', 'woo-alipay-profitsharing' ) . '</th><td><input type="text" name="desc_template" value="' . esc_attr( (string) ( $settings['desc_template'] ?? 'Woo Profit Sharing' ) ) . '" class="regular-text" /><br/><span class="description">' . esc_html__( 'Placeholders: {order_id}, {order_number}', 'woo-alipay-profitsharing' ) . '</span></td></tr>';
        echo '<tr><th>' . esc_html__( 'Tail allocation strategy', 'woo-alipay-profitsharing' ) . '</th><td>';
        $ts = (string) ( $settings['tail_strategy'] ?? 'largest' );
        echo '<select name="tail_strategy">';
        foreach ( array( 'largest' => 'Largest', 'first' => 'First', 'receiver' => 'Specific receiver', 'none' => 'None' ) as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $ts, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Tail receiver (when Specific)', 'woo-alipay-profitsharing' ) . '</th><td>';
        echo '<input type="text" name="tail_receiver_id" value="' . esc_attr( (string) ( $settings['tail_receiver_id'] ?? '' ) ) . '" class="regular-text" placeholder="receiver id" />';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Confirm interval (seconds)', 'woo-alipay-profitsharing' ) . '</th><td><input type="number" min="30" step="1" name="confirm_interval" value="' . esc_attr( (string) ( $settings['confirm_interval'] ?? '60' ) ) . '" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Max confirmation checks', 'woo-alipay-profitsharing' ) . '</th><td><input type="number" min="1" step="1" name="confirm_max_checks" value="' . esc_attr( (string) ( $settings['confirm_max_checks'] ?? '10' ) ) . '" /></td></tr>';
        echo '</tbody></table>';
        submit_button( esc_html__( 'Save settings', 'woo-alipay-profitsharing' ) );
        echo '</form>';

        // Query result panel
        $qres = get_transient( 'woo_alipay_ps_query_result' );
        if ( $qres ) {
            delete_transient( 'woo_alipay_ps_query_result' );
            if ( ! empty( $qres['error'] ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( (string) $qres['error'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-info"><p>' . esc_html__( 'Settlement query result:', 'woo-alipay-profitsharing' ) . '</p><ul>';
                foreach ( array( 'trade_no', 'out_request_no', 'result_code', 'msg', 'sub_msg' ) as $k ) {
                    if ( ! empty( $qres[ $k ] ) ) {
                        echo '<li>' . esc_html( $k ) . ': ' . esc_html( (string) $qres[ $k ] ) . '</li>';
                    }
                }
                if ( ! empty( $qres['details'] ) && is_array( $qres['details'] ) ) {
                    echo '<h4>' . esc_html__( 'Royalty details', 'woo-alipay-profitsharing' ) . '</h4><ol>';
                    foreach ( $qres['details'] as $d ) {
                        $line = wp_json_encode( $d, JSON_UNESCAPED_UNICODE );
                        echo '<li><code>' . esc_html( (string) $line ) . '</code></li>';
                    }
                    echo '</ol>';
                }
                if ( ! empty( $qres['hint'] ) ) {
                    $help = 'https://woocn.com/help/alipay-profitsharing-errors';
                    echo '<p class="description">' . esc_html__( 'Hint:', 'woo-alipay-profitsharing' ) . ' ' . esc_html( (string) $qres['hint'] ) . ' <a href="' . esc_url( $help ) . '" target="_blank" rel="noopener">' . esc_html__( 'Help', 'woo-alipay-profitsharing' ) . '</a></p>';
                }
                echo '</ul></div>';
            }
        }

        echo '<h2>' . esc_html__( 'Settlement Query', 'woo-alipay-profitsharing' ) . '</h2>';
        // If using Action Scheduler, show a hint
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            echo '<p class="description">' . esc_html__( 'Note: Jobs are processed via Action Scheduler. See WooCommerce > Status > Scheduled Actions (group: woo_alipay_ps).', 'woo-alipay-profitsharing' ) . '</p>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:880px; margin-bottom: 24px;">';
        wp_nonce_field( 'woo_alipay_ps_query' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_query" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="out_request_no">' . esc_html__( 'Out request no', 'woo-alipay-profitsharing' ) . '</label></th><td><input type="text" name="out_request_no" id="out_request_no" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="trade_no">' . esc_html__( 'Trade no', 'woo-alipay-profitsharing' ) . '</label></th><td><input type="text" name="trade_no" id="trade_no" class="regular-text" /></td></tr>';
        echo '</tbody></table>';
        submit_button( esc_html__( 'Query', 'woo-alipay-profitsharing' ) );
        echo '</form>';

        echo '<hr/>';
        echo '<h2>' . esc_html__( 'Receivers', 'woo-alipay-profitsharing' ) . '</h2>';

        // List receivers
        if ( ! empty( $receivers ) ) {
            echo '<table class="widefat" style="max-width:880px"><thead><tr><th>' . esc_html__( 'Label', 'woo-alipay-profitsharing' ) . '</th><th>' . esc_html__( 'Identity Type', 'woo-alipay-profitsharing' ) . '</th><th>' . esc_html__( 'Identity', 'woo-alipay-profitsharing' ) . '</th><th>' . esc_html__( 'Enabled', 'woo-alipay-profitsharing' ) . '</th><th>' . esc_html__( 'Actions', 'woo-alipay-profitsharing' ) . '</th></tr></thead><tbody>';
            foreach ( $receivers as $rcv ) {
                echo '<tr>';
                echo '<td>' . esc_html( $rcv['label'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $rcv['identity_type'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $rcv['identity'] ?? '' ) . '</td>';
                echo '<td>' . ( ! empty( $rcv['enabled'] ) ? '✔' : '✖' ) . '</td>';
                echo '<td>';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
                wp_nonce_field( 'woo_alipay_ps_receivers_delete' );
                echo '<input type="hidden" name="action" value="woo_alipay_ps_receivers_delete" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( $rcv['id'] ?? '' ) ) . '" />';
                submit_button( esc_html__( 'Delete', 'woo-alipay-profitsharing' ), 'delete small', '', false );
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__( 'No receivers yet.', 'woo-alipay-profitsharing' ) . '</p>';
        }

        // Add receiver form
        echo '<h3>' . esc_html__( 'Add receiver', 'woo-alipay-profitsharing' ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:880px">';
        wp_nonce_field( 'woo_alipay_ps_receivers_upsert' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_receivers_upsert" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="label">' . esc_html__( 'Label', 'woo-alipay-profitsharing' ) . '</label></th><td><input type="text" name="label" id="label" class="regular-text" required /></td></tr>';
        echo '<tr><th><label for="identity_type">' . esc_html__( 'Identity Type', 'woo-alipay-profitsharing' ) . '</label></th><td><select name="identity_type" id="identity_type"><option value="ALIPAY_LOGON_ID">ALIPAY_LOGON_ID</option><option value="ALIPAY_USER_ID">ALIPAY_USER_ID</option></select></td></tr>';
        echo '<tr><th><label for="identity">' . esc_html__( 'Identity', 'woo-alipay-profitsharing' ) . '</label></th><td><input type="text" name="identity" id="identity" class="regular-text" required /></td></tr>';
        echo '<tr><th><label for="enabled">' . esc_html__( 'Enabled', 'woo-alipay-profitsharing' ) . '</label></th><td><label><input type="checkbox" name="enabled" value="1" checked /> ' . esc_html__( 'Enabled', 'woo-alipay-profitsharing' ) . '</label></td></tr>';
        echo '</tbody></table>';
        submit_button( esc_html__( 'Add', 'woo-alipay-profitsharing' ) );
        echo '</form>';

        echo '<hr/>';
        echo '<h2>' . esc_html__( 'Default rule', 'woo-alipay-profitsharing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Define percentage shares for each receiver. Sum should be ≤ 100%.', 'woo-alipay-profitsharing' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:880px">';
        wp_nonce_field( 'woo_alipay_ps_rules_save' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_rules_save" />';
        if ( ! empty( $receivers ) ) {
            echo '<table class="form-table"><tbody>';
            foreach ( $receivers as $rcv ) {
                $rid = (string) ( $rcv['id'] ?? '' );
                $pct = 0;
                if ( is_array( $default ) ) {
                    foreach ( $default as $item ) { if ( ($item['receiver_id'] ?? '') === $rid ) { $pct = (float) $item['percent']; break; } }
                }
                echo '<tr><th>' . esc_html( $rcv['label'] ?? $rid ) . '</th><td><input type="number" step="0.01" min="0" max="100" name="default_percent[' . esc_attr( $rid ) . ']" value="' . esc_attr( $pct ) . '" /> %</td></tr>';
            }
            echo '</tbody></table>';
            submit_button( esc_html__( 'Save rule', 'woo-alipay-profitsharing' ) );
        } else {
            echo '<p>' . esc_html__( 'Add receivers first.', 'woo-alipay-profitsharing' ) . '</p>';
        }
        echo '</form>';

        echo '<hr/>';
        echo '<h2>' . esc_html__( 'Category rules', 'woo-alipay-profitsharing' ) . '</h2>';
        $cat_rules = isset( $rules['category'] ) && is_array( $rules['category'] ) ? $rules['category'] : array();
        if ( ! empty( $cat_rules ) ) {
            echo '<table class="widefat" style="max-width:880px"><thead><tr><th>' . esc_html__( 'Category ID', 'woo-alipay-profitsharing' ) . '</th><th>' . esc_html__( 'Rules', 'woo-alipay-profitsharing' ) . '</th><th>' . esc_html__( 'Actions', 'woo-alipay-profitsharing' ) . '</th></tr></thead><tbody>';
            foreach ( $cat_rules as $cid => $rs ) {
                echo '<tr><td>' . esc_html( (string) $cid ) . '</td><td>';
                if ( ! empty( $rs ) ) {
                    echo '<ul style="margin:0">';
                    foreach ( $rs as $r ) {
                        $rid = (string) ( $r['receiver_id'] ?? '' );
                        $label = isset( $receivers[ $rid ]['label'] ) ? $receivers[ $rid ]['label'] : $rid;
                        $pct = (float) ( $r['percent'] ?? 0 );
                        echo '<li>' . esc_html( $label ) . ': ' . esc_html( $pct ) . '% ';
                        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
                        wp_nonce_field( 'woo_alipay_ps_category_rule_delete' );
                        echo '<input type="hidden" name="action" value="woo_alipay_ps_category_rule_delete" />';
                        echo '<input type="hidden" name="category_id" value="' . esc_attr( (string) $cid ) . '" />';
                        echo '<input type="hidden" name="receiver_id" value="' . esc_attr( $rid ) . '" />';
                        submit_button( esc_html__( 'Delete', 'woo-alipay-profitsharing' ), 'delete small', '', false );
                        echo '</form>';
                        echo '</li>';
                    }
                    echo '</ul>';
                }
                echo '</td><td></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__( 'No category rules yet.', 'woo-alipay-profitsharing' ) . '</p>';
        }

        echo '<h3>' . esc_html__( 'Add category rule', 'woo-alipay-profitsharing' ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:880px">';
        wp_nonce_field( 'woo_alipay_ps_category_rule_add' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_category_rule_add" />';
        echo '<table class="form-table"><tbody>';
        // Category selector
        $cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200 ) );
        echo '<tr><th><label for="category_id">' . esc_html__( 'Category', 'woo-alipay-profitsharing' ) . '</label></th><td>';
        if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
            echo '<select name="category_id" id="category_id">';
            foreach ( $cats as $cat ) {
                echo '<option value="' . esc_attr( (string) $cat->term_id ) . '">' . esc_html( $cat->name ) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="number" min="1" name="category_id" id="category_id" required />';
        }
        echo '</td></tr>';
        if ( ! empty( $receivers ) ) {
        echo '<tr><th><label for="receiver_id">' . esc_html__( 'Receiver', 'woo-alipay-profitsharing' ) . '</label></th><td><select name="receiver_id" id="receiver_id">';
            foreach ( $receivers as $rcv ) {
                $rid = (string) ( $rcv['id'] ?? '' );
                $lbl = (string) ( $rcv['label'] ?? $rid );
                echo '<option value="' . esc_attr( $rid ) . '">' . esc_html( $lbl ) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '<tr><th><label for="percent">' . esc_html__( 'Percent', 'woo-alipay-profitsharing' ) . '</label></th><td><input type="number" step="0.01" min="0" max="100" name="percent" id="percent" required /> %</td></tr>';
        echo '</tbody></table>';
        submit_button( esc_html__( 'Add rule', 'woo-alipay-profitsharing' ) );
        echo '</form>';

        // Product-level rules section
        echo '<hr/>';
        echo '<h2>' . esc_html__( 'Product rules', 'woo-alipay-profitsharing' ) . '</h2>';
        $prd_rules = isset( $rules['product'] ) && is_array( $rules['product'] ) ? $rules['product'] : array();
        if ( ! empty( $prd_rules ) ) {
            echo '<table class="widefat" style="max-width:880px"><thead><tr><th>' . esc_html__( 'Product ID', 'woo-alipay-profitsharing' ) . '</th><th>' . esc_html__( 'Rules', 'woo-alipay-profitsharing' ) . '</th></tr></thead><tbody>';
            foreach ( $prd_rules as $pid => $rs ) {
                echo '<tr><td>' . esc_html( (string) $pid ) . '</td><td>';
                if ( ! empty( $rs ) ) {
                    echo '<ul style="margin:0">';
                    foreach ( $rs as $r ) {
                        $rid2 = (string) ( $r['receiver_id'] ?? '' );
                        $label2 = isset( $receivers[ $rid2 ]['label'] ) ? $receivers[ $rid2 ]['label'] : $rid2;
                        $pct2 = (float) ( $r['percent'] ?? 0 );
                        echo '<li>' . esc_html( $label2 ) . ': ' . esc_html( $pct2 ) . '% ';
                        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
                        wp_nonce_field( 'woo_alipay_ps_product_rule_delete' );
                        echo '<input type="hidden" name="action" value="woo_alipay_ps_product_rule_delete" />';
                        echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $pid ) . '" />';
                        echo '<input type="hidden" name="receiver_id" value="' . esc_attr( $rid2 ) . '" />';
                        submit_button( esc_html__( 'Delete', 'woo-alipay-profitsharing' ), 'delete small', '', false );
                        echo '</form>';
                        echo '</li>';
                    }
                    echo '</ul>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__( 'No product rules yet.', 'woo-alipay-profitsharing' ) . '</p>';
        }

        echo '<h3>' . esc_html__( 'Add product rule', 'woo-alipay-profitsharing' ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:880px">';
        wp_nonce_field( 'woo_alipay_ps_product_rule_add' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_product_rule_add" />';
        echo '<table class="form-table"><tbody>';
// Product selector (autocomplete-like)
        $prods = get_posts( array( 'post_type' => 'product', 'posts_per_page' => 200, 'post_status' => 'publish' ) );
        echo '<tr><th><label for=\"product_id\">' . esc_html__( 'Product', 'woo-alipay-profitsharing' ) . '</label></th><td>';
        echo '<input type=\"text\" name=\"product_id\" id=\"product_id\" list=\"woo_alipay_ps_product_list\" class=\"regular-text\" placeholder=\"ID or Name (#ID)\" required />';
        echo '<datalist id=\"woo_alipay_ps_product_list\">';
        if ( ! empty( $prods ) ) {
            foreach ( $prods as $p ) {
                echo '<option value=\"' . esc_attr( $p->post_title . ' (#' . $p->ID . ')' ) . '\"></option>';
            }
        }
        echo '</datalist>';
        echo '</td></tr>';
        if ( ! empty( $receivers ) ) {
            echo '<tr><th><label for="prd_receiver_id">' . esc_html__( 'Receiver', 'woo-alipay-profitsharing' ) . '</label></th><td><select name="receiver_id" id="prd_receiver_id">';
            foreach ( $receivers as $rcv ) {
                $rid = (string) ( $rcv['id'] ?? '' );
                $lbl = (string) ( $rcv['label'] ?? $rid );
                echo '<option value="' . esc_attr( $rid ) . '">' . esc_html( $lbl ) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '<tr><th><label for="prd_percent">' . esc_html__( 'Percent', 'woo-alipay-profitsharing' ) . '</label></th><td><input type="number" step="0.01" min="0" max="100" name="percent" id="prd_percent" required /> %</td></tr>';
        echo '</tbody></table>';
        submit_button( esc_html__( 'Add product rule', 'woo-alipay-profitsharing' ) );
        echo '</form>';

        echo '</div>';
    }
}
