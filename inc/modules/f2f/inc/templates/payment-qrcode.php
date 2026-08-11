<?php
/**
 * 当面付二维码支付页面模板
 * 
 * @var WC_Order $order 订单对象
 * @var string $qr_code 二维码数据
 * @var int $qrcode_size 二维码尺寸
 * @var int $timeout 二维码有效期（秒）
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html__('扫码支付', 'woo-alipay'); ?> - <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
</head>
<body class="alipay-facetopay-page">

<div class="alipay-qrcode-container">
    <div class="alipay-qrcode-header">
        <h2><?php esc_html_e( '请使用支付宝扫码支付', 'woo-alipay' ); ?></h2>
        <div class="order-info">
            <?php echo esc_html( sprintf( __( '订单号: #%s', 'woo-alipay' ), $order->get_id() ) ); ?>
        </div>
        <div class="order-amount">
            ¥<?php echo esc_html( number_format_i18n( $order->get_total(), 2 ) ); ?>
        </div>
    </div>
    
    <div class="alipay-qrcode-wrapper">
        <div id="alipay-qrcode" 
             data-qrcode="<?php echo esc_attr($qr_code); ?>"
             data-size="<?php echo esc_attr($qrcode_size); ?>"
             data-order-id="<?php echo esc_attr($order->get_id()); ?>">
        </div>
    </div>
    
    <div class="alipay-qrcode-tips">
        <p><?php esc_html_e( '请打开支付宝APP扫描上方二维码', 'woo-alipay' ); ?></p>
        <p><?php esc_html_e( '二维码有效期内完成支付，支付完成后会自动跳转', 'woo-alipay' ); ?></p>
        <p class="alipay-deeplink">
            <a class="button button-primary" href="<?php echo esc_url( 'alipays://platformapi/startapp?saId=10000007&qrcode=' . rawurlencode( $qr_code ), array( 'alipays' ) ); ?>"><?php esc_html_e( '在支付宝中打开', 'woo-alipay' ); ?></a>
        </p>
    </div>
    
    <div class="alipay-payment-status">
        <span class="status-text"><?php esc_html_e( '等待支付中...', 'woo-alipay' ); ?></span>
    </div>
    
    <div class="alipay-qrcode-timer"></div>
</div>

<input type="hidden" id="alipay-facetopay-nonce" value="<?php echo esc_attr(wp_create_nonce('alipay_facetopay_query')); ?>">
<input type="hidden" id="alipay-facetopay-order-key" value="<?php echo esc_attr($order->get_order_key()); ?>">

<?php wp_footer(); ?>

</body>
</html>