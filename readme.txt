=== Wei Alipay Payment Gateway for WooCommerce ===
Contributors: weixiaoduo
Tags: alipay, payments, payment gateway, woocommerce, china
Requires at least: 6.6
Tested up to: 7.0
Stable tag: 4.3.1
Requires PHP: 7.4
WC requires at least: 8.3
WC tested up to: 10.9.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Alipay payment gateway for WooCommerce, developed by Weixiaoduo.com.

== Description ==

Wei Alipay Payment Gateway for WooCommerce integrates Mainland China Alipay payments with WooCommerce.

The primary gateway supports desktop and mobile web payments, RSA2 signing and notification verification, replay-safe callbacks, WooCommerce refunds, CNY conversion, High-Performance Order Storage (HPOS), and Checkout Blocks.

Optional modules for face-to-face QR, Huabei instalments, barcode/POS, mini-program, cross-border, subscriptions, fund authorization, profit sharing, webhook retry, and reconciliation are bundled. Select them explicitly under Wei Alipay > Features. Disabled modules do not register their payment method, administration pages, or normal background processing, and their retained settings and transaction history are not deleted. Enable an optional module only when the merchant account is entitled to that product and the flow has been tested in an isolated sandbox or offline fixture.

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

Wei Alipay is developed by Weixiaoduo.com. FeiCode `Weixiaoduo/wei-alipay` is the canonical development upstream, and GitHub `Weixiaoduo/wei-alipay` is a downstream mirror. The former `woo-alipay` name is retained only in historical references and stable runtime compatibility identifiers; only approved FeiCode commits may be synchronized.

The project preserves applicable upstream copyright and license notices. Third-party component provenance and license status are recorded in the package SBOM.

== Installation ==

1. Install the release ZIP from the WordPress Plugins screen.
2. Activate WooCommerce, then activate Wei Alipay.
3. Open Wei Alipay > Features and select only the optional products the merchant is entitled to use.
4. Open WooCommerce > Settings > Payments > Alipay and enter the Alipay application ID, Alipay public key, and merchant application private key. Optional payment methods require a second configuration and enablement step in WooCommerce.
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

= 4.3.1 =
* Refined standalone admin UI across Overview, Payment Methods, Reconciliation, Webhook Logs, Account, and Help: equal-height 2/3-column cards, removed developer jargon and duplicated panels, unified payment/local-tools save action, fixed avatar fallback and webhook column widths.
* Decluttered WooCommerce Alipay gateway settings: conditional public-key vs certificate textarea visibility, collapsible reliability parameters, removed empty sections and hardcoded sample AppID.
* Updated WooCommerce Checkout Blocks asset versions to 4.3.1.

= 4.3.0 =
* 自动更新器：改走共用文派更新服务器（`wenpai-bridge`）`/api/v1/update-check` 与 `/api/v1/plugins/{slug}` 协议，域名继续使用 `updates.weixiaoduo.com`，放行 `updates.weixiaoduo.com` 与 `downloads.feicode.com` 下载源，并在过渡期对 `/api/v1` 返回 404 自动回退读取 `/{slug}.json`
* 跨境支付（cross-border）：补齐幂等 `process_refund()`（锁 + 确定性 `out_request_no` + 金额/订单号核对 + 已处理表）并恢复 `refunds` 能力声明，补加 `wc_back_link()` 与公钥证书模式兼容
* 资金预授权（fund-auth）：移除沙箱本地 `SIM-AUTH-*` 假模拟，预授权转扣改用 `AlipayTradePayRequest`（`alipay.trade.pay`），新增先验签后查单的 `woocommerce_api_wc_alipay_fund_auth` 异步通知处理
* 分账（profitsharing）：修复商品规则与默认规则叠加超 100% 重复分账缺陷（P1-6），加入严格总额上限与折后按比例缩放，补齐 `AlipayTradeRoyaltyRelationBindRequest` / `UnbindRequest` 分账关系绑定与解绑接口
* 订阅自动代扣（subscriptions）：改用 `AlipayTradePayRequest`（`alipay.trade.pay`）并按实际支付状态区分已支付/处理中/需查询确认（P1-5），改走 `Alipay_SDK_Helper::create_alipay_service()` 消除 PHP 8.4 动态属性告警，并在订阅模块启用时避免核心网关重复挂载续费钩子
* 安全门控：上述四个高阶模块在完成真实支付宝沙箱验收前继续保持功能中心不可启用态

= 4.2.0 =
* Added Alipay Public Key Certificate Mode (`appCert` / `alipayCert` / `alipayRootCert` via `AopCertClient`) across main gateway, F2F, Huabei, Barcode, Reconcile, and notification verification, with Overview certificate expiry warnings (30-day warn / 7-day critical) and zero migration impact for existing public-key sites.
* Added Scheduled Daily Reconciliation (`Wei_Alipay_Reconcile_Scheduler`) using WooCommerce Action Scheduler (with WP-Cron fallback), privacy-safe 30-day summary history, idempotent daily execution, throttled discrepancy email alerts, collapsible schedule card on the Reconcile page, and Overview 7-day activity status.
* Eliminated PHP 8.4 dynamic property deprecation on `AopClient::$charset` / `AopCertClient::$charset` (switched to native `$postCharset`).

= 4.1.1 =
* Fixed orphan transaction email PHP 8.4 / WooCommerce 11 Email Preview TypeError, broken template path arguments, `{transaction_id}` heading placeholder mismatch, plain-text `<code>` markup, and localized Recipient(s) / heading description fields.
* Absorbed lower-precedence key-material decryption fallback in `Wei_Alipay_Secret_Store::keyring()` so adding `WEI_ALIPAY_ENCRYPTION_KEY` on an existing site keeps previously encrypted secrets readable.
* Fixed `Aliay` typo and broken `section=wc_alipay` link in currency notice; added `wc_back_link()` on WooCommerce Alipay / F2F / Huabei settings screens.
* Upgraded Account disconnect action to a gated POST confirmation form (`Wenpai_Admin_UI::gate_html()`) and aligned in-plugin help/directory links to live `www.weixiaoduo.com` endpoints.

= 4.1.0 =
* Upgraded standalone Wei Alipay admin screens (Overview, Payment Methods, Reconcile, Webhooks, Account, Help) to vendored `wenpai-admin-ui` v0.13.8 with Weixiaoduo brand tokens and per-admin optional Alipay Blue (`#1677ff`) palette toggle.
* Switched plugin updates to `updates.weixiaoduo.com` (shared UpdatePulse infrastructure with `updates.wenpai.net`).
* Implemented Connect-Latch v3 site identity linking (PKCE S256 via `account.weixiaoduo.com`) with server-side `pre_update_option_*` save lock on disconnected optional methods while preserving live payments, callbacks, and refunds.
* Removed legacy `mini-program` (`alipay_wap`) module, slimmed bundled Alipay SDK via allowlist from 8,829 to 41 files (ZIP reduced to 1.4 MB), and fixed P0/P1 issues across F2F, Huabei, Barcode, Reconcile, Webhooks, Blocks, HPOS, and zh_CN translations.

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
