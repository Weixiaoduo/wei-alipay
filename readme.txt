=== Wei Alipay Payment Gateway for WooCommerce ===
Contributors: frogerme
Tags: alipay, alibaba, payments, payment gateway, 支付宝, 阿里巴巴
Requires at least: 6.6
Tested up to: 7.0
Stable tag: 4.0.0
Requires PHP: 7.4
WC requires at least: 8.3
WC tested up to: 10.2.2
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Alipay China payment gateway for WooCommerce.

== Description ==

Wei Alipay adds an Alipay China payment gateway to WooCommerce-powered websites.
This Mainland China gateway allows customers to pay both in mobile browsers or from their computer.

### Requirements

* WordPress 6.6 or later, PHP 7.4 or later, and WooCommerce 8.3 or later.
* A [China Mainland Alipay merchant account](https://b.alipay.com/).
* The Payment products "支付产品" Computer website payment "电脑网站支付" and Mobile website payment "手机网站支付" enabled.

### Important Notes

* The primary gateway is for Mainland China Alipay. Optional bundled modules include cross-border, face-to-face QR, Huabei instalments, barcode/POS, mini-program, subscriptions, fund authorization, profit sharing, and reconciliation. Enable only the modules covered by your merchant agreement and testing.
* Make sure to read the "TROUBLESHOOT, FEATURE REQUESTS AND 3RD PARTY INTEGRATION" section below and [the full documentation](https://github.com/froger-me/wei-alipay/blob/master/README.md) before contacting the author.

### Overview

This plugin adds the following major features to WooCommerce:

* **Payment of WooCommerce orders in mobile web browser app:** calls the Alipay mobile app for a seamless experience.
* **Payment of WooCommerce orders standard in web browser:** authentication performed via credentials or QR code on desktop/laptop.
* **Refund of WooCommerce orders:** manual WooCommerce refunds use the original stored merchant order number. Automatic orphan-payment refund is not enabled.
* **Multi-currency support:** using an exchange rate against Chinese Yuan configured in the settings.

Compatible with [WooCommerce Multilingual](https://wordpress.org/plugins/woocommerce-multilingual/), [WPML](http://wpml.org/), [Ultimate Member](https://wordpress.org/plugins/ultimate-member/), and any caching plugin compatible with WooCommerce.

### Support and source repository

Wei Alipay Payment Gateway for WooCommerce is developed by Weixiaoduo.com.

The canonical source, pull requests, candidate CI, tags, and releases are maintained in the FeiCode Weixiaoduo organization. The GitHub Weixiaoduo/wei-alipay repository is a public downstream mirror and is not the development upstream.

The project retains upstream copyright and license notices. Do not include merchant private keys, shared secrets, transaction payloads, or customer data in issue reports.

== Installation ==

This section describes how to install the plugin and get it working.

1. Upload the plugin files to the `/wp-content/plugins/wei-alipay` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Edit plugin settings

== Changelog ==

= 4.0.0 =
* Rename the plugin and package to Wei Alipay Payment Gateway for WooCommerce.
* Keep Alipay financial identities stable for existing orders, refunds, settings, and callbacks.
* Establish FeiCode as the canonical upstream and GitHub as a controlled public downstream mirror.

= 3.2.0 =
* Harden notification signature, merchant/order/trade/amount/time binding and replay handling.
* Add explicit RSA2-first Mini-program migration policy without automatic MD5 downgrade.
* Add encrypted secret migration, rotation and fail-closed rollback generation.
* Declare and test WooCommerce HPOS and Checkout Blocks compatibility.
* Add multisite lifecycle policy, first-party static analysis, SBOM and reproducible candidate packaging.

= 1.1.3 =
* Strip `%` from data sent to Alipay
* WC tested up to: 4.0.1
* WordPress tested up to: 5.4

= 1.1.2 =
* WC tested up to: 4.0

= 1.1.1 =
* Adjust price parameters for high values, and use the `maybe_convert_amount` method throughout
* WC tested up to: 3.9.2

= 1.1 =
* Add translations
* Make sure parameters send to Alipay stay within string length requirements
* Fix floating point arithmetic
* WC tested up to: 3.9.1

= 1.0 =
* First version