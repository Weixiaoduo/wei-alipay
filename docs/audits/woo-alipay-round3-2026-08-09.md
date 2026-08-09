# Woo Alipay PR #1 third-round closure

Status: `[CX]` candidate only. Do not merge, deploy, release, or connect it to a real Alipay endpoint.

## Mini-program legacy MD5 migration

The historical `alipay_wap` gateway now has an explicit protocol selector. New and credential-free sites use RSA2. Existing sites with legacy partner/shared-secret settings are migrated to `legacy_md5` but are fail-closed until both controls are present:

1. the site checks `legacy_md5_ack`; and
2. an operator defines `WOO_ALIPAY_ALLOW_LEGACY_MD5=true` (or an equivalent policy filter).

RSA2 build or verification failures never invoke MD5. MD5 callbacks are verified locally against the configured shared secret and then pass the same order, seller, amount, time, transition, and replay-lock checks. Migration procedure: stage RSA2 application credentials, switch the site selector to RSA2, complete sandbox fixtures, then remove the constant and legacy secret. Do not delete the legacy fields before rollback acceptance.

## Secret migration and rotation

`private_key` and legacy `secure_key` values are stored in AES-256-GCM versioned envelopes derived from WordPress salts or `WOO_ALIPAY_ENCRYPTION_KEY`. The public verification key stays plaintext because it is public material. Runtime secrets may be supplied through `woo_alipay_secret_value`; they are not written to the database.

Rotation procedure:

1. Keep the old material in `woo_alipay_secret_keyring` and configure the new active material.
2. Run the site upgrade hook in a disposable environment and verify the envelope `kid` changed.
3. Exercise RSA2 signing and sandbox callback fixtures.
4. Remove the retired key only after every site has rotated.

Recognized envelopes that cannot decrypt become an empty runtime credential and block payment. They never fall back to ciphertext or guessed plaintext. Tests use synthetic strings only.

## Multisite lifecycle and data retention

| Operation | Single site | Network activation | Existing data |
|---|---|---|---|
| Activate/upgrade | schema + secret migration on current site | iterates every current site with `switch_to_blog` | retained and migrated in place |
| Add a site later | admin upgrade on that site | same per-site upgrade path | no cross-site copying |
| Deactivate | clears plugin cron hooks | hooks clear in the active context | settings/orders retained |
| Uninstall, default | no deletion | no deletion | settings, secrets, order/trade metadata retained |
| Uninstall with explicit `WOO_ALIPAY_DELETE_DATA=true` | removes known gateway settings/schema/cron | iterates all sites | order and transaction metadata still retained |

Minimum declared support: PHP 7.4, WordPress 6.6, WooCommerce 8.3. HPOS and Cart/Checkout Blocks are declared compatible; the release matrix also tests WooCommerce 10.2.2. No claim is made for versions below those minimums.

## Bundled SDK boundary and provenance

The package currently contains Alipay SDK `alipay-sdk-PHP-4.20.807.ALL` under `lib/alipay` (8,829 files, about 36 MiB) plus two historical WAP sample libraries. The official SDK source is identified by its embedded version and README, but the checkout has no authoritative lock file, upstream commit, or local license text. The legacy WAP sample provenance/license is also incomplete. The SBOM therefore uses `NOASSERTION` where evidence is absent; this is a release blocker, not a pass.

Do not delete SDK request classes mechanically. First-party code directly uses a small trade/refund/query/payment subset, but `AopClient` and module paths have transitive/dynamic loads. Recommended migration:

1. replace the snapshot with the official Composer package pinned by version and lock hash;
2. run the full gateway/module and sandbox fixture matrix against the pin;
3. generate a CycloneDX/SPDX SBOM from the lock file;
4. only then remove unreachable request classes and compare deterministic package manifests.

`scripts/build-release-candidate.sh` creates an offline candidate ZIP, SHA-256 file, SDK tree digest, and copies the SBOM. It does not publish anything or perform network requests.

## Targeted first-party security cleanup

- parameterized the WPML currency lookup instead of concatenating the current currency into SQL;
- escaped the legacy form action/attributes and restricted its output allowlist;
- removed reliance on the old SDK form builder containing PHP 7-incompatible `each()`;
- made uninstall retention explicit rather than deleting a settings row unconditionally.

Formatting-only PHPCS debt and bundled SDK findings are intentionally not rewritten in this round.

## Final verification (2026-08-09)

All payment fixtures were local/synthetic. No real Alipay endpoint or transaction was used.

| Check | Command/evidence | Exit/result |
|---|---|---|
| First-party lint + contracts | `scripts/tests/run-tests.sh all` | `0`; 66 PHP/JS files and 66 contract assertions passed, including legacy identity binding, no-downgrade, secret rotation, multisite and uninstall |
| PHPStan | `vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --memory-limit=1G` | `0`; `No errors` against the established first-party baseline; no new first-party findings |
| Changed payment/security PHPCS | WordPress security sniffs on the seven changed runtime files, SDK excluded | `0`; no security-sniff errors |
| Full release PHPCS | WordPress standard on release first-party PHP, SDK excluded | `2`; 14,834 errors and 1,307 warnings across 59 file summaries, mainly historical formatting; **NOT PASS** |
| Full release security PHPCS | WordPress security-related sniffs on release first-party PHP, SDK excluded | `1`; 146 errors across 14 files; signed callback/no-nonce and prepared-table false positives coexist with real remaining admin input/output debt; **NOT PASS** |
| Plugin Check 2.0.0 | update mode on the release package, `--slug=woo-alipay`, SDK/legacy SDK directories explicitly excluded | command exit `0`, but reported 601 errors and 184 warnings in 48 files; 461 are historical text-domain mismatches, plus escaping/nonce/input/SQL findings; **NOT PASS** |
| HPOS off/on | isolated WooCommerce runtime exact merchant-number lookup | `0,0`; legacy order 23 and HPOS order 25 resolved exactly |
| Checkout Blocks | isolated Store API cart/checkout with nonce | `0`; order 30 created pending, payment response success with redirect |
| Concurrent RSA2 notify | eight parallel locally signed callbacks | eight HTTP 200 responses; one mutation succeeded, seven lock-contention failures; order completed once; sequential replay returned `success` |
| Refund | offline subclass, no network | `0`; one stable refund request persisted and replay acknowledged |
| Release build | `scripts/build-release-candidate.sh /tmp/woo-alipay-r3-final` | offline ZIP integrity passed; SHA-256 `cbc10f4a2e932978ec8122001786d8d2247f81e1cd40b421cc435181ad1d7006`; SDK tree digest `20ed3a52196334b6e32efa1d0dd2a6d899c236b07adda812185dbfe2efb4a31b`; development files absent; installed tree about 39 MiB, ZIP about 8.5 MiB, SDK 8,829 files |

The release package is not approved for publishing: Plugin Check and full first-party PHPCS still contain untriaged/security findings, and the bundled SDK/legacy libraries lack an authoritative lock, complete license evidence, and upstream integrity record. FeiCode Actions returned `total_count: 0`, and the repository contains no workflow files; PR CI is therefore **NOT PASS (zero jobs)**, not a green result.
