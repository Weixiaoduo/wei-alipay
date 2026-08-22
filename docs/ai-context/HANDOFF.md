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

## 2026-08-20 - [CX] Wei Alipay explicit feature centre and WooPayments design reference

- WenPai canonical isolated worktree `/home/parallels/Projects/wei-alipay-cx-401`, branch `cx/wei-alipay-admin-menu-401`, adds an independent `Wei Alipay -> Features` screen. The primary `alipay` gateway remains registered; F2F, Huabei, barcode, fund authorization, Mini Program, cross-border, profit sharing, reconciliation, webhook retry and subscriptions now load only from the explicit `wei_alipay_features` allow-list. First migration preserves explicitly enabled existing gateways/configured tools; disabling preserves settings, order metadata and history and only clears module-owned schedules.
- Removed runtime loading of the legacy bulk-enable helper. Feature saving requires `manage_woocommerce`, a nonce and a registry allow-list. WooCommerce Subscriptions is dependency-gated. The page uses two-step status: feature registration first, then the existing WooCommerce gateway configuration/enablement.
- Extracted a deterministic admin token inventory from local WooPayments 11.0.0 `dist/settings.css`, `dist/index.css`, `dist/wcpay-overview.css` and `dist/plugins-page.css`; the four hashes, complete raw inventory, adopted semantic tokens and source-map interaction evidence are in `docs/design/`. WooCommerce core, not WooPayments, owns the draggable Payments list shell; no compiled WooPayments code or private component namespace was copied.
- Exact local implementation commit before this HANDOFF entry: `ace0f2be7e4891029720b9356f02f320f16b88d4`. `./scripts/ci-local.sh` exited 0: 72 first-party PHP/JavaScript syntax samples and contracts passed, PHPStan analyzed 56 first-party files with zero errors, and A/B release ZIPs were byte-identical. Candidate SHA-256 `c73806a8b9566e6bcc7c88b2fa116fb3d8bc4a9ba6c9c79eaa3d30d5aa8359c1`, 8,866,604 bytes.
- Targeted WordPress PHPCS security sniffs on `inc/class-wei-alipay-features.php`, `inc/class-wei-alipay.php` and `wei-alipay.php` exited 0. Release-package Plugin Check 2.0.0, with SDK directories separately excluded, exited 0 but is NOT PASS: 50 classified records, two policy/metadata errors and 48 warnings. The two errors are the intentional private updater policy and `Tested up to: 7.0 < 7.1`; the new feature class has zero Plugin Check records. Existing warnings are prefix compatibility, slow order-meta queries and discouraged textdomain loading.
- Mac WordPress Studio runtime used WordPress 7.0.4, WooCommerce 11.0.1 and PHP 8.4.7. With all optional features off, exactly 10 toggles rendered, Subscriptions was disabled for its missing dependency, the Save button started disabled, and WooCommerce registered only `alipay`. Enabling F2F through the form enabled Save, persisted through the nonce-protected POST and registered `alipay_facetopay`; the test option was then reset to all-off. After syncing zero unmigrated orders, HPOS off and on both registered `alipay` and loaded `WC_Alipay_Blocks_Support`, then HPOS was restored off. Browser console samples contained two WordPress/WooCommerce deprecation warnings and zero Wei Alipay errors. A WordPress 7.1 Studio upgrade was attempted twice but BLOCKED before installation by TLS `cURL error 35`; therefore 7.1 runtime is not claimed.
- No Alipay endpoint, real payment/refund, mall production data, FeiCode/GitHub push, PR update, merge, release or deployment occurred. FeiCode write remains Board-gated.

## 2026-08-20 - [CX] WooPayments and WooCommerce administration design evidence v2

- Replaced the four-file token sample with a deterministic inventory of the installed WordPress Studio packages: WooPayments 11.0.0 and WooCommerce 11.0.1. Scope is 30/45 WooPayments administration CSS/JS artifacts and 27/152 WooCommerce administration CSS/JS artifacts; every selected path, byte size and SHA-256 is recorded in `docs/design/woopayments-admin-tokens.json`.
- `DESIGN.md` now keeps WordPress/WooCommerce native controls, settings APIs, routing and accessibility as the foundation while adopting WooPayments hierarchy, density, surfaces, statuses and async feedback through independent `--wei-*` tokens. It specifies semantic color, typography, spacing, shape, elevation, layout, motion, component hierarchy and save/query/retry/filter/confirmation/copy state machines.
- `scripts/extract-woopayments-admin-design.py` regenerates the v2 evidence without copying WooPayments CSS, JavaScript, React components or private selectors. Raw frequency is treated as evidence rather than an automatic design decision; frontend checkout/cart/express and RTL/minified duplicates are outside the extraction scope.
- Implementation/evidence commit before this HANDOFF entry: `ed2d0972dd5f741316eb6047d73b01ea100f9335`. `./scripts/tests/run-tests.sh all`, PHPStan debug/non-parallel over 56 first-party files, Python byte-compilation, evidence regeneration comparison and `git diff --check` exited 0. This round analyzed and extracted the design system; it did not claim a new page-level visual PASS.
- No FeiCode/GitHub push, PR update, merge, release, deployment, mall change, Alipay endpoint, real payment or refund occurred.

## 2026-08-20 - [CX] WooCommerce settings header-tab alignment

- Compared the live WordPress Studio WooCommerce 11.0.1 settings header at `admin.php?page=wc-settings` with the Wei Alipay profit-sharing screen. Replaced boxed WordPress tabs with the scoped WooCommerce settings composition: transparent 13px medium links, 24px gap, quiet divider and 2px admin-theme active underline; at 500px labels become 16px and remain horizontally scrollable.
- Native links, URL-addressable state and the navigation label remain intact. Unlike WooCommerce core CSS, Wei Alipay retains a visible `:focus-visible` ring.
- Implementation commit before this HANDOFF entry: `9a9074051fd05eeb5a7e54d98bf2db0192a462de`. Contract tests, 56-file PHPStan debug run and `git diff --check` exited 0. WordPress Studio samples at 1280x720 and 480x900 showed the new underline tabs without clipping; browser console contained zero errors and zero warnings.
- No FeiCode/GitHub push, merge, release, deployment, mall change or Alipay request occurred.

## 2026-08-20 - [CX] Feature-centre unavailable-state detail repair

- Reproduced the screenshot defect with WooCommerce Subscriptions absent and the retained subscriptions preference previously enabled. The hidden native checkbox was made visible by WordPress disabled-control CSS, the custom switch still appeared on, the icon/toggle were vertically centred against multi-line copy, a Configure link was shown for an unavailable page, and a duplicate global dependency notice occupied the page header.
- The feature centre now separates stored preference from effective availability: unavailable features render one disabled off-state switch, no Configure link, an inline warning linked with `aria-describedby`, top-aligned icon/control, and a muted unavailable row. The checkbox is visually clipped with an opacity override while remaining in the accessibility tree. The subscriptions bootstrap no longer emits the duplicate global notice.
- Implementation commit before this HANDOFF entry: `3d9365af6e2ea2628ac43341bbbd45b04aeec43f`. Contract tests, 56-file PHPStan debug run, targeted WordPress security PHPCS on both changed runtime PHP files and `git diff --check` exited 0.
- WordPress Studio runtime samples at 1280x900 and 480x900: 11 feature rows including the required gateway, one unavailable row, one disabled switch, zero disabled-checked switches, zero unavailable Configure links, no horizontal overflow, one page H1, and zero browser console errors/warnings. The only duplicate DOM ID was WordPress core footer `footer-thankyou`, not plugin markup.
- No FeiCode/GitHub push, merge, release, deployment, mall change or Alipay request occurred.

## 2026-08-20 - [CX] Wei Alipay administration detail and tab-system refinement

- Canonical isolated branch `/home/parallels/Projects/wei-alipay-cx-401` implementation commit `abc02ae17a57a42ad29dc99e157c083c061bd137` now uses one native-first page header and URL-addressable navigation pattern across the operational screens. Profit sharing settings/query/receivers/rules/jobs/compensation are one six-tab family; reconciliation separates schedule/retention and manual provider-contacting work into two views; the WooCommerce gateway page uses the native WooCommerce-generated section IDs for seven keyboard-focusable jump tabs.
- Refined card/form density, mobile form stacking, filter/export toolbar, queue danger zone, empty states, list-table mobile bulk action visibility and status labels through the existing design tokens. Removed the duplicate cleanup nonce IDs, retained server capability/nonce checks, and did not change payment, refund, callback or order-state behavior. Chinese source presentation now consistently uses `Wei Alipay`, `分账` and translated control/status labels rather than mixed `支付宝分润`/English labels.
- WordPress Studio runtime on WordPress 7.0.4, WooCommerce 11.0.1 and PHP 8.4 sampled 10 routes at 1280x900 and the same 10 routes at 480x900: profit sharing's four configuration views plus jobs/compensation, reconciliation schedule/manual, Webhook logs and the core gateway settings. All 20 route/viewport samples had no page-level horizontal overflow, tabbed screens had exactly one current tab, and there were zero plugin duplicate DOM IDs; console output contained only the WordPress jQuery Migrate log. One gateway anchor interaction moved the URL to `#woocommerce_alipay_advanced_settings_title`, marked Advanced current and focused that native heading. Webhook bulk retry remained visible at 480px. WooCommerce Subscriptions was absent, so its dedicated settings page is SKIP rather than PASS.
- `./scripts/tests/run-tests.sh all` passed 74 first-party PHP/JavaScript syntax samples plus the administration, notification, amount, state, replay, MD5, secret, refund, Blocks and multisite contracts. Targeted WordPress security PHPCS over 12 changed runtime PHP files exited 0. PHPStan debug mode analyzed 57 first-party files with zero errors. The aggregate `./scripts/ci-local.sh` itself exited 1 because its parallel PHPStan worker reported an incomplete-worker infrastructure error after the tests passed; the deterministic debug rerun is the completed static-analysis result.
- Reproducible release builds A/B were byte-identical: `wei-alipay-4.0.1-candidate.zip` SHA-256 `f91492d620fa18e90c418eab6f5a28833b4e24f2b7abd1206f2cc2fbfbdd56bb`, 8,946,460 bytes. Plugin Check was not rerun in this detail round because the Studio WP-CLI installation has no `plugin check` subcommand; the earlier classified package result is not promoted to a fresh PASS.
- No FeiCode/GitHub push, PR update, merge, tag, release, deployment, mall production change, Alipay endpoint, real payment or refund occurred.

## 2026-08-20 - [CX] Alipay product admission and fail-closed feature policy

- [CX] Researched current public Alipay admission boundaries for recurring debit, face-to-face/barcode, Huabei installments, fund authorization, Mini Program, cross-border and profit sharing. The public evidence does not support a universal RMB 10 million capital rule for automatic subscriptions; current merchant-withholding upgrade material describes a different, product-specific threshold, so no capital number is hard-coded.
- [CX] Added `docs/audits/alipay-feature-admission-and-entitlement-2026-08-20.md`. It separates Alipay product approval from future Wei Alipay entitlement and records the WordPress.org trialware review boundary.
- [CX] Feature policy schema advanced to version 2. `f2f`, `huabei`, `barcode`, `fund_auth`, `mini_program`, `cross_border`, `profitsharing` and `subscriptions` are fail-closed in migration, save handling, UI and runtime until a reviewed entitlement path exists. `reconcile` and `webhook_retry` remain free optional operational tools; the main gateway remains available.
- [CX] Restricted feature cards expose Alipay requirement links and a Weixiaoduo.com application link, but no authorization Key service was implemented and no merchant private key is sent off-site.
- [CX] Verification: `php -l inc/class-wei-alipay-features.php` exit 0; `php tests/feature-center-contracts.php` exit 0 (44 assertions); `./scripts/ci-local.sh` exit 0, including 74 first-party PHP/JS syntax samples, security/compatibility contracts, PHPStan 57/57 and two reproducible candidate builds. Browser/WordPress visual sample count for this policy change: 0, not PASS.
- [CX] No real Alipay request, production payment/refund, deployment, release, merge or push was performed. Changes remain uncommitted on `cx/wei-alipay-admin-menu-401` for review.

## 2026-08-20 - [CX] Weixiaoduo account linking, entitlement and usage-visibility design

- [CX] Reviewed the current Weixiaoduo.com registration/license/update surfaces, `wei-account` read-model/API contract and the existing Kestrel API Manager activation model. `wei-account` already models product-level licenses, activation domains, versions and seats; the mall engine remains the authorization source and `wei-account` must not become a second writer.
- [CX] Added `docs/plans/wei-alipay-account-linking-entitlements-2026-08-20.md`: free plugin + free account binding + paid recurring/profit-sharing service scopes, explicit service-data disclosure, minimal site/version/feature telemetry, no order/customer/payment-key collection, PKCE connection flow, site-scoped installation tokens, signed offline entitlements, continuity rules and phased rollout.
- [CX] Updated `DESIGN.md` so the feature centre is split into native URL-addressable Overview/Payment methods/Operations/Account & authorization views. It defines fixed Chinese-ready authorization states, one contextual action per row, required-data disclosure and a separate opt-in for optional diagnostics.
- [CX] The existing account-level Master API Key is not accepted as the target Wei Alipay credential because it spans multiple products and the legacy client sends it in a query parameter. The target plugin must never receive or transmit Alipay private keys.
- [CX] This round changes documentation only. No entitlement endpoint, telemetry request, account connection, production write, Alipay request, push, merge, release or deployment was performed. The shared `wei-account` checkout had unrelated uncommitted WIP and was read only.

## [CX] 2026-08-21 账户页渐进式披露与后台一致性收口

- 工作区：`/home/parallels/Projects/wei-alipay-cx-401`，分支 `cx/wei-alipay-admin-menu-401`；进入本轮时 HEAD 为 `dc7950e7aea7845b6a6d85199e55fc011167f877`，已有未提交 WIP 已保留并在原隔离 worktree 上适配。FeiCode 为 P0/board_required，本轮未 commit、push、merge、release、deploy。
- 根页从单个长表单拆为 URL 可寻址的 `overview`、`payments`、`operations`、`account` 四个原生页签；各页浏览器标题与当前任务一致。支付方式与运营工具分别保存，保存一个分组不会清空另一个分组。
- 账户页采用“连接结论 → 免费/商业权益摘要 → 必需数据披露 → 连接详情 → 已连接时才出现的危险操作”顺序。数据说明和诊断使用原生 `details/summary` 且默认关闭；未实现经过评审的 Wei Account connector 前，连接按钮明确禁用，不构造不安全的伪外联。
- 功能 registry 已增加 `tier`、`required_scope`、`provider_requirement`；受限功能仍由 runtime/save/migration 三处 fail-closed。功能行使用“连接后免费 / 需要商业授权 / 需要支付宝审核 / 依赖缺失”等固定状态，完整要求进入 disclosure，取消每行堆叠长说明。
- 产品边界更新：FeiCode 自有分发、不计划提交 WordPress.org；外联、必要使用遥测和站点授权属于既定产品能力，但支付/订单/客户/私钥/证书/通知原文明确排除，实际 PKCE/token/entitlement 后端仍属后续受审实现。
- 中文目录重新从当前 first-party 源生成并清理：`languages/wei-alipay-zh_CN.po` untranslated=0、fuzzy=0；MO 经 `msgfmt --check --check-format` 编译。新增 `tests/translation-contracts.php`，并加入 contracts 自动门。
- 定向安全 PHPCS：使用 `/home/parallels/Projects/wpcomments-codex-audit/vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.Security.EscapeOutput inc/class-wei-alipay-features.php`，1 个文件，exit 0。全 WordPress 标准仍有既有格式/文档债，不用自动格式化掩盖。
- VM 验证：
  - `./scripts/tests/run-tests.sh static`：75 个 first-party PHP 文件及 first-party JS，exit 0。
  - `./scripts/tests/run-tests.sh contracts`：全部契约样本通过（含 10 个 runtime gate、4 个 URL view、账户 disclosure、翻译、通知绑定/重放/金额/MD5/密钥/退款/Blocks/multisite/uninstall），exit 0。

  - `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=4G --no-progress`：0 errors，exit 0。
  - `msgfmt --check --check-format`、`git diff --check`：exit 0。
- Mac WP Studio 验证（非生产）：WordPress 7.1、WooCommerce 11.0.1、PHP 8.4.7、Wei Alipay 4.0.1。
  - CLI render smoke：overview/payments/operations/account 各 1 样本，均 exit 0；账户页 2 个 disclosure、0 个功能表单；支付/运营页各 1 个独立表单。
  - 浏览器运行：overview、payments、operations、account、reconcile、webhooks、WooCommerce Alipay settings 共 7 路由均完成加载；插件页 PHP/JS fatal 样本 0，插件 notice-error 样本 0。WooCommerce 设置页看到 1 个 WordPress heartbeat “Connection lost” 通知，归类为测试浏览器连接提示，不是插件 PHP 错误。
  - 视觉样本：1440px 账户/概览/支付方式 3 张，600px 账户/支付方式 2 张；页签无挤压、账户详情默认折叠、禁用连接原因可见、移动布局未横向溢出。
  - 为浏览器验收创建的临时本地管理员已删除；生产和 mall.weixiaoduo.com 未触碰。
- 尚未完成：Wei Account PKCE 连接、installation token 加密、签名 entitlement、本地验签/离线宽限、遥测调度、解绑/导出/删除 API 均未实现；这些需要 wei-account 与 mall entitlement 真源契约和单独 Board change，当前 UI 不把它们伪装成可用。

## [CX] 2026-08-21 Cravatar 账户身份与概览运行信息

- 工作区仍为 WenPai VM `/home/parallels/Projects/wei-alipay-cx-401`、分支 `cx/wei-alipay-admin-menu-401`、HEAD `dc7950e7aea7845b6a6d85199e55fc011167f877`，在既有未提交 WIP 上继续适配；本轮未 commit、push、merge、release、deploy，未访问商城生产或支付宝。
- `wei_alipay_account_state` 展示契约新增 `avatar_url`/`avatar_alt`。仅账户状态为 connected 且 URL 为 HTTPS、主机位于 Cravatar 明确 allow-list 时渲染 40×40 头像；使用 `referrerpolicy=no-referrer`。支付插件不接收邮箱来计算头像 hash，不发起账户查询；不合法 URL 回退 WordPress 用户 Dashicon。
- 概览页增加两组事实模块：收款就绪度读取主网关启用、三项商户配置 presence、币种和 HTTPS；运行状态读取 HPOS 模式、Blocks 集成类、待处理订单检查和支付超时计划。`Needs attention` 只从这些本地事实生成动作；本地全绿文案明确不等同支付宝沙箱验收。页面渲染不查询订单/Webhook 表，也不请求支付宝或账户服务。
- 翻译目录从当前 first-party 源重新生成：POT 907 个 msgid，zh_CN untranslated=0、fuzzy=0；MO 通过 `msgfmt --check --check-format`。
- WenPai VM 验证：`./scripts/tests/run-tests.sh all` exit 0（75 个 first-party PHP 文件及 first-party JS，加全部现有合同）；定向 `WordPress.Security.EscapeOutput` PHPCS 1 个文件 exit 0；PHPStan first-party 全量 exit 0、0 errors；`git diff --check` exit 0。
- Mac Studio 仅同步当前改动用于验证：WordPress 7.1、WooCommerce 11.0.1、PHP 8.4.7、CNY、插件 4.0.1 active。概览和账户页各 1 个 1440×1100 中文浏览器样本，控制台 errors=0；connected Cravatar 样本 natural size 80×80、`referrerPolicy=no-referrer`，非 allow-list 头像样本回退图标；600px 两页各 1 个样本，`scrollWidth=viewport=600`。临时 account-state fixture 验证后已删除，未形成伪连接状态。

## [CX] 2026-08-21 Weixiaoduo.com 账户动作命名

- 已将 connected 账户主动作从内部名称 `Manage in Wei Account` 改为面向用户的 `Manage on Weixiaoduo.com`，zh_CN 显示“在 Weixiaoduo.com 中管理”；目标 URL 仍由受审 account-state adapter 提供，没有新增硬编码授权请求。
- `DESIGN.md`、POT、zh_CN PO/MO 和翻译合同同步更新。`./scripts/tests/run-tests.sh all`、定向 EscapeOutput PHPCS、`msgfmt --check --check-format`、`git diff --check` 均 exit 0；Mac Studio connected 账户页 1 个浏览器样本找到该文案，控制台 errors=0，临时 fixture 已删除。
- 仍处于未提交的 WenPai 隔离 WIP；未 push、merge、release、deploy，未触碰商城生产或支付宝。

## [CX] 2026-08-21 概览健康、授权与本地活动模块

- 在既有 Overview 中补齐五组渐进式信息：收款就绪度、账户授权、支付可靠性、最近 7 天、兼容与维护；`Needs attention` 最多显示 3 项。更新状态只读取 WordPress 本地更新缓存，页面不扫描订单/Webhook 表，也不为了渲染而请求 Weixiaoduo.com 或支付宝。
- 新增 `Wei_Alipay_Health` 本地有界快照：观察已处理回调、待处理订单检查、超时检查、Webhook 重试、支付完成、失败订单和退款创建。仅保留 8 天的时间戳、计数、清理后的错误码与 HMAC 事件哈希；活动去重键最多 500 个，失败回调哈希最多 100 个，不存订单号、交易号、金额、客户、通知原文或密钥。
- 私钥就绪度通过 `Wei_Alipay_Secret_Store::main_secret_health()` 返回 ready/missing/unreadable 与 protection 类别；不返回密钥值。账户展示契约增加授权 scope、到期、离线宽限和最近同步字段，真实连接/entitlement 后端仍未伪造为可用。
- 文案目录已从 955 个当前 first-party msgid 生成，zh_CN untranslated=0、fuzzy=0；`Payment reliability` 的旧误译已改为“支付可靠性”。`DESIGN.md` 已记录本地快照的隐私、保留与零样本边界。
- WenPai VM：`./scripts/tests/run-tests.sh all` exit 0，77 个 first-party PHP 文件和 first-party JavaScript 语法通过，功能/健康/设计/翻译/支付安全/多站点合同全部通过；PHPStan first-party 全量 0 errors、exit 0；定向 WordPress Security PHPCS 5 个改动运行文件 exit 0；`git diff --check` exit 0。
- Mac Studio：WordPress 7.1、WooCommerce 11.0.1、PHP CLI 8.4.7（Web PHP 8.4.23）、Wei Alipay 4.0.1 active。运行时健康事件样本为支付成功 1、支付失败 1、退款 1、重复支付事件 1 次去重、失败回调 1、后台任务标记 3，摘要成功率 50%，原始测试 merchant id 留存 0；测试订单、退款与快照均在验收后删除/恢复，未发送支付、退款或支付宝请求。
- 浏览器：概览 1440px 与 600px 各 1 个样本，600px `scrollWidth=viewport=600`，控制台 errors=0；connected 账户页 1440px 1 个样本确认 Cravatar、`在 Weixiaoduo.com 中管理`、3 个原生 disclosure 与无横向溢出，临时 local-only account/login fixture 已删除。
- 工作区仍为 `/home/parallels/Projects/wei-alipay-cx-401`、分支 `cx/wei-alipay-admin-menu-401`、HEAD `dc7950e7aea7845b6a6d85199e55fc011167f877`，所有内容仍是未提交隔离 WIP；未 commit、push、merge、release、deploy，未触碰 `mall.weixiaoduo.com`。

## [CX] 2026-08-21 连接感知标签与增值服务目录

- 已先固化跨插件设计要求：Mac 协调文档 `docs/plans/weixiaoduo-connected-plugin-admin-standard-2026-08-21.md` 记录账户连接型插件的信息架构、标签状态机、功能元数据、授权判定、原生组件、WooPayments token 使用、交互、头像、外联/遥测/隐私、支付连续性、多站点和验收矩阵；Wei Alipay 自身的 `DESIGN.md` 与账户授权方案同步记录产品映射。
- 未连接时只显示概览、支付方式、运营工具、账户与授权四个标签；connected account-state 才在运营工具后增加`增值服务`。直接请求 `view=services` 且未连接时在服务端规范化到账户视图，不依赖 CSS 隐藏。
- `commercial` 与 `specialist` 功能从普通支付/运营表单移入增值服务目录：资金授权、跨境支付、分账、订阅共 4 项。免费单商户支付扩展仍在支付方式，本地对账/Webhook 仍在运营工具。
- 连接只控制目录可见性，不作为 entitlement 或支付宝准入证明。当前增值服务每项只显示状态、原生 requirements disclosure 和一个 Weixiaoduo.com 申请动作，启用开关为 0；运行时/save/migration 的 restricted fail-closed 边界未放松。服务行不使用会连带禁用内部链接的 `aria-disabled`。
- 翻译目录更新为 962 个 current first-party msgid，zh_CN untranslated=0、fuzzy=0；浏览器标题、标签、审批说明和申请动作均有完整中文。
- WenPai VM 最终验证：`./scripts/tests/run-tests.sh all` exit 0（77 个 first-party PHP 文件及 first-party JavaScript，加全部合同）；PHPStan first-party 全量 0 errors；定向 WordPress Security PHPCS 1 个改动运行文件 exit 0；`msgfmt --check --check-format` 与 `git diff --check` exit 0。
- Mac Studio：WordPress 7.1、WooCommerce 11.0.1、PHP 8.4、插件 4.0.1。未连接样本 1 个：增值服务标签 0、直接服务 URL 归一到账户页、商业/专项功能泄漏 0；connected 样本 1 个：5 个标签、4 个服务、0 个开关、4 个申请动作。浏览器 1440/600/400px 共 3 个服务页样本，横向溢出 0、当前标签 1、console errors=0；临时 local-only fixture 已删除。
- 本轮仍停留在未提交隔离 WIP，HEAD 未变为 `dc7950e7aea7845b6a6d85199e55fc011167f877`；未 push、merge、release、deploy，未连接支付宝，未修改 mall 生产。

## [CX] 2026-08-21 Wei Alipay 标题身份与折叠细节收口

- 独立后台页标题现在使用本地 `assets/images/wei-alipay-placeholder.svg` 作为 32×32 临时插件标识；该 SVG 无远程图片引用并进入候选包。连接后的 Cravatar 账户头像保留为独立的 28×28 圆形身份，显示在连接状态之后，不再与插件 Logo 混用。
- Overview 的四个原生 `details/summary` 折叠项补充打开态、悬停态、键盘焦点和 reduced-motion 处理；展开内容的状态行内部使用虚线分隔，折叠项之间及展开区边界仍使用实线，避免层级混乱。
- Mac Studio WordPress 7.1 / WooCommerce 11.0.1 的 1200px、400px 浏览器样本均无横向溢出，控制台 errors=0；连接头像 fixture 在验证后已删除。
- `./scripts/ci-local.sh` exit 0：77 个第一方 PHP/JS 语法及全部合同通过，PHPStan 58/58 为 0 errors，A/B 候选包字节一致。本条记录时未合并、发布、部署或连接支付宝。

## 2026-08-21 - [CX] Notification ownership and placement cleanup

- In the isolated WenPai workspace `/home/parallels/Projects/wei-alipay-cx-401` on branch `cx/wei-alipay-admin-menu-401`, system, plugin-page and gateway-settings notifications now have separate owners. WordPress/WooCommerce system notices remain in the native global area; Wei Alipay page feedback uses one region immediately after its header/tabs; gateway health and requirement notices render inside the relevant gateway settings screen before tools/fields.
- Removed global `admin_notices` registration from the secret store, exchange-rate validation, cross-border, Mini Program, fund authorization and the bundled subscriptions dependency fallback. Disabled or unrelated gateways no longer place their warnings on other administration pages. Native `notice notice-* inline` markup, roles and dismissal behavior remain WordPress-owned; CSS only controls region spacing.
- Updated `DESIGN.md` and `css/admin/DESIGN.md` with the three-level notification contract. Added static contracts for placement, global-hook absence and native notice markup. Existing copy-edit WIP contracts for the seven-day privacy sentence and current zh_CN translations were aligned without changing runtime behavior.
- Local checks: `./scripts/ci-local.sh` exit 0 (78 first-party PHP/JavaScript syntax samples and contracts, PHPStan 58 files/0 errors, reproducible ZIP); targeted WordPress security PHPCS on eight changed PHP files exit 0; security contracts 63/63 and cross-border availability contracts 3/3 passed. A transient parallel PHPStan worker failure was rerun with `--debug`, 58/58 files and zero errors.
- WordPress Studio runtime (`http://localhost:8884`, WordPress 7.1, WooCommerce 11.0.1) sampled two pages at 1440px and 400px (4 page/viewport samples): gateway settings had exactly one settings-notice region with two native notices, no secret warning in the global notice area and no horizontal overflow; the Wei overview had exactly one page-notice region directly after tabs, one native success notice, one h1 and no horizontal overflow. Overview console errors were 0. Gateway settings still has one unrelated WooCommerce PayPal Payments React #299 console error.
- Local Studio received the reproducible candidate only for acceptance; SHA-256 `60803630f772073d41382e0011da623b9c5ecb0bbad6765225a0dc409c8f1c67`. Repository HEAD remains `7c0bed4` with preserved shared WIP; this notification change is uncommitted. No FeiCode/GitHub push, merge, release, production deployment, Alipay request, payment or refund occurred.

## 2026-08-22 - [CX] Compact identity header and Weixiaoduo account-connection boundary

- Removed the detached `Main gateway settings` button from the Wei Alipay identity header. The header now contains only the local plugin logo, one H1, connection state, a connected-account avatar when available, and one bounded description. The gateway action remains beside payment-readiness evidence; header vertical padding is 16px through existing `--wei-*` tokens.
- Added `docs/ADMIN-UI-ENGINEERING.md` and linked it from `docs/DEVELOPMENT.md` and `AI原生配置.md`. The handbook fixes page skeleton order, native-component ownership, notice ownership, overflow/localization rules, 1280/960/782/600/400px samples, notification-pressure states, and the rule that a local problem must not be patched with a global `admin_notices` hook or global core selector.
- Clarified the account architecture in `docs/plans/wei-alipay-account-linking-entitlements-2026-08-20.md`: SSO is the browser login experience on Weixiaoduo-owned domains; Wei Account owns consent, site linking and presentation of site-scoped installation credentials/entitlements; Wei Payments remains the mall checkout/order/renewal/payment-event layer and may update the entitlement source through a controlled adapter, but is not the authentication server.
- The external WordPress-plugin connection target is OAuth Authorization Code + PKCE S256, one-time state/code, exact HTTPS callback and a site/product-scoped installation credential. The current Wei Account checkout only proves native WordPress cookie login and cookie+`X-WP-Nonce` REST sessions; no OAuth/OIDC authorization endpoint, token endpoint, discovery/JWKS, installation-token endpoint or reviewed connector exists. Wei Alipay therefore remains presentation-only and fail-closed; no SSO cookie, account Master API Key, WooCommerce order key or Wei Payments return parameter is treated as plugin authorization.
- Verification: PHP lint 3/3 files exit 0; feature/design contract scripts exit 0; targeted security PHPCS over the changed runtime file exit 0; PHPStan 58/58 first-party files, 0 errors, exit 0; `git diff --check` exit 0; `./scripts/ci-local.sh` exit 0 with 78 first-party PHP/JavaScript syntax samples and reproducible A/B build. Candidate ZIP is 9,035,213 bytes, SHA-256 `f0008938fbc1923f8337c117bbda823c392acb6eba791828853c9c61aa6a3fd5`.
- Local Studio runtime: WordPress 7.1, WooCommerce 11.0.1, PHP 8.4, Wei Alipay 4.0.1 active. Overview samples at 1440/960/782/600/400px (5 samples) had one H1, zero header buttons, zero horizontal overflow and aligned heading/tabs/content at 960px and below. Header height was 106 CSS px in all five samples; console errors were 0. The only main-gateway actions were contextual links outside the header.
- Repository HEAD remains `7c0bed4c818c0ceae54eb7c73c26b95b238c2406` with preserved uncommitted shared WIP. No commit, push, merge, release, deployment, mall production write, Weixiaoduo account request, Alipay request, payment or refund occurred.

## 2026-08-22 - [CX] Identity header surface correction

- User clarified that the requested header change was removal of the white header background. The feature-centre identity header now uses a transparent background with no border or divider; its logo, title, state, avatar and description remain on the normal WordPress admin canvas.
- Updated the mirrored design contract and added a static assertion for the transparent/borderless header. `./scripts/ci-local.sh` exit 0; the reproducible candidate is 9,035,284 bytes, SHA-256 `5b2176b4f4f45e9f72b59152c5db0b20a408505b74b288f1fc8ba62e19a60a7c`.
- WordPress Studio 7.1 / WooCommerce 11.0.1 runtime sample at 1440px computed `rgba(0, 0, 0, 0)`, 0px top/bottom borders, 0 header buttons, 0 horizontal overflow, one H1 and zero console errors. No commit, push, release or deployment occurred.

## 2026-08-22 - [CX] Production cutover incident review, signing contract and dedicated SOP

- Added `docs/audits/wei-alipay-production-cutover-incident-2026-08-21.md`. The review separates two events: the user-reported `missing-signature` after the 4.0.0 cutover has a confirmed process failure but no unique code-level root cause; the later empty/invalid GET callback changing paid order 42792 from `processing` to `failed` has a confirmed old-callback root cause.
- Git evidence shows old baseline `d135d67` and 4.0.0 candidate `6698292` have the same `lib/alipay` tree `360df6e71c509eeeb872cdaf1f1855dd6b422c1f`. The cutover changed plugin entry/loading, secret storage/decryption and first-party payment paths, but did not update that SDK tree. Historical SDK updates remain separate changes.
- Added `docs/governance/payment-gateway-production-cutover-sop.md` and linked it from `docs/DEVELOPMENT.md`, `AI原生配置.md` and `docs/SDK-MAINTENANCE.md`. The SOP requires separate gates for SDK provenance, key format, non-secret public-key fingerprint, offline sign/verify, final-form required fields, isolated WordPress, Alipay sandbox, separately approved production canary, reconciliation, observation and rollback. `decryptable` is not treated as `sign-ready`.
- Added `tests/sdk-signing-contracts.php` to `scripts/tests/run-tests.sh`. It generates a process-local ephemeral PKCS#1 key, builds a page-pay form through the bundled SDK, asserts 10 required fields including non-empty `sign`, verifies RSA2 with the paired public key, proves the preflight rejects a removed signature, and checks that the first-party sanitizer retains signed hidden inputs. It reads no merchant credential and makes no network request.
- Verification: targeted signing contract 18/18 assertions, exit 0; `./scripts/tests/run-tests.sh contracts` 413 contract assertions plus 2 runner assertions, exit 0; `./scripts/ci-local.sh` exit 0 with 79 first-party PHP syntax samples, first-party JavaScript syntax, PHPStan 58/58 files and 0 errors, and byte-identical A/B candidate ZIPs. Final verified candidate SHA-256 was `199c00618b981eba149ed867b5703dee8bba66f975f69f04f274b990a1df680e`; A/B ZIP comparison exit 0 and no release artifact was published.
- Canonical branch remains `cx/wei-alipay-admin-menu-401`, HEAD `7c0bed4c818c0ceae54eb7c73c26b95b238c2406`, with pre-existing shared WIP preserved. No commit, push, merge, release, deployment, production read/write, Alipay request, payment or refund occurred.

## 2026-08-22 - [CX] Final local candidate commit and requested FeiCode delivery

- User explicitly requested commit and push. The accumulated reviewed UI, notification placement, account presentation, payment-cutover documentation and offline signing gate were committed as `72abc0478fbd5da83eb52fd16dd574a21b1014fb` on `cx/wei-alipay-admin-menu-401`; the unrelated untracked `Users/` Studio-copy artifact was not staged or deleted.
- Exact committed-code verification: `./scripts/ci-local.sh` exit 0; 79 first-party PHP syntax samples plus first-party JavaScript; 413 contract assertions; PHPStan 58/58 first-party files with 0 errors; reproducible A/B candidate ZIP SHA-256 `cd0637b33d5983a7fdec9567ad9912ae66eb9ed34a8a64b8ae89a36e47232ac1`; `git diff --check` exit 0.
- Requested remote action is limited to one ordinary non-force push of the final exact HEAD to FeiCode branch `cx/wei-alipay-admin-menu-401`. It does not authorize default-branch merge, CI approval, tag, Release, GitHub downstream sync, mall deployment, production configuration/key migration, Alipay request, payment or refund.
- Online switching remains blocked pending exact-head FeiCode CI, a clone/sandbox payment lifecycle sample, production-key format and public-key fingerprint preflight, approved rollback package, and a separately approved production canary. Local CI and a generated ZIP do not satisfy these production gates.
