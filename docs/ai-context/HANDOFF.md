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
