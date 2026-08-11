# [CX] Woo Alipay 3.2.0 release-closure audit

## Supported candidate matrix

| Surface | Minimum / mode | Candidate claim |
|---|---|---|
| PHP | 7.4 | Syntax/contract CI matrix; PHPStan on 8.3 with PHP 7.4 compatibility checks |
| WordPress | 6.6 | Plugin header/readme minimum |
| WooCommerce | 8.3 | Plugin header/readme minimum; tested runtime uses 10.2.2 |
| WordPress tested | 7.0 | Isolated test runtime only; production not touched |
| HPOS | off/on | Declared compatible and exercised with real local Woo orders in both modes |
| Checkout Blocks | Store API | Declared compatible; local Store API order/pay redirect flow only |
| Multisite | network activation/upgrade | Per-site migration; uninstall retains by default, opt-in purge visits every site |

## Secret migration and rollback

Private application keys and legacy shared secrets are stored only as AES-256-GCM versioned envelopes. Migration validates all configured secrets before changing gateway options, records one encrypted rollback generation, and fails closed when any envelope cannot be decrypted. Rotation requires the retired key to remain in `woo_alipay_secret_keyring` until rollback is no longer needed. `Woo_Alipay_Secret_Store::rollback_current_site()` restores the generation under the active key and deletes the snapshot only after all values validate. Explicit uninstall purge deletes the rollback option; default uninstall retains it with payment settings.

## SDK provenance and trimming boundary

The bundle contains 8,829 files / about 36 MiB under `lib/alipay`, plus two five-file legacy module SDK copies. The declared main SDK version is 4.20.807.ALL and points to `alipay/alipay-sdk-php-all`, but the vendored tree has no lock file, upstream archive digest, or bundled license file. The legacy module copies have no asserted upstream source or license. Therefore SDK license/provenance is **NOT PASS** and blocks release.

Migration: first inventory runtime-loaded SDK files, replace the tree with a Composer-locked official distribution in a non-production compatibility branch, add upstream archive/commit hashes and license texts, then run the full gateway/refund/notification matrix. Do not delete SDK files based only on static reachability. This 3.2.0 candidate keeps the existing bundle and records every SDK file hash in `sdk-files.sha256`.

## Lifecycle and product decisions

Mini-program defaults to RSA2. Legacy MD5 is available only when both site acknowledgement and operator policy explicitly enable transition mode; verification never downgrades from RSA2. Existing payment modules remain present. Default uninstall retains gateway settings and financial/order metadata. An explicit `WOO_ALIPAY_DELETE_DATA=true` policy purges plugin options and schedules per site but does not delete order records.

## Deferred migrations

- Third-party QR: proxy through a same-origin, allowlisted endpoint with bounded timeout/cache, then remove the external QR dependency after runtime parity tests.
- Remote close: move to a retryable job with idempotency key, strict timeout, terminal-state recheck and operator-visible uncertain state; never close remotely from uninstall.
- Multisite: keep per-site secret envelopes and lifecycle markers; add network admin inventory and staged rotation before any network-wide key retirement.

## Fourth-round verification

All commands ran in `/home/parallels/Projects/woo-alipay-cx-audit` or its isolated wp-env container. No production credential was loaded and no Alipay endpoint was contacted.

| Gate | Evidence | Result |
|---|---|---|
| First-party contracts | `./scripts/tests/run-tests.sh all` | PASS, exit 0; 66 PHP/JS files and 66 security/lifecycle assertions |
| PHPStan | `composer analyse -- --debug --no-progress` | PASS, exit 0; full configured first-party scope, no errors |
| Security PHPCS | WordPress security/DB sniffs against the first-party file list | PASS, exit 0; 0 errors / 0 warnings |
| PHP 7.4 syntax | `php:7.4-cli` release first-party lint | PASS, exit 0; 61 files |
| Runtime | isolated WP 7.0.3 / WC 10.2.2 / PHP 8.3.33 | PASS for the sampled paths below |
| Declared minimum WP/WC | WP 6.6 / WC 8.3 | NOT RUN as an actual runtime pair; metadata and CI contract only |
| Plugin Check | release tree, SDK directories excluded and separately inventoried | NOT PASS; final counts are recorded after the final clean-HEAD build |
| SDK provenance/license | SBOM plus per-file/tree hashes | NOT PASS; upstream lock/archive digest and bundled licenses are absent |

Runtime samples: HPOS off created/found order 31 and HPOS on created/found order 32; Checkout Blocks Store API created order 35 and returned HTTP 200 with an Alipay redirect; eight concurrent signed local callback requests completed only order 36 once with transaction `R4-TRADE-36`; an offline refund fixture made one SDK-stub call and acknowledged the replay; encrypted migration/rotation/rollback/unreadable-envelope behavior passed. These are isolated synthetic samples, not evidence of an Alipay sandbox or production transaction.

## Plugin Check disposition

The original full package baseline was 601 errors. The fourth-round first scan was 539 errors / 38 warnings; after first-party security fixes and safe text-domain normalization it was 68 errors / 37 warnings before the final three text-domain repairs. The remaining error classes are translation placeholder comments, the intentional FeiCode self-updater conflict with WordPress.org packaging, and the existing third-party QR script. Security PHPCS for callback input, nonce/capability, SQL, output and redirect/network categories is separately zero. No SDK result is hidden: the SDK is excluded from Plugin Check because it is separately hashed and its unresolved provenance/license status blocks release.

The external QR script must be replaced by a same-origin packaged implementation before a WordPress.org-style release. The updater must be removed only in a distinct WordPress.org distribution profile; it remains intentional for the FeiCode private channel and must never compete with a WordPress.org update source in the same package.

## Board-gated delivery

FeiCode is P0 with `write_policy=board_required`. The tracked Board template at `docs/governance/feicode-board-change-template.md` permits only an ordinary push of one exact local HEAD to the existing PR review branch and requires CI approval bound to the same SHA. Merge, release, deployment, default-branch mutation, live Alipay access and real transaction/refund are explicitly out of scope.
