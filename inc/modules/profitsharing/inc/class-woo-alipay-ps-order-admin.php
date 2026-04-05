<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Woo_Alipay_PS_Order_Admin {
    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
    }

    public function add_metabox() {
        add_meta_box(
            'woo_alipay_ps_box',
            __( 'Alipay Profit Sharing', 'woo-alipay-profitsharing' ),
            array( $this, 'render_box' ),
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_box( $post ) {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) { return; }
        $order = wc_get_order( $post->ID );
        if ( ! $order ) { return; }

        $trace = (array) $order->get_meta( '_alipay_settle_trace', true );
        $done  = (int) $order->get_meta( '_alipay_settle_done' );
        echo '<p>' . esc_html__( 'Settled:', 'woo-alipay-profitsharing' ) . ' ' . ( $done ? '✔' : '✖' ) . '</p>';
        if ( ! empty( $trace ) ) {
            echo '<p><strong>' . esc_html__( 'Recent activity', 'woo-alipay-profitsharing' ) . '</strong></p><ul style="max-height:120px;overflow:auto">';
            $last = array_slice( $trace, -5 );
            foreach ( $last as $t ) {
                $line = ( $t['time'] ?? '' ) . ': ';
                if ( isset( $t['ok'] ) ) {
                    $line .= 'OK ' . ( is_array( $t['ok'] ) ? ( $t['ok']['out_request_no'] ?? '' ) : '' );
                } elseif ( isset( $t['error'] ) ) {
                    $line .= 'ERR ' . ( is_array( $t['error'] ) ? ( $t['error']['error'] ?? '' ) : (string) $t['error'] );
                } elseif ( isset( $t['exception'] ) ) {
                    $line .= 'EXC ' . ( is_array( $t['exception'] ) ? ( $t['exception']['error'] ?? '' ) : (string) $t['exception'] );
                }
                echo '<li>' . esc_html( $line ) . '</li>';
            }
            echo '</ul>';
        }

        // Compensation log
        $comp = (array) $order->get_meta( '_alipay_ps_comp', true );
        if ( ! empty( $comp ) ) {
            echo '<p><strong>' . esc_html__( 'Compensations', 'woo-alipay-profitsharing' ) . '</strong></p><ul style="max-height:120px;overflow:auto">';
            foreach ( array_slice( $comp, -5 ) as $c ) {
                $line = ( $c['time'] ?? '' ) . ' #' . ( $c['out_request_no'] ?? '' ) . ' ' . ( $c['receiver_id'] ?? '' ) . ' ' . ( $c['amount'] ?? '' );
                echo '<li>' . esc_html( $line ) . '</li>';
            }
            echo '</ul>';
        }

        // Suggestions
        $sugs = (array) $order->get_meta( '_alipay_ps_suggestions', true );
        if ( ! empty( $sugs ) ) {
            echo '<p><strong>' . esc_html__( 'Refund compensation suggestions', 'woo-alipay-profitsharing' ) . '</strong></p><ul style="max-height:120px;overflow:auto">';
            foreach ( $sugs as $idx => $sg ) {
                if ( (string) ( $sg['status'] ?? 'pending' ) !== 'pending' ) { continue; }
                $line = ( $sg['receiver_id'] ?? '' ) . ' ' . number_format( (float) ( $sg['amount'] ?? 0 ), 2 );
                echo '<li>' . esc_html( $line ) . ' ';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
                wp_nonce_field( 'woo_alipay_ps_compensate_from_suggestion' );
                echo '<input type="hidden" name="action" value="woo_alipay_ps_compensate_from_suggestion" />';
                echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
                echo '<input type="hidden" name="suggest_key" value="' . esc_attr( (string) $idx ) . '" />';
                submit_button( esc_html__( 'Send', 'woo-alipay-profitsharing' ), 'secondary small', '', false );
                echo '</form>';
                echo '</li>';
            }
            echo '</ul>';
        }

        // Preview computed jobs
        if ( class_exists( 'Woo_Alipay_PS_Service' ) ) {
            $jobs = Woo_Alipay_PS_Service::compute_jobs_for_order( $order );
            if ( ! empty( $jobs ) ) {
                echo '<p><strong>' . esc_html__( 'Preview', 'woo-alipay-profitsharing' ) . '</strong></p><ul>';
                foreach ( $jobs as $j ) {
                    $txt = ( $j['label'] ? $j['label'] . ' - ' : '' ) . ( $j['identity'] ?? '' ) . ' : ' . number_format( (float) $j['amount'], 2 );
                    echo '<li>' . esc_html( $txt ) . '</li>';
                }
                echo '</ul>';
            }
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'woo_alipay_ps_settle_order' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_settle_order" />';
        echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
        submit_button( esc_html__( 'Settle Now', 'woo-alipay-profitsharing' ), 'primary', 'submit', false );
        echo '</form>';

        // Start new round: clear out_request_no and settled flag, then enqueue
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
        wp_nonce_field( 'woo_alipay_ps_settle_order_new_round' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_settle_order_new_round" />';
        echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
        submit_button( esc_html__( 'Start New Round', 'woo-alipay-profitsharing' ), 'secondary', 'submit', false );
        echo '</form>';
    }
}
