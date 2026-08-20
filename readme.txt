=== Wei Alipay Payment Gateway for WooCommerce ===
Contributors: weixiaoduo
Tags: alipay, payments, payment gateway, woocommerce, china
Requires at least: 6.6
Tested up to: 7.0
Stable tag: 4.0.1
Requires PHP: 7.4
WC requires at least: 8.3
WC tested up to: 10.9.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Alipay payment gateway for WooCommerce, developed by Weixiaoduo.com.

== Description ==

Wei Alipay Payment Gateway for WooCommerce integrates Mainland China Alipay payments with WooCommerce.

The primary gateway supports desktop and mobile web payments, RSA2 signing and notification verification, replay-safe callbacks, WooCommerce refunds, CNY conversion, High-Performance Order Storage (HPOS), and Checkout Blocks.

Optional modules for face-to-face QR, Huabei instalments, barcode/POS, mini-program, cross-border, subscriptions, fund authorization, profit sharing, and reconciliation are bundled. Enable an optional module only when the merchant account is entitled to that product and the flow has been tested in an isolated sandbox or offline fixture.

= Security and operational notes =

* Configure HTTPS before accepting payments.
* Never paste a merchant private key, application secret, complete callback payload, or customer data into a public issue.
* The Alipay public key is not the merchant application public key.
* Mini-program payments use RSA2 by default. Legacy MD5 requires two explicit transition controls and cannot be selected by automatic fallback.
* Unreadable encrypted credentials fail closed.
* Uninstall retains payment settings and financial order metadata by default. It never deletes orders.
* A successful local test or candidate ZIP does not prove that a production merchant flow has passed.

= Compatibility =

Requires PHP 7.4+, WordPress 6.6+, and WooCommerce 8.3+. The 4.0.1 candidate was exercised with WordPress 7.0.2, WooCommerce 10.9.4, and PHP 8.3 for activation, gateway registration, HPOS enabled, and F2F/Huabei Blocks asset registration. HPOS disablement was blocked by unsynchronized clone orders, and the declared minimum combination has not completed the same runtime matrix.

= Source and support =

Wei Alipay is developed by Weixiaoduo.com. FeiCode is the canonical development upstream. The current `Weixiaoduo/woo-alipay` GitHub repository is a downstream mirror. After an approved remote rename, its target name is `Weixiaoduo/wei-alipay`; only approved FeiCode commits may be synchronized.

The project preserves applicable upstream copyright and license notices. Third-party component provenance and license status are recorded in the package SBOM.

== Installation ==

1. Install the release ZIP from the WordPress Plugins screen.
2. Activate WooCommerce, then activate Wei Alipay.
3. Open WooCommerce > Settings > Payments > Alipay.
4. Enter the Alipay application ID, Alipay public key, and merchant application private key.
5. Use Alipay sandbox for the first integration test.
6. Configure the asynchronous notification URL as `https://example.com/wc-api/WC_Alipay/`.
7. Verify order creation, signed notification handling, exact replay, amount mismatch rejection, and an offline or sandbox refund before production approval.

== Frequently Asked Questions ==

= Does the plugin rename the WooCommerce gateway ID? =

No. The product and package are named Wei Alipay, while the financial runtime gateway ID remains `alipay` so existing orders, settings, refunds, callbacks, and scheduled work remain addressable.

= Does the plugin automatically fall back to MD5? =

No. RSA2 is the default. Legacy Mini-program MD5 can only be enabled as an explicitly acknowledged transition mode and never activates after an RSA2 failure.

= Are merchant keys included in logs or support exports? =

They must not be. Sensitive settings are encrypted where supported and logging is redacted. Treat any unexpected credential disclosure as a security incident.

= Is every optional module production-ready for my merchant account? =

No. Availability depends on the merchant agreement, store configuration, and module-specific acceptance evidence.

== Changelog ==

= 4.0.1 =
* Require feature enablement, user/order ownership, nonces, app binding and persistent idempotency for subscription signing and initial charges.
* Protect subscription agreement removal against cross-site request forgery.
* Bind Huabei and face-to-face refunds to request IDs, transaction context, exact amounts and replay locks.
* Repair Huabei and face-to-face Checkout Blocks asset URLs.
* Align multi-currency availability and administrative exchange-rate notices.
* Restrict private updater packages to approved HTTPS hosts and sanitize remote Markdown links.

= 4.0.0 =
* Rename the product and package to Wei Alipay Payment Gateway for WooCommerce.
* Keep the existing financial gateway, settings, order metadata, callback, and scheduling identities.
* Harden signature, merchant/order/trade/amount/time binding and replay handling.
* Default Mini-program flows to RSA2 without automatic MD5 downgrade.
* Add encrypted credential migration, rotation, rollback generation, and fail-closed reads.
* Declare HPOS and Checkout Blocks compatibility and add isolated runtime checks.
* Vendor the checkout QR renderer and record third-party components in an SPDX SBOM.
* Define FeiCode as the canonical upstream and GitHub as an approved-SHA downstream mirror.

= 3.2.0 =
* Add callback, amount, order lookup, refund idempotency, HPOS, Blocks, multisite, and release-candidate hardening.

= 1.1.3 =
* Strip percent characters from data sent to Alipay.
* Update legacy compatibility metadata.

= 1.1.2 =
* Update legacy WooCommerce compatibility metadata.

= 1.1.1 =
* Improve high-value amount handling and shared amount conversion.

= 1.1 =
* Add translations and payment parameter validation.

= 1.0 =
* Initial release.
