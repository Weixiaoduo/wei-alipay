# Woo Alipay handoff

## [CX] 2026-08-09 comprehensive audit/remediation

- Source of truth: `ssh://git@feicode.com:2222/Weixiaoduo/woo-alipay.git` at baseline `d135d670b1ee8ff0dcdc02e55f72e5b5312f1c47`; public mirror was 17 commits behind.
- Isolated worktree/branch: `/home/parallels/Projects/woo-alipay-cx-audit`, `codex/woo-alipay-audit-20260809`.
- Boundary: no production deployment/release/default-branch merge, no live Alipay call, no real transaction, and no historical-data deletion.
- Delivered: callback context validation/redaction/idempotency, AJAX order-key authorization, query/reconcile amount checks, timeout fail-closed behavior, HPOS order metadata/query repairs, F2F assets/authorization, barcode currency guard, reconciliation SSRF guard, updater isolation/PHP 7.4 fix, webhook table/retry hardening, safe tests, compatibility metadata, and audit report.
- Audit report: `docs/audits/woo-alipay-comprehensive-audit-2026-08-09.md`.

### Verification

- `./scripts/tests/run-tests.sh all` — exit 0: 8,895 PHP files linted, first-party JavaScript parsed, `git diff --check` clean, and 8 notification/security contract assertions passed.
- PHPCompatibilityWP on all modified PHP files with `testVersion=7.4-` — exit 0.
- Isolated WordPress 7.0.3 / WooCommerce 10.2.2 / PHP 8.3 runtime — activation exit 0; seven gateway IDs registered; five Checkout Block payment method integrations registered.
- HPOS off and on — both created a real Woo order and found `_alipay_out_trade_no` using `wc_get_orders(meta_query)`; exit 0 in both modes.
- Generated-key callback matrix in the isolated runtime — valid callback `success`, exact replay `success` with note count unchanged at 2, signed amount/app/transaction mismatches and invalid signature all `fail`; order remained paid with the original transaction; exit 0. No Alipay network call occurred.
- Candidate ZIP `/tmp/woo-alipay-package-20260809/woo-alipay-3.2.0-candidate.zip` — 9,003 entries / 9,290,847 bytes; `unzip -t`, top-level exclusion and secret-pattern checks exit 0; clean ZIP install/activation and core gateway registration exit 0.
- WordPress PHPCS on the 16 modified PHP files — exit 2 with 9,342 errors and 872 warnings, overwhelmingly pre-existing formatting/naming/escaping debt; not PASS and not auto-fixed because a 9,219-change formatting rewrite would obscure payment fixes.
- PHPStan level 1 on four core files — exit 1 with 124 findings, dominated by missing WooCommerce/SDK symbols because the repository has no WooCommerce/PHPStan bootstrap; result is incomplete, not PASS.
- Plugin Check 2.0.0 full repository scan — terminated after 10 minutes with exit 124 and no result; the bundled 8,000+ file SDK kept the scanner CPU-bound. This is INCOMPLETE, not PASS.

### Open constraints

- Production/sandbox merchant network flows, real refund, agreement renewal, profit sharing, and settlement were not exercised.
- Plaintext option-stored keys, external QR CDN, SDK packaging/SBOM, remote-close timeout state machine, legacy mini/cross-border cleanup, multisite lifecycle, and module split remain follow-up work described in the audit.
