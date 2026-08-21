# Wei Alipay administration design system

This is the implementation contract for the feature centre, gateway settings, profit sharing, jobs, compensation, reconciliation, webhook logs and subscription administration. Checkout rendering remains governed by WooCommerce and each gateway integration.

## 1. Design direction

**WordPress native first, WooPayments visual language second.** Native WordPress/WooCommerce controls, page routing, settings APIs, focus behavior and accessibility are the foundation. WooPayments 11.0.0 supplies the reference for density, hierarchy, surfaces, status treatments, progressive disclosure and asynchronous feedback. Wei Alipay implements these ideas with its own `--wei-*` tokens and scoped markup; it does not ship copied WooPayments CSS, JavaScript, React components, private selectors, fonts or icons.

Product rules:

1. One clear task and one page title per view.
2. State precedes action: inactive, configuration required, ready, working, succeeded, failed or unavailable.
3. Optional payment products are disclosed and loaded only after explicit enablement.
4. Reads/previews are distinct from writes; consequential payment operations name their effect and require confirmation.
5. A visual rename never changes gateway IDs, settings keys, order metadata, callback routes or schedules.
6. Private keys, complete signed payloads, buyer identifiers and unbounded provider errors never enter DOM feedback, exports or screenshots.

## 2. Evidence

The source packages, extraction command, artifact SHA-256 values, raw inventories and interaction-marker counts are recorded in:

- `docs/design/woopayments-admin-design-reference.md`
- `docs/design/woopayments-admin-tokens.json`
- `scripts/extract-woopayments-admin-design.py`

The inventory covers 30 WooPayments CSS, 45 WooPayments JavaScript, 27 WooCommerce CSS and 152 WooCommerce JavaScript administration artifacts. Raw occurrence counts are evidence, not semantic tokens. The tables below are the reviewed semantic layer.

## 3. Token contract

All tokens live under `.wei-alipay-admin` or the scoped WooCommerce gateway settings section. Never define global `body`, `.button`, `.notice`, `.form-table` or `.wp-list-table` replacements.

### 3.1 Color

| Token | Value | Use |
| --- | --- | --- |
| `--wei-color-text` | `#1e1e1e` | headings and primary copy |
| `--wei-color-text-secondary` | `#757575` | descriptions and row metadata |
| `--wei-color-text-wp` | `#646970` | WordPress-compatible secondary copy |
| `--wei-color-surface` | `#fff` | primary cards and tables |
| `--wei-color-surface-subtle` | `#f6f7f7` | grouped content and code blocks |
| `--wei-color-surface-hover` | `#f0f0f0` | hover/selected-row background |
| `--wei-color-border` | `#e0e0e0` | internal dividers and quiet cards |
| `--wei-color-border-strong` | `#c3c4c7` | controls and legacy WP boundaries |
| `--wei-color-accent` | `var(--wp-admin-theme-color, #2271b1)` | focus, link and selected state |
| `--wei-color-accent-subtle` | `#f0f6fc` | selected/linked quiet surface |
| `--wei-color-success-text` | `#005c12` | success text |
| `--wei-color-success-surface` | `#edfaef` | success pill/callout surface |
| `--wei-color-warning-text` | `#614200` | warning text |
| `--wei-color-warning-surface` | `#fcf9e8` | warning pill/callout surface |
| `--wei-color-error-text` | `#8a2424` | error/danger text |
| `--wei-color-error-surface` | `#fcf0f1` | error pill/callout surface |
| `--wei-color-info-text` | `#0a4b78` | informational text |
| `--wei-color-info-surface` | `#f0f6fc` | informational surface |

Color never carries state alone. Every semantic color has text or a meaningful icon. Ordinary primary actions use the admin theme color, not semantic green.

### 3.2 Typography

Use the inherited WordPress system stack. Product/UI copy must not load a remote font. `code` and machine identifiers may use the WordPress monospace stack.

| Token | Size / line height | Use |
| --- | --- | --- |
| `--wei-font-size-meta` | `11px / 16px` | timestamps and tertiary metadata |
| `--wei-font-size-label` | `12px / 16px` | compact status and helper labels |
| `--wei-font-size-body` | `13px / 20px` | default administration copy |
| `--wei-font-size-body-emphasis` | `14px / 20px` | emphasized row text |
| `--wei-font-size-section` | `16px / 24px` | card/section heading |
| `--wei-font-size-page` | `20px / 28px` | page heading when not provided by core |
| `--wei-font-size-display` | `24px / 32px` | empty-state or onboarding heading only |

Weights are 400 regular, 500 medium and 600 semibold. Avoid all-caps translated labels and decorative bold paragraphs.

### 3.3 Spacing and density

| Token | Value | Use |
| --- | ---: | --- |
| `--wei-space-1` … `--wei-space-8` | `4, 8, 12, 16, 24, 32, 40, 48px` | four-point spacing scale |
| `--wei-control-height` | `36px` | custom compact control only |
| `--wei-row-height-compact` | `48px` | compact operational row minimum |
| `--wei-row-height` | `64px` | standard settings/feature row minimum |
| `--wei-card-padding` | `24px` | desktop card inset |
| `--wei-card-padding-mobile` | `16px` | card inset below 782px |

Do not override native button/input dimensions merely to reach these values. The tokens control Wei composition and custom exceptions.

### 3.4 Shape and elevation

| Token | Value | Use |
| --- | --- | --- |
| `--wei-radius-small` | `2px` | compact indicator |
| `--wei-radius-control` | `4px` | custom control or quiet card |
| `--wei-radius-card` | `8px` | elevated feature/summary card |
| `--wei-radius-pill` | `9999px` | text status only |
| `--wei-shadow-quiet` | `0 2px 6px rgba(0,0,0,.05)` | floating summary card |
| `--wei-shadow-card` | layered 2/4/16px, 2–5% alpha | feature/onboarding card only |
| `--wei-focus-ring` | white 2px + admin-theme 4px | custom interactive control |

A card uses either a quiet border or an elevation token. Tables and routine settings rows remain flat.

### 3.5 Layout and breakpoints

| Token | Value | Use |
| --- | ---: | --- |
| `--wei-content-reading` | `680px` | explanatory content |
| `--wei-content-standard` | `1120px` | ordinary page shell |
| `--wei-content-wide` | `1280px` | operational tables |
| `--wei-breakpoint-wide` | `1280px` | wide-grid reduction |
| `--wei-breakpoint-admin` | `960px` | admin navigation composition |
| `--wei-breakpoint-mobile` | `782px` | WordPress mobile behavior |
| `--wei-breakpoint-narrow` | `600px` | one-column composition |
| `--wei-breakpoint-compact` | `400px` | compact fallback |

CSS custom properties cannot be used directly in media conditions; implementation uses the numeric values while keeping these names in documentation and extraction metadata.

### 3.6 Motion

- `--wei-motion-fast: 100ms` for focus/pressed/color feedback.
- `--wei-motion-standard: 150ms` for simple fades.
- `--wei-motion-disclosure: 200ms` for reveal/collapse.
- `--wei-motion-long: 300ms` is the maximum ordinary transition.
- Use `ease-out` when revealing, `ease-in` when removing and `linear` only for progress rotation.
- Under `prefers-reduced-motion: reduce`, set animations and transitions to effectively zero and retain textual loading state.

## 4. Component hierarchy

### 4.1 Native foundation

| Need | Native foundation | Wei composition allowed |
| --- | --- | --- |
| Page | `wrap`, native `h1` | max width, header description/actions |
| Settings | WooCommerce settings API, `form-table` | grouped section surface and dirty-state footer |
| Navigation | `nav-tab-wrapper`, links/GET state | compact underline treatment, mobile overflow |
| Feedback | `notice-*`, ARIA live region | compact inline status/callout composition |
| Lists | `wp-list-table`, `widefat`, `striped`, `tablenav`, `subsubsub` | toolbar, overflow, status pill, empty state |
| Actions | `button`, `button-primary`, `button-link-delete`, `spinner` | icons, async label and confirmation behavior |
| Inputs | WordPress/WooCommerce controls | prefix/suffix, validation message and description |
| Modal | WordPress Components Modal when available | financial consequence copy and action ordering |

“Native first” does not mean leaving every screen visually uncomposed. It means keeping the stable control semantics while the Wei layout adopts WooPayments hierarchy and density.

### 4.2 Page header

One `h1`, optional 680px description and an optional right-aligned action group. Do not duplicate the title inside the first card. Header actions collapse below the title under 600px.

### 4.3 Section card

Use a flat bordered section for routine forms and an elevated 8px card only for feature selection, onboarding or a high-level summary. Card header contains title, description and optional status/action; body contains one conceptual task. Avoid nested cards.

### 4.4 Tabs and filters

Tabs are URL-addressable and preserve browser back/reload. Use `aria-current="page"` or the native active class. Operational status filters use `subsubsub` or a compact segmented row; search/filter is GET and mutations are POST.

### 4.5 Status pill

Pills contain text and optionally a 12–16px icon. Variants: neutral, info, success, warning and error. “Inactive” is neutral; “Action needed” is warning; provider/network failures are error. Do not use a pill as a button.

### 4.6 Settings row

A row has label, concise description, control and validation/metadata. Advanced or dangerous fields are progressively disclosed rather than mixed into the first screen. Secret values are masked and never restored into visible HTML after save.

### 4.7 Data table

Use native table semantics, sticky behavior only when useful, 48px minimum row height, sortable headers with announced direction, status text, bounded errors and an action menu/explicit action column. Wide tables scroll inside `.wei-alipay-table-wrap`; the admin body never overflows.

### 4.8 Empty, loading and error states

- Empty: 24px title at most, explanation, one primary recovery action and optional documentation link.
- Loading: preserve geometry, apply `aria-busy="true"`, disable only the initiating control and show text plus spinner/skeleton.
- Error: retain user input where safe, focus/announce the summary and associate field errors using `aria-describedby` / `aria-invalid`.

## 5. Interaction state machines

### Save

`pristine → dirty → submitting → success | error`. Dirty starts only after a real value change. Submit disables the submit control, sets busy state and prevents duplicate POST. Success clears dirty state after server confirmation; error restores controls and keeps safe input.

### Remote test/query/retry

`idle → confirming (when consequential) → requesting → succeeded | failed`. The operation has a stable idempotency/request identifier where supported. The initiating button cannot be re-entered while requesting. Feedback is textual and bounded; no raw provider payload is rendered.

### Toggle vs disclosure

A toggle immediately represents a boolean setting but does not silently enable a WooCommerce gateway. A disclosure reveals dependent controls without changing persisted state. Dependencies are explained before a disabled toggle.

### Search/filter/pagination

Filter and pagination are URL state. Remote free-text search is debounced; stale responses cannot replace newer results. Pagination retains filters and exposes current/total pages to assistive technology.

### Confirmation

Confirmation copy names the object, remote effect and reversibility. Default focus is on Cancel for refunds, compensation, clearing and remote close. A warning banner never substitutes for server capability, nonce, validation, idempotency and audit controls.

### Copy

Only callback URLs and non-secret identifiers may be copied. Success/failure is announced through a concise live region. Clipboard failure leaves the source selectable.

## 6. Page information architecture

| Route | Primary task | Composition |
| --- | --- | --- |
| `page=wei-alipay` | understand current readiness and choose optional products/tools | native page tabs for Overview, Payment methods, Operations, Account & authorization; grouped feature rows and explicit Save |
| `page=wei-alipay&view=account` | connect and manage the Weixiaoduo.com site authorization | one connection summary, disclosed data list, connect/manage/unlink actions; no feature toggles |
| WooCommerce `section=alipay` | configure the core gateway | WooCommerce settings foundation, progressive Basic/Credentials/Reliability/Advanced sections |
| `page=wei-alipay-profitsharing` | configure and operate sharing | URL tabs: Settings, Query, Receivers, Rules |
| `page=wei-alipay-ps-jobs` | inspect/retry queue | toolbar, filters, table, pagination, separate danger zone |
| `page=wei-alipay-ps-compensation` | submit compensation | warning, narrow form, review/confirmation, result |
| `page=wei-alipay-reconcile-pro` | schedule/run reconciliation | summary, scheduled settings, separate manual action panel |
| `page=wei-alipay-webhooks` | inspect/retry callbacks | filters/search, bounded log table, retry feedback |
| `page=wei-alipay-subscriptions` | configure agreement payments | dependency state, settings, sandbox-only signing shortcut |

### Detail rules for related screens

- The feature centre is not one endless mixed form. Overview, Payment methods, Operations and Account & authorization are URL-addressable native tabs. Each view has one primary task and retains the product heading.
- Authorization is explained before it is requested. The unconnected account card lists the exact required service data: normalized site host, installation ID, WordPress/WooCommerce/PHP/plugin versions, locale, currency, enabled feature scopes and last contact time. It also states that orders, amounts, customer records and Alipay private keys are not sent.
- Use fixed authorization states: `Connect for free`, `Available`, `Alipay product required`, `Commercial authorization required`, `Dependency missing`, `Configuration required`, `Enabled`. A disabled switch always names the unmet condition.
- Do not repeat two or three sales/documentation links in every feature row. Each row gets one contextual action; complete admission, pricing and privacy information belongs in a disclosure or the Account & authorization view.
- Account connection and optional diagnostics are separate choices. Required service data is confirmed during connection; extra diagnostics remain off until a separate opt-in.
- Authorization-server latency is never part of checkout rendering. The UI displays the last successful background sync, and runtime decisions use a locally verified signed entitlement.
- All standalone tool screens use one product heading, one task sentence and then navigation or content. Do not repeat a second product heading inside the first card.
- Profit-sharing settings, query, receivers, rules, jobs and compensation form one six-tab family. The active tab uses `aria-current="page"`; each tab has its own URL and browser history entry.
- Reconciliation uses separate Schedule and Manual URLs. Merely viewing or saving the schedule cannot expose or trigger the provider-contacting manual action.
- Gateway section links reuse WooCommerce-generated heading IDs. They update the URL fragment, move keyboard focus to the native section heading and never replace the WooCommerce settings form.
- List filters remain native `.subsubsub` or GET controls rather than being disguised as feature tabs. Bulk actions stay visible at 782px and below.
- Destructive queue cleanup sits in a labelled danger zone separated from non-destructive filter/export controls. The warning names what is deleted and what is retained.
- Empty queue/log states retain the table or card context, use one concise title and sentence, and never show a success state when the sample count is zero.

### Account progressive-disclosure contract

The Account & authorization view uses this fixed reading order:

1. **Conclusion:** connection state, masked account identity when available and one primary action.
2. **Access:** only the free/commercial entitlement summary needed to understand why a feature is locked.
3. **Disclosure:** required connection data and explicit exclusions in a native closed `details` element.
4. **Diagnostics:** site host, installation-ID suffix and last successful sync in a second closed `details` element.
5. **Danger:** disconnect/revoke actions appear last, only when connected, in a separate closed disclosure.

Normal state must not show token values, API endpoints, full installation identifiers, event payloads, policy versions or retry traces. Those belong in bounded diagnostics/export flows. An error opens only the disclosure containing the corrective action; unrelated disclosures remain closed. A disabled Connect control must explain that the reviewed connector is not present rather than navigating to an insecure placeholder flow.

### Connected identity and avatar contract

- A connected identity shows the masked account label and the Cravatar image supplied by the reviewed Wei Account connector. The payment plugin never receives an account email solely to compute an avatar and never derives MD5/SHA hashes from account PII.
- The account-state adapter supplies a complete HTTPS `avatar_url`. Rendering is limited to the explicit Cravatar host allow-list, uses a 40×40 circular image, lazy decoding and `referrerpolicy="no-referrer"`. Invalid, non-HTTPS or non-Cravatar URLs fall back to the WordPress user Dashicon.
- The unconnected state never loads a remote avatar. Avatar failure must not change entitlement state, block configuration or expose the account email as alternative text.
- `wei_alipay_cravatar_hosts` is an integration escape hatch for an approved Cravatar delivery host, not a generic remote-image allow-list. Connector review must bind any added host to the Cravatar service before deployment.
- The connected primary action is labelled `Manage on Weixiaoduo.com` / `在 Weixiaoduo.com 中管理`; the customer-facing destination is the product website rather than the internal connector name.

### Overview information architecture

The Overview page is an operational summary, not a marketing landing page. It contains:

1. Three compact conclusions: main gateway readiness, enabled optional-feature count and Weixiaoduo account state.
2. **Payment readiness:** sandbox/live environment, gateway enabled, private-key decryptability/storage mode, public merchant configuration, store currency and HTTPS callback readiness. It never prints, returns or exports credential values.
3. **Account authorization:** connection, locally cached scopes, expiry, offline grace and last successful synchronization. Checkout never waits for the account service.
4. **Payment reliability:** last callback result and sanitized error code, unresolved recent callback hashes, and the last observed pending-order/timeout task completion.
5. **Last seven days:** local counts of successful payments, failed payment transitions and created refunds, plus a success rate only when at least one payment event exists. Amounts, order numbers and customers are excluded.
6. **Compatibility and maintenance:** HPOS mode, Checkout Blocks integration, local WordPress/WooCommerce/PHP/plugin versions and the existing WordPress update-cache state.
7. **Needs attention:** at most three items, ordered payment/key health → account authorization → callback/recovery → update. A clear local state explicitly says it does not replace an Alipay sandbox acceptance test.

The page performs no Alipay or account-server request, no order-table scan and no webhook-log query during rendering. `Wei_Alipay_Health` records only timestamps, counters, sanitized error codes and site-keyed event hashes in one non-autoloaded option. It retains eight days, caps activity hashes at 500 and callback failures at 100, and uses a short self-healing option lock. Provider acceptance, historical activity before the recorder was installed and real transaction success must not be inferred from a zero-event dashboard.

### Connection-aware navigation and value-added services

The feature centre uses progressive navigation rather than exposing every product before it is relevant:

| Account state | Visible tabs |
| --- | --- |
| Not connected | Overview, Payment methods, Operational tools, Account and authorization |
| Connected | Overview, Payment methods, Operational tools, Value-added services, Account and authorization |

`Value-added services` is the approved label. Do not call the page `Advanced features`: recurring debit, profit sharing, fund authorization and cross-border acceptance are not merely advanced settings; they can require separate Alipay/Alipay+ admission, Weixiaoduo.com authorization and paid implementation or support.

- `Payment methods` keeps the existing gateway list unchanged, including Fund Authorization and Cross-Border where their natural task is checkout configuration.
- `Operational tools` keeps the existing recovery, webhook, reconciliation, Subscriptions and Profit Sharing rows in their natural task context.
- `Value-added services` is an authorization and service catalogue for commercial/specialist capabilities. It does not move or duplicate their settings.
- The connected state controls only whether the catalogue tab is relevant and visible. It never proves entitlement, provider admission or runtime availability.
- Until the reviewed installation-token and signed-entitlement adapter lands, value-added rows have no enable switch. Each row shows one bounded status and a requirements disclosure; the entire page has one Weixiaoduo.com service action rather than one repeated application button per row. All restricted runtimes remain fail closed.
- A direct request for `view=services` while disconnected is normalized to Account and authorization. CSS-only hiding is forbidden.
- When entitlement exists in a later release, each service still requires dependency, signed scope, provider acknowledgement and local configuration before a Configure or Enable action can appear.

Commercial authorization expiry follows “stop new business, preserve existing obligations”: prevent new agreements, receivers or rules, but retain callbacks, refunds, queries, agreement cancellation, existing-job compensation and audit access. Checkout and callbacks never wait synchronously for the account service.

Do not generate a top-level tab for every payment method after it is enabled. Dynamic gateway tabs make navigation unstable and crowded. The Payment methods page retains its list and may add one compact `Enabled payment services` status block when optional gateways are effectively active. The block summarizes readiness only; configuration remains in the existing WooCommerce gateway screen and the original feature row remains the source of activation.

## 7. Accessibility, localization and responsiveness

- WCAG 2.2 AA contrast; visible `:focus-visible`; never remove a focus outline without the documented replacement.
- Real checkbox/button/link semantics. Switch visuals wrap a checkbox; cards are not clickable containers unless the whole card has correct keyboard semantics.
- Use `aria-busy`, `aria-live`, `aria-expanded`, `aria-controls`, `aria-selected`, `aria-invalid` and `aria-describedby` only for their actual semantics.
- English source msgids and complete `zh_CN` translations for changed surfaces. Product/protocol identifiers remain consistent; placeholder strings include translator comments.
- At 782px form rows stack and card padding becomes 16px. At 600px headers/toolbars become one column. At 400px secondary actions wrap without clipping.
- RTL uses logical properties (`margin-inline`, `padding-inline`, `inset-inline`) for new styles.

## 8. Implementation boundaries

- Shared tokens/components: `css/admin/design-system.css`.
- Shared progressive enhancement: `js/admin/design-system.js`.
- Feature-centre specialization: `css/admin/features.css`, `js/admin/features.js`.
- Assets load only on Wei Alipay screens or the exact WooCommerce Alipay settings section.
- No frontend dependency, remote asset, copied WooPayments bundle or private React selector.
- Feature selection remains the only custom module selector. It uses a real checkbox, native button semantics, documented Wei tokens and admin-theme focus.

## 9. Review gate

For every changed page verify exact route/capability/nonce; task hierarchy; empty/loading/success/error states; keyboard and screen-reader semantics; 1280/960/782/600/400 behavior; English/Chinese strings; secret redaction; no real Alipay traffic; PHPCS security categories; PHPStan; contract tests; release-package Plugin Check; and WordPress Studio visual samples. Token extraction, a static check or zero runtime samples cannot be reported as a visual PASS.
