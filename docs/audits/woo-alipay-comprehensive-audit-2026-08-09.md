# Woo Alipay comprehensive audit — 2026-08-09

> [CX] Scope: FeiCode source `ssh://git@feicode.com:2222/Weixiaoduo/woo-alipay.git`, commit `d135d670b1ee8ff0dcdc02e55f72e5b5312f1c47`, isolated branch `codex/woo-alipay-audit-20260809`. No production deployment, release, live merchant API call, or real transaction was performed.

## Source and boundary evidence

- FeiCode is the source of truth. At audit start it was 17 commits ahead of the public `Weixiaoduo/woo-alipay` mirror (`origin/master...github-mirror/master = 17 0`).
- Isolated worktree: `/home/parallels/Projects/woo-alipay-cx-audit`; original clone: `/home/parallels/Projects/woo-alipay`.
- Repository size is about 39 MiB; bundled `lib/` is about 36 MiB and contains the Alipay AOP/page-pay SDK. The SDK is committed source, not Composer-managed.
- Runtime testing is sandbox/local only. No production Alipay endpoint may be exercised by this audit.

## Functional inventory and data flows

| Surface | Entry points | Data/state | External dependency |
|---|---|---|---|
| Core web gateway (`alipay`) | classic checkout, Checkout Block, receipt page, Woo API notify, refund | Woo order, `_alipay_out_trade_no`, transaction ID, bounded last-notify receipt, gateway option | Alipay page/WAP pay, query, refund |
| Payment status query | order-pay polling AJAX, WP-Cron pending-order scan, admin reconcile query | order key, order status, merchant/Alipay transaction IDs | Alipay trade query |
| Timeout/retry | WP-Cron and webhook retry table | pending orders; custom retry table | Alipay query; local DB |
| Face-to-face QR (`alipay_facetopay`) | classic/Block checkout, QR page, refresh/query AJAX, notify, refund | stable merchant order number, QR URL, order key | Alipay precreate/query/refund; jsDelivr QR renderer |
| Huabei instalments (`alipay_installment`) | classic/Block checkout, notify, refund | instalment term, stable merchant order number | Alipay page pay/refund |
| Barcode/POS (`alipay_barcode`) | classic/Block field, admin order metabox AJAX, notify | auth code input, stable merchant order number | Alipay trade pay/query |
| Fund authorization | classic/Block checkout and order-admin controls | authorization/payment IDs and order metadata | Alipay fund-auth APIs |
| Mini-program (`alipay_wap`) | gateway receipt/return/notify | merchant number and trade number | Alipay SDK; legacy compatibility code |
| Cross-border (`acbop`) | gateway receipt/return/notify | merchant/trade number, currency, payment institution | Alipay overseas products |
| Subscriptions/agreement | agreement signing notify, scheduled renewal charge/retry | Woo payment token/agreement number, renewal order metadata | Woo Subscriptions, Alipay agreement/trade APIs |
| Profit sharing | payment-complete hook, order/admin actions, Action Scheduler/WP-Cron fallback | receiver rules, queued jobs, compensation state | Alipay profit-sharing APIs |
| Bill reconciliation | admin screen, scheduled/manual bill parsing | CSV/ZIP under uploads, matching summary | signed Alipay bill URL and download host |
| Updater | WordPress Update URI filters | transient/plugin update metadata | `updates.wenpai.net` |

The classic and Blocks implementations coexist. Core, face-to-face, Huabei, barcode, and fund-auth register `AbstractPaymentMethodType` integrations. Mini-program, cross-border, subscriptions, profit sharing, and reconciliation remain separate bundled modules and do not all have equivalent Checkout Block user flows.

## Multisite, activation, upgrade, and uninstall

- Merchant order numbers include a blog prefix only when generated through `Alipay_SDK_Helper`; legacy mini/cross-border formats do not consistently do so.
- Activation stores `woo_alipay_plugin_version`; webhook schema creation now also runs through a versioned `admin_init` guard because the previous activation callback could be registered too late.
- There is no explicit version-to-version data migration framework beyond individual option/schema guards. Version header is `3.2.0`, while some source comments/assets refer to `3.3.0`.
- Deactivation clears core query, timeout, webhook-retry, and profit-sharing cron hooks.
- Uninstall deletes only the current site's `woocommerce_alipay_settings`. It deliberately does not delete order/transaction history, retry/audit table data, module settings, tokens, or multisite data. A future destructive purge must be explicit opt-in and must never silently remove financial evidence.

## Findings and remediation

### Fixed in this branch

| Severity | Finding | Remediation |
|---|---|---|
| P0 | Invalid signed-context fields could fail a legitimate order; main callbacks persisted/logged raw notification data. | Verify signature before lookup/mutation; validate app ID, payment method, merchant number, amount, and transaction ID; invalid callbacks never change order state; persist/log bounded fields only. |
| P0 | Public order-query and face-to-face AJAX accepted an order ID plus a site-wide nonce, enabling cross-order query/state mutation and disclosure of received URLs. | Require the matching Woo order key (or `manage_woocommerce`), exact gateway, and an unpaid order; localize only an authorized order ID/key. |
| P0 | Main, face-to-face, Huabei, barcode, mini-program, and cross-border notifications lacked a common complete context check. | Added common post-signature context validation and applied it to those notify handlers. |
| P1 | Main gateway read options before `init_settings()`, silently replacing saved settings with defaults/empty values. | Initialize form fields/settings before reading every gateway option. |
| P1 | Receipt/refresh/retry flows generated a new merchant number; refunds fabricated a number when the original was absent. | Reuse one stored `_alipay_out_trade_no`; retain the core legacy key; refuse refund when the submitted identifier is missing. |
| P1 | Timeout cron cancelled a submitted order even when Alipay query failed or still reported pending, creating a paid-at-Alipay/cancelled-in-Woo race. | Fail closed: do not cancel a remotely submitted trade unless a verified close workflow exists. |
| P1 | Query/reconcile could mark paid without matching returned amount, merchant number, payment method, and existing transaction ID. | Validate query context before `payment_complete`; reconciliation now refuses a mismatch. |
| P1 | Barcode silently sent a foreign-currency numeric amount as CNY when no rate existed. | Hide/refuse the gateway and return `WP_Error` until a positive CNY rate exists. |
| P1 | Reconciliation downloaded an API-provided arbitrary URL with `wp_remote_get`. | Require HTTPS Alipay/Alipay sandbox host suffixes, use `wp_safe_remote_get`, and cap the response to 25 MiB. |
| P1 | A hidden GET switch enabled three gateways without CSRF protection. | Require `manage_woocommerce` and an explicit action nonce. |
| P1 | Enabled gateway weakened WordPress username sanitization globally. | Removed the global `sanitize_user` filter registration. |
| P1 | Webhook retry table activation could be missed and stored full request payloads. | Added versioned table creation and allow-listed request fields; processing results update retry state. |
| P1 | F2F enqueued nonexistent core asset paths and referenced currency before initialization. | Use module asset URLs and initialize currency first. |
| P1 | Direct `post_meta` access in mini/cross-border contradicted declared HPOS compatibility. | Use `WC_Order` metadata methods and HPOS `meta_query` order lookups. |
| P1 | Shared global `WenPai_Updater` collided with divergent updater copies in sibling plugins; it also called PHP 8 `str_starts_with` while declaring PHP 7.4. | Use plugin-specific `Woo_Alipay_WenPai_Updater`, sync updater version comment, and replace the PHP 8-only call. |
| P2 | Deactivation left recurring jobs scheduled. | Clear known plugin cron hooks on deactivation. |

### Open or decision-gated

| Severity | Evidence / risk | Required decision or test |
|---|---|---|
| P1 | Merchant private/public keys remain plaintext in serialized WooCommerce options and are displayed in settings. | Introduce an environment/filter-backed secret source and encrypted-at-rest migration with rollback; do not silently invalidate existing keys. |
| P1 | The bundled SDK is 36 MiB, not dependency-managed, and contains thousands of files. | Pin an upstream SDK release/SBOM and remove unused API surface in a major-version packaging change, after gateway regression tests. |
| P1 | F2F renders QR codes from public jsDelivr on a payment page. | Vendor and integrity-review the QR library; removing it now would break checkout. |
| P1 | No test may validate live signatures, refunds, subscriptions, profit sharing, or real settlement without controlled Alipay sandbox credentials. | Build a dedicated merchant sandbox fixture and recorded, non-secret response corpus. Production credentials/transactions remain prohibited. |
| P1 | A safe remote-close operation is not integrated with timeout cancellation. | Add query → signed context check → Alipay close → confirm closed → Woo cancel state machine. Current branch favors stale pending orders over double fulfilment/cancellation. |
| P2 | Mini/cross-border retain legacy code patterns, return-page state changes, mixed text domains, and duplicated configuration paths. | Deprecate return-page mutation, then move each to a separately testable module. |
| P2 | Profit-sharing/order admin uses legacy `shop_order` screens and several cron fallbacks. | Verify HPOS admin-screen hooks and Action Scheduler behavior in both HPOS modes. |
| P2 | Uninstall is current-site only and leaves module settings/table data. | Keep financial evidence by default; add a documented multisite-aware opt-in purge command rather than broader automatic deletion. |
| P2 | Readme/version metadata had drifted and internal source references `3.3.0` while plugin version is `3.2.0`. | Choose the next release version after acceptance; do not publish from this branch. |

## Test strategy and evidence

The repository's prior `scripts/tests/run-tests.sh` was unsafe: it deployed over a WordPress path under a desktop Trash directory, used raw SSH, and deleted/replaced the target plugin. It has been replaced with non-deploying modes:

- `./scripts/tests/run-tests.sh static`: all PHP syntax, first-party JavaScript syntax, and `git diff --check`.
- `./scripts/tests/run-tests.sh contracts`: notification-context unit contracts plus HPOS/PHP-compatibility source contracts.
- `./scripts/tests/run-tests.sh all`: static + contracts only; never installs or deploys.
- `WOO_ALIPAY_TEST_WP_PATH=/isolated/path ./scripts/tests/run-tests.sh runtime`: refuses historical Trash paths and checks gateways only in an explicitly provided isolated WordPress.

A local `.wp-env.json` pins WooCommerce 10.2.2 and PHP 8.3 and mounts this worktree. Runtime acceptance must include plugin activation, gateway registration, Checkout Blocks registration, HPOS on/off order creation/query, a signed synthetic callback/replay/mismatch matrix, Plugin Check, and package re-install. Synthetic callbacks use generated test RSA keys and `0.01 CNY`; they never contact Alipay.

Recorded results: the safe suite passed all 8,895 PHP files and 8 security assertions; PHPCompatibilityWP passed for PHP 7.4+; isolated WordPress 7.0.3/WooCommerce 10.2.2 activation, gateway/Blocks registration, HPOS off/on metadata query, generated-key callback/replay/mismatch matrix, candidate ZIP integrity, and ZIP reinstall all passed. PHPCS remains a failing baseline (9,342 errors / 872 warnings across 16 touched files). PHPStan remains incomplete (124 missing WooCommerce/SDK-symbol findings at level 1). Plugin Check 2.0.0 was attempted against the full repository, remained CPU-bound in the bundled SDK for 10 minutes, and was terminated with exit 124 and no findings output; this is INCOMPLETE, not PASS.

Test results and exact exit codes are recorded in `docs/ai-context/HANDOFF.md`. A zero-sample or unavailable tool is recorded as SKIP/INCOMPLETE, never PASS.

## Product direction

### Keep in the next version

- Core web/WAP payment, refund, signature verification, stable merchant-number idempotency, sandbox switch, HPOS/Blocks declarations, bounded logs, and authenticated order polling.
- F2F, Huabei, and barcode only behind explicit merchant enablement and currency/config guards.
- Reconciliation as read-only comparison by default; state sync remains an explicit admin action with context matching.

### Simplify

- One notification validator, one query result validator, one redacted logger, one merchant-number service, and one schema/version migration runner.
- One credential source shared by all modules; module screens should show readiness rather than duplicate secrets.
- One Action Scheduler queue instead of multiple WP-Cron loops and per-order constructor hooks.

### Split or deprecate

- Split cross-border, mini-program, subscriptions/agreement, profit sharing, fund authorization, and reconciliation into independently versioned add-ons after preserving upgrade aliases.
- Deprecate return-page order mutation, hidden URL maintenance switches, raw SDK logging, and any refund fallback that invents an identifier.
- Replace the embedded generic updater with a namespaced shared package or per-plugin generated class.

### Version roadmap

1. **Next patch/minor:** merge the verified security/idempotency/HPOS fixes, add sandbox callback fixtures, complete Plugin Check/PHPCS/PHPStan baselines, vendor QR rendering, and document credential rotation. No feature removal.
2. **Following major/minor:** module split with migration aliases, encrypted/filter-backed secrets, Action Scheduler state machine for query/close/refund/retry, SDK/SBOM reduction, multisite-aware lifecycle tooling, and removal of legacy return-page mutation after a deprecation cycle.

## References

- WooCommerce HPOS extension recipe: <https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/>
- WooCommerce order query API: <https://developer.woocommerce.com/docs/features/orders/wc-get-orders/>
- WooCommerce Checkout payment method integration: <https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration>
