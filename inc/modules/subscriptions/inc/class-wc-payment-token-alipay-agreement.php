<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Payment_Token_Alipay_Agreement extends WC_Payment_Token {

    protected $type = 'alipay_agreement';

    public function get_display_name( $deprecated = '' ) {
        $agreement = $this->get_agreement_no();
        return sprintf( __( '支付宝代扣协议 %s', 'woo-alipay-subscriptions' ), $agreement ? '#' . substr( $agreement, -6 ) : '' );
    }

    // Agreement No
    public function set_agreement_no( $value ) { $this->set_meta( 'agreement_no', wc_clean( $value ) ); }
    public function get_agreement_no() { return $this->get_meta( 'agreement_no' ); }

    // Alipay user id
    public function set_alipay_user_id( $value ) { $this->set_meta( 'alipay_user_id', wc_clean( $value ) ); }
    public function get_alipay_user_id() { return $this->get_meta( 'alipay_user_id' ); }

    // Optional: product code (GENERAL_WITHHOLDING / CYCLE_PAY_AUTH)
    public function set_product_code( $value ) { $this->set_meta( 'product_code', wc_clean( $value ) ); }
    public function get_product_code() { return $this->get_meta( 'product_code' ); }

    public function validate() {
        $is_parent_valid = parent::validate();
        return $is_parent_valid && ! empty( $this->get_agreement_no() );
    }
}
