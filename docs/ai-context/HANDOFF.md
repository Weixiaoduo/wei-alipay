# Woo Alipay handoff

## [CX] 2026-08-09 comprehensive audit/remediation

- Source of truth: `ssh://git@feicode.com:2222/Weixiaoduo/woo-alipay.git` at baseline `d135d670b1ee8ff0dcdc02e55f72e5b5312f1c47`; public mirror was 17 commits behind.
- Isolated worktree/branch: `/home/parallels/Projects/woo-alipay-cx-audit`, `codex/woo-alipay-audit-20260809`.
- Code/audit commit: `6a57a68` (`fix: harden Alipay order and callback flows`). FeiCode policy refused a direct branch push; the same commit was sent without merge through AGit to `refs/pull/1/head` (`https://feicode.com/Weixiaoduo/woo-alipay/pulls/1`).
- Boundary: no production deployment/release/default-branch merge, no live Alipay call, no real transaction, and no historical-data deletion.
- Delivered: callback context validation/redaction/idempotency, AJAX order-key authorization, query/reconcile amount checks, timeout fail-closed behavior, HPOS order metadata/query repairs, F2F assets/authorization, barcode currency guard, reconciliation SSRF guard, updater isolation/PHP 7.4 fix, webhook table/retry hardening, safe tests, compatibility metadata, and audit report.
- Audit report: `docs/audits/woo-alipay-comprehensive-audit-2026-08-09.md`.

### Verification

- `./scripts/tests/run-tests.sh all` — exit 0: 8,895 PHP files linted, first-party JavaScript parsed, `git diff --check` clean, and 8 notification/security contract assertions passed.
- PHPCompatibilityWP on all modified PHP files with `testVersion=7.4-` — exit 0.
- Isolated WordPress 7.0.3 / WooCommerce 10.2.2 / PHP 8.3 runtime — activation exit 0; seven gateway IDs registered; five Checkout Block payment method integrations registered.
- HPOS off and on — both created a real Woo order and found `_alipay_out_trade_no` using `wc_get_orders(meta_query)`; exit 0 in both modes.
- Generated-key callback matrix in the isolated runtime — valid callback `success`, exact replay `success` with note count unchanged at 2, signed amount/app/transaction mismatches and invalid signature all `fail`; order remained paid with the original transaction; exit 0. No Alipay network call occurred.
- Candidate ZIP `/tmp/woo-alipay-package-20260809/woo-alipay-3.2.0-candidate.zip` — 9,003 entries / 9,290,847 bytes / SHA-256 `bfbf76cac342b2ac2957b521b7c0230a03bdfb8b90b60dcdd1035f4d9a4520db`; `unzip -t`, top-level exclusion and secret-pattern checks exit 0; clean ZIP install/activation and core gateway registration exit 0.
- WordPress PHPCS on the 16 modified PHP files — exit 2 with 9,342 errors and 872 warnings, overwhelmingly pre-existing formatting/naming/escaping debt; not PASS and not auto-fixed because a 9,219-change formatting rewrite would obscure payment fixes.
- PHPStan level 1 on four core files — exit 1 with 124 findings, dominated by missing WooCommerce/SDK symbols because the repository has no WooCommerce/PHPStan bootstrap; result is incomplete, not PASS.
- Plugin Check 2.0.0 full repository scan — terminated after 10 minutes with exit 124 and no result; the bundled 8,000+ file SDK kept the scanner CPU-bound. This is INCOMPLETE, not PASS.

### Open constraints

- Production/sandbox merchant network flows, real refund, agreement renewal, profit sharing, and settlement were not exercised.
- Plaintext option-stored keys, external QR CDN, SDK packaging/SBOM, remote-close timeout state machine, legacy mini/cross-border cleanup, multisite lifecycle, and module split remain follow-up work described in the audit.

## [CX] 2026-08-09 second-round payment security closure

- Re-reviewed PR #1 and fixed a first-round false-positive HPOS claim: legacy `wc_get_orders(meta_query)` ignored the filter. Added data-store-aware exact lookup, duplicate fail-closed behavior, and applied it to core/F2F/Huabei/barcode/mini/cross-border/reconcile paths.
- Added RSA2-only verification, strict timestamp/UTF-8/minor-unit amount parsing, seller/app/merchant/transaction binding, notification transition policy, DB replay locks with revalidation, refund request idempotency/response binding, and bounded redaction.
- Added 40 contract assertions plus isolated concurrent replay, cross-order tamper, expiry/future, RSA downgrade, invalid encoding, transaction mismatch, late-close, and close-before-success runtime cases. Test RSA keys and 0.01 CNY orders were local only; no Alipay request was sent.
- HPOS off exact lookup returned orders 17/18; HPOS on exact lookup returned 19/20 and a signed callback completed only 19. Store API Checkout Blocks created HPOS order 22 and returned the Alipay order-pay redirect without contacting Alipay.
- PHPStan bootstrap: WooCommerce 10.2.2 stubs, WordPress extension, SDK boundary stubs, and `phpstan-baseline.neon` with 83 historical findings. Full first-party level-1 run exit 0; changed security files exit 0 with no errors.
- `./scripts/tests/run-tests.sh all` exit 0: 61 first-party PHP files/JS syntax plus 40 security/compatibility contracts. Bundled SDK is deliberately excluded from this second-round first-party lint; it was unchanged and remains separately classified packaging debt.
- PHPCS first-party-only exit 2: 16,007 errors / 1,343 warnings in 57 files, predominantly formatting; security categories and new-order-query warnings are classified in the audit. Plugin Check 2.0.0 first-party-only exit 0/no findings; release-candidate first-party scan exit 0/no findings; SDK explicitly excluded from both.
- Candidate ZIP `/tmp/woo-alipay-round2-package-final/woo-alipay-3.2.0-round2-candidate.zip` passed integrity, isolated activation, and gateway registration. Final rebuild SHA-256: `85009231602985a37bcc806d068b92b05fb3e438c14caed33b05801ea1bd0454`.
- Migration plans for SDK, secret storage, QR CDN, remote close, and multisite uninstall are recorded in `docs/audits/woo-alipay-comprehensive-audit-2026-08-09.md`. Mini-program MD5-era outgoing flow remains a decision-gated legacy blocker.
- No production deployment, release, merge, real Alipay call/transaction, or historical-data deletion was performed.
- Second-round code commit `cddf9ca` was pushed without merge to FeiCode PR #1 via `refs/for/master/woo-alipay-audit-20260809`; `refs/pull/1/head` matched `cddf9ca3ee89c06d1fa594c926bfa3152956dd4f` immediately after the push.


## [CX] 2026-08-09 Woo Alipay third-round PR #1

- Added explicit Mini-program RSA2/legacy-MD5 policy: legacy requires site acknowledgement plus operator policy, verifies MD5 locally, binds signed partner/seller/order/trade/amount/time, and never falls back from RSA2.
- Added AES-256-GCM versioned secret envelopes, external-provider filter, key-ring rotation and fail-closed unreadable-secret behavior. Settings UI masks stored and external private material.
- Added network activation/upgrade and opt-in multisite uninstall behavior. Default uninstall retains gateway settings and all financial/order metadata.
- Added SPDX candidate SBOM and deterministic offline build with ZIP/file/SDK-tree hashes. The 8,829-file SDK provenance/license/lock gap remains a release blocker.
- Final first-party tests and PHPStan passed. Changed payment/security PHPCS security sniffs passed. Full release PHPCS, security PHPCS, and Plugin Check remain NOT PASS with classified first-party debt in `docs/audits/woo-alipay-round3-2026-08-09.md`.
- Isolated HPOS off/on, Blocks, concurrent synthetic RSA2 notification and offline refund fixtures passed. No real Alipay endpoint was contacted.
- Do not merge, deploy, publish or release this branch.

## [CX] 2026-08-11 Woo Alipay fourth-round local release candidate

- Kept candidate version 3.2.0 and aligned plugin/readme compatibility metadata: PHP 7.4, WordPress 6.6, WooCommerce 8.3, tested WC 10.2.2, HPOS and Checkout Blocks declarations. WP 6.6 + WC 8.3 was not executed as a real runtime pair and is not a PASS.
- Secret migration now validates before mutation, keeps one encrypted rollback generation, supports active/retired key rotation, rolls back by re-wrapping under the active key, and fails closed on unreadable envelopes. Default uninstall retains settings/history; explicit purge removes plugin settings, schedules and rollback envelope per site without deleting orders.
- Full first-party payment/security PHPCS categories now report 0 errors / 0 warnings. Plugin Check remains NOT PASS for classified non-security first-party debt, the external QR script and private-channel updater; the 8,829-file SDK remains separately NOT PASS for missing verified upstream archive/lock/license provenance.
- Contract tests, PHPStan, PHP 7.4 syntax, HPOS off/on, Store API Checkout Blocks, eight-way synthetic callback concurrency, offline refund replay and secret migration/rotation/rollback passed locally. No real merchant secret or Alipay request/transaction/refund was used.
- Added minimal credential-free Forgejo CI, deterministic release builder, SPDX SBOM, SDK/file manifests and a Board change template. The exact local commit and ZIP digest are written to an ignored local Board artifact after the clean-HEAD build.
- FeiCode P0 `write_policy=board_required`: do not push until a Board change approves the exact HEAD and SHA-bound CI. Do not merge, release, deploy, approve CI, contact production Alipay or send a real transaction/refund.

## [CX] 2026-08-19 Wei Alipay 4.0.0 local rename and GitHub downstream candidate

- FeiCode remains the canonical upstream. Current FeiCode base is b67e0334095e928af6ac202abff8c76f2e4a0c10; the GitHub public master was its ancestor and 29 commits behind at intake. FeiCode API reported mirror=false.
- Isolated worktree/branch: /home/parallels/Projects/wei-alipay-cx-rename, cx/wei-alipay-4.0.0. Rename/QR implementation commit: 4cbbfd1132d40f5ceb65166970205fe4e83fdc38.
- Candidate identity: Wei Alipay Payment Gateway for WooCommerce, slug/main file/text domain wei-alipay, developer Weixiaoduo.com, version 4.0.0.
- Renamed first-party package constants/classes/assets, language catalogs, Composer package, SBOM and deterministic release layout. Historical audit documents were not rewritten.
- Deliberately retained financial/runtime identities: gateway ID alipay, woocommerce_alipay_settings, _alipay_* order metadata, WC_Alipay callback routes and existing woo_alipay_* schedules/hooks. Secret envelopes also retain the original prefix, AES-GCM AAD and KDF context; WEI_ALIPAY_ENCRYPTION_KEY is preferred while the old constant remains a fail-safe for the controlled mall key-name cutover.
- Replaced the checkout-time jsDelivr QR script with vendored qrcodejs 1.0.0 plus its MIT license. npm archive integrity and local file SHA-256 are recorded in docs/sbom/wei-alipay.spdx.json.
- Added a manual Forgejo workflow and scripts/sync-github-downstream.sh. It requires a full current FeiCode master SHA plus Board change ID, checks GitHub ancestry, and performs an atomic normal push of only master and an optional matching release tag. It does not force-push, use full-ref mirror mode, delete refs or reverse-sync GitHub.
- No remote repository rename, push, PR, merge, tag, release, GitHub synchronization, mall change, production access, or Alipay transaction/refund occurred.

### Verification

- ./scripts/tests/run-tests.sh all — exit 0: 67 first-party PHP files, first-party JavaScript and rename/security/amount/replay/state/MD5/key/multisite contracts passed.
- vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=4G --no-progress — exit 0, 55 first-party files, 0 errors against the existing classified SDK baseline.
- Targeted WordPress PHPCS security sniffs on 54 changed runtime PHP files — exit 0, 0 errors / 0 warnings. Three CLI-test-only escape findings were separately classified and excluded from runtime counts.
- Isolated Docker runtime, WordPress 7.0.2 / WooCommerce 10.2.2 / PHP 8.3 — exit 0: plugin activated as version 4.0.0, gateway ID alipay registered, Blocks support class loaded, gateway remained registered with HPOS option on and off, REST index returned successfully.
- Local bare-repository mirror contract — exit 0: fast-forwarded base b67e033 to candidate c9d8480; malformed SHA was rejected with exit 1. No network GitHub write was made.
- Release-package Plugin Check with SDK directories excluded — tool exit 0 but result is NOT PASS: 1 policy error (plugin_updater_detected) and 52 warnings. The prior remote-script error was removed. Remaining warnings are 25 class-prefix, 11 hook-prefix, 5 variable-prefix, 2 function-prefix, four slow-meta-query, three debug-log, one discouraged textdomain and one readme-tag finding.
- Reproducible candidate from implementation commit 4cbbfd1 — byte-identical A/B ZIPs. ZIP SHA-256 e0e9e62c2d70f98b45d57b1a22e78a9183fabd8464abf6470ef6fcddd98679d6; 8,836,221 bytes. Manifest source SHA matched 4cbbfd1132d40f5ceb65166970205fe4e83fdc38; internal HANDOFF/audit/governance files were excluded from the package.

### Remaining gates

- Board approval is required before the exact local HEAD can be pushed to a FeiCode review branch. Repository rename, CI approval, merge, GitHub rename/deploy key, mirror run, tag/release and mall cutover are separate approvals.
- The minimum declared WordPress 6.6 / WooCommerce 8.3 / PHP 7.4 runtime combination was not rerun in this rename round.
- Plugin Check policy/debt remains classified rather than hidden. The private updater finding is incompatible with WordPress.org policy but not necessarily with the approved private distribution channel.
- The 8,829-file Alipay SDK provenance/license/lock and future package split remain release blockers.
- mall.weixiaoduo.com still requires a controlled clone acceptance and plugin-basename activation cutover. No merchant key, callback configuration or historical order was inspected in this round.

## [CX] 2026-08-19 Wei Alipay documentation and SDK provenance assessment

- Rewrote `README.md` and `readme.txt`; added `docs/DEVELOPMENT.md` and root `AI原生配置.md`. The documents now separate candidate, release and production facts; state the FeiCode-upstream/GitHub-downstream boundary; preserve financial runtime identities; document secret handling, callback order, HPOS/Blocks, multisite, test evidence and Board-gated delivery.
- `AI原生配置.md` is repository-only and is intentionally excluded from release ZIPs. `scripts/build-release-candidate.sh` enforces this so Plugin Check does not treat the AI agent contract as public plugin content.
- Added `docs/SDK-MAINTENANCE.md`. The bundled `alipay-sdk-PHP-4.20.807.ALL` maps to official `alipay/alipay-sdk-php-all` commit `d37c264fd5fd94a6291f8c682e905e6cdb193866` (2025-09-12), v2 tree `b708b3d0bdc49e7014cdf532b16d2bf2a9f2dfad`, codeload SHA-256 `66f3571eb14121254791b944826a677eb749216785ce1ee57ed3b07133b8c2ab`.
- File comparison found all 8,815 official v2 files in the bundle: 8,812 byte-identical, three locally patched, plus 14 local historical helper files. The official 2026-06-18 master is `4.20.1258.ALL`; relative to the matching current bundle commit it adds 945 and modifies 1,378 paths. Core client behavior is unchanged except the reported SDK version; 11 of 15 plugin-used Request classes only changed generated timestamps and four are identical.
- Decision: do not mix a 2,323-path wholesale SDK refresh into the 4.0.0 rename/documentation candidate. First create an independently reviewed SDK allowlist and reproducible importer, then update and run offline/sandbox regression. Official v2 and the 14 helper files still lack confirmed redistribution license evidence, so SDK license stays `NOASSERTION` and public release remains BLOCKED. SBOM now records the verified commit/archive/tree without hiding the remaining license/source gap.
- Documentation implementation commit: `f7e62b15c727a9c3e08a6259fc1dc8bbf1cec1fe`. `composer validate --strict`, four-document local-link validation, `./scripts/tests/run-tests.sh all` (67 first-party PHP/JS plus security/compatibility contracts), PHPStan (55 files, zero errors), `git diff --check`, and `./scripts/ci-local.sh` all exited 0.
- Reproducible A/B candidate from `f7e62b15c727a9c3e08a6259fc1dc8bbf1cec1fe`: both ZIPs SHA-256 `3602892a0bfb7facdc99082835e3cfe5ff0276cc7f5207b928a5d437408b3d21`, 8,842,193 bytes; manifest SDK hash `3dc27f218eef3ecfd6dd802a303e71f9bf522498ffb4d3dd242b6203fa66612d`.
- Plugin Check 2.0.0 on the release ZIP, excluding the three separately classified SDK directories, exited 0 but remains NOT PASS: 52 records = one `plugin_updater_detected` policy error and 51 warnings (25 class prefix, 11 hook prefix, five variable prefix, four slow-meta-query, three debug logging, two function prefix, one discouraged textdomain). Rewritten tags cleared the prior readme-tag warning; the repository-only AI document produced no package warning.
- No source SDK files were upgraded. No FeiCode/GitHub push, remote rename, PR update, CI approval, merge, tag, release, mall change, deployment, production Alipay connection, real payment or real refund occurred.

## [CX] 2026-08-20 mall.weixiaoduo.com read-only cutover audit

- Production was inspected read-only through the registered `weixiaoduo-prod`/`wei-wp` route. No plugin, option, database row, file, payment, refund, callback, cron or Alipay endpoint was changed or invoked. WP-CLI required `--skip-packages` because the installed global WP-CLI package autoloader exits before normal commands.
- The mall is multisite blog 11 on WordPress 7.0.1, WooCommerce 10.9.4 and PHP 8.3.25. HPOS is enabled. Checkout page 6 uses the WooCommerce `classic-shortcode` checkout block, not the native Checkout Block flow.
- Active payment code is `woo-alipay/woo-alipay.php` version 3.2.0 plus separate `woo-alipay-f2f-payments` 0.1.0 and `woo-alipay-huabei-payments` 0.1.0. The core basename appears four times in the site active-plugin option; none is network-active. Huabei is enabled, F2F is disabled. No `woo-alipay` or `wei-alipay` theme template override was found.
- The live core gateway retains ID `alipay`, option `woocommerce_alipay_settings`, callback `https://mall.weixiaoduo.com/wc-api/WC_Alipay/` and the existing `woo_alipay_*` cron hooks. The private key is still plaintext in the serialized option (length-only inspection: 1,624 bytes); the public verification key is plaintext by design. No key material was printed or copied.
- Historical HPOS sample counts are non-zero: 1,080 `alipay` orders (1,052 completed, 2 processing, 13 failed, 7 cancelled, 6 refunded), four `alipay_facetopay` orders and one `alipay_installment` order. This makes identity preservation and rollback mandatory even though there is only one known production site.
- The 4.0.0 candidate preserves gateway/settings/meta/callback/schedule identities, but its renamed plugin basename is `wei-alipay/wei-alipay.php` and it bundles the F2F/Huabei classes that the two active legacy add-ons also declare. Installing/activating it beside the three live plugins is unsafe: WordPress treats it as a separate plugin and co-activation can redeclare classes. A controlled cutover must deactivate the old core and both add-ons as one change, activate the new bundle, run the secret migration, then verify gateway registration/settings/callback/HPOS/orders before reopening checkout.
- Therefore a direct in-place “seamless” switch is NOT PASS. A maintenance-window/clone-validated cutover is feasible, but has not been rehearsed against a mall clone. The live cron events were also overdue at inspection time (last reported next-run dates 2026-08-17), so cron health is a separate production gate.

## [CX] 2026-08-20 Wei Alipay PR #1 exact-SHA CI bring-up

- PR #1 remains open and mergeable. Review head `15fad25d6425125ae34fc53b36c56d2a7d86a048` uses credential-free shell checkout of the exact `GITHUB_SHA` and dedicated PHP 7.4/8.3 runner labels; it does not contact Alipay.
- FeiCode Actions run #9 (`https://feicode.com/Weixiaoduo/woo-alipay/actions/runs/9`) tested that exact SHA. The PHP 7.4 job passed; JavaScript syntax was explicitly SKIP on its Node 12 runtime and is covered by the PHP 8.3 lane. The PHP 8.3 job passed checkout, runtime assertion, Composer, contract tests and PHPStan, then failed the local-equivalent release build because the runner image lacked `zip`. Run #9 is therefore FAIL, not PASS.
- A Board-scoped runner image update added `zip` without changing repository code. The active PHP 8.3 label now resolves to the immutable local image `feicode.com/wenpai-org/forgejo-ci-php:8.3-20260820.3`, image ID `sha256:b3ee60e7a96d8f84009ab8e0483db82ace7b6d3bd79dab817c28d31eae6c22ee`; base-image digests and superseded image IDs remain in the runner-host manifest.
- The exact-head CI gate must be rerun on the commit containing this entry. Do not merge until both PHP jobs pass for that exact SHA. Clone acceptance, production backup/cutover, GitHub synchronization, tag, release and deployment remain separate gates.

## [CX] 2026-08-20 disabled cross-border CNY admin-notice hotfix candidate

- mall.weixiaoduo.com was probed read-only on multisite blog 11: store currency is CNY and `woocommerce_acbop_settings.enabled` and `woocommerce_alipay_wap_settings.enabled` are both `no`; the retained Mini-program setting still had `protocol_mode=legacy_md5` and acknowledgement `no`. No option, plugin, order, callback, payment, refund or Alipay endpoint was changed or invoked.
- Root cause: the bundled cross-border and Mini-program gateways register on every WooCommerce gateway load, and both `requirement_checks()` methods emitted configuration notices even when their gateway was disabled. CNY is correctly unsupported by the separate cross-border product; the primary `alipay` gateway supports CNY.
- Local branch `cx/fix-disabled-cross-border-cny-notice` now returns from both requirement checks unless the corresponding gateway is enabled. Added contracts proving disabled cross-border+CNY and disabled Mini-program+legacy-MD5 emit no notice, while enabled cross-border+CNY still reports the genuine unsupported combination.
- `./scripts/tests/run-tests.sh all` exited 0 (68 first-party PHP files plus JS and contracts); `composer analyse` exited 0 with 55 files and zero errors. This is a local candidate only: no push, merge, release or production deployment has occurred.

## [CX] 2026-08-20 Wei Alipay 4.0.1 FeiCode candidate

- Isolated worktree `/home/parallels/Projects/wei-alipay-cx-401`, branch `cx/wei-alipay-4.0.1`, based on FeiCode master `fac7b02baa71f2d3ba838ee17bad601431ed46b3`. First-party implementation and documentation commit: `7b773338863097857a8c9e1b562e002d3391753b`.
- Candidate identity is version 4.0.1 with minimum PHP 7.4, WordPress 6.6 and WooCommerce 8.3; WooCommerce tested header is 10.9.4. Gateway ID `alipay`, existing option/meta/callback/schedule identities and enabled payment functions remain unchanged.
- Hardened both agreement/subscription signing paths with explicit enablement, user/order-bound nonces, local return targets, signed `app_id` and configured `seller_id` binding, accepted-state token storage, initial-charge lock/persistent idempotency and response `out_trade_no` binding. Removed browser-session identity fallback from server callbacks and added CSRF protection to agreement removal.
- Hardened F2F and Huabei refunds with WooCommerce refund-ID request keys, replay locks, persistent completion markers and exact order/trade/amount response binding. Corrected both module Checkout Blocks asset paths. Multi-currency notices now use effective checkout currency. Secret-store health notices fail closed without rendering secrets. The updater accepts packages only from HTTPS `updates.wenpai.net` or `feicode.com`, and remote Markdown links are HTTPS-sanitized.
- Removed first-party debug dumps from Huabei/profit-sharing paths and used bounded structured diagnostics. No production key, full signed payload, buyer identity or private material was logged or packaged.
- `./scripts/ci-local.sh` exit 0: 69 first-party PHP files plus JavaScript syntax, notification/amount/state/replay/MD5/secret/multisite and 39 4.0.1 release-security contracts passed; PHPStan analyzed 55 first-party files with zero errors; reproducible A/B ZIPs matched. `composer validate --strict`, `git diff --check`, ZIP integrity and PHP 7.4 lint of 69 first-party PHP files also exited 0.
- Targeted WordPress PHPCS security sniffs on 16 changed first-party runtime files exited 0 with zero findings. Plugin Check 2.0.0 on the candidate with all SDK directories separately excluded returned 32 classified records: one private-updater/WordPress.org policy error and 31 warnings (prefix compatibility, four order-meta performance warnings, one discouraged textdomain and one current-WordPress readme header warning). This is NOT PASS for WordPress.org publication, not a first-party payment-security failure.
- Isolated mall clone WordPress 7.0.2 / WooCommerce 10.9.4 / PHP 8.3.33 activated 4.0.1, registered the primary/F2F/Huabei gateways with HPOS enabled, and registered both corrected Blocks scripts with existing local files. HPOS disablement was BLOCKED because WooCommerce detected unsynchronized clone orders; the declared minimum runtime pair and live WooCommerce Subscriptions callback were not sampled in this round.
- Bundled Alipay v2 SDK remains 4.20.807.ALL and unchanged. Official 4.20.1258.ALL provides no demonstrated fix in the plugin-used core/request surface, while a wholesale update changes 2,323 paths; redistribution license remains `NOASSERTION`, so public distribution stays BLOCKED pending the independent SDK allowlist/license/sandbox change.
- No real Alipay endpoint, payment, refund or production callback was invoked. mall.weixiaoduo.com remains on 4.0.0. FeiCode push, exact-SHA CI and FeiCode Release require the final Board-bound HEAD; GitHub synchronization, production deployment and public release are separate actions.
- FeiCode PR #4 received exact HEAD `2322e87b281c69ecb9b1ee368e19d14b2cdb5a66`; Actions run #15 checked out that SHA, but both lanes failed before tests because Composer dependency download returned nonzero after 20m5s on PHP 7.4 and 5m49s on PHP 8.3. Test samples were zero, so run #15 is FAIL. The workflow now uses three bounded Composer retries; the replacement exact SHA still requires a fresh Board binding and both remote lanes.

## 2026-08-20 - [CX] Wei Alipay 后台菜单路由与信息架构修复候选

- 根因是 7 个后台页面把 `add_submenu_page()` 的 parent 和 page slug 分别错误地统一为 `woocommerce` / `wei-alipay`，造成 WooCommerce 菜单污染、重复标题和回调路由碰撞；截图中的多个入口实际都指向 `admin.php?page=wei-alipay`。
- 本地隔离分支 `cx/wei-alipay-admin-menu-401` 将插件改为独立顶级 `Wei Alipay` 菜单，只保留一个概览入口；分账、分账任务、分账补偿、对账、Webhook、订阅分别使用唯一 slug，并且仅在对应页面真实注册时显示概览卡片。WooCommerce 子菜单不再注册 Wei Alipay 工具项。
- 菜单源文案统一为英文 msgid，并补齐本轮新增菜单/概览文案的 zh_CN PO/MO；产品名 `Wei Alipay` 不再被旧翻译错误显示成“支付宝付款”。缺少 WooCommerce Subscriptions 时的提示也改为英文源文案和完整中文翻译。
- 新增 `tests/admin-menu-contracts.php`，覆盖独立顶级菜单、唯一工具路由和不污染 WooCommerce 子菜单；`./scripts/ci-local.sh` 退出 0，70 个第一方 PHP/JS 文件语法检查通过，PHPStan 55 个第一方文件 0 错误，A/B 候选包字节一致。
- Mac WordPress Studio（WordPress 7.0.4、WooCommerce 11.0.1、PHP 8.4）安装候选后运行时菜单样本：WooCommerce 下 Wei Alipay 条目 0 个；独立菜单按当前可用模块显示“概览、分账、分账任务、分账补偿、对账、Webhook 日志”，各自 slug 唯一。未连接支付宝、未发送支付/退款，未修改生产、未推送 FeiCode、未合并、未发布。
