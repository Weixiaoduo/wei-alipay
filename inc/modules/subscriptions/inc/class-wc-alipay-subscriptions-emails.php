<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Alipay_Subscriptions_Emails {
    public function __construct() {
        add_action( 'woocommerce_email_before_order_table', array( $this, 'inject_email_hint' ), 5, 4 );
    }

    /**
     * Inject a helper hint into renewal invoice emails to guide Alipay payment
     * Only runs for customer renewal invoice emails.
     */
    public function inject_email_hint( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || ! $email || empty( $email->id ) ) {
            return;
        }
        // Only target WooCommerce Subscriptions renewal invoice email
        if ( 'customer_renewal_invoice' !== $email->id ) {
            return;
        }
        // Load configured hint
        $hint = get_option( 'woo_alipay_subscriptions_email_hint', '' );
        if ( ! $hint ) {
            return;
        }
        echo '<div class="woo-alipay-subscriptions-hint" style="margin:10px 0;padding:10px;background:#f6f7f7;border-left:4px solid #1d2327;">' . wp_kses_post( wpautop( $hint ) ) . '</div>';
    }
}

new WC_Alipay_Subscriptions_Emails();
