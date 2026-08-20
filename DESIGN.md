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
| `page=wei-alipay` | choose optional products/tools | grouped elevated feature rows, dependency/status summary, explicit Save |
| WooCommerce `section=alipay` | configure the core gateway | WooCommerce settings foundation, progressive Basic/Credentials/Reliability/Advanced sections |
| `page=wei-alipay-profitsharing` | configure and operate sharing | URL tabs: Settings, Query, Receivers, Rules |
| `page=wei-alipay-ps-jobs` | inspect/retry queue | toolbar, filters, table, pagination, separate danger zone |
| `page=wei-alipay-ps-compensation` | submit compensation | warning, narrow form, review/confirmation, result |
| `page=wei-alipay-reconcile-pro` | schedule/run reconciliation | summary, scheduled settings, separate manual action panel |
| `page=wei-alipay-webhooks` | inspect/retry callbacks | filters/search, bounded log table, retry feedback |
| `page=wei-alipay-subscriptions` | configure agreement payments | dependency state, settings, sandbox-only signing shortcut |

### Detail rules for related screens

- All standalone tool screens use one product heading, one task sentence and then navigation or content. Do not repeat a second product heading inside the first card.
- Profit-sharing settings, query, receivers, rules, jobs and compensation form one six-tab family. The active tab uses `aria-current="page"`; each tab has its own URL and browser history entry.
- Reconciliation uses separate Schedule and Manual URLs. Merely viewing or saving the schedule cannot expose or trigger the provider-contacting manual action.
- Gateway section links reuse WooCommerce-generated heading IDs. They update the URL fragment, move keyboard focus to the native section heading and never replace the WooCommerce settings form.
- List filters remain native `.subsubsub` or GET controls rather than being disguised as feature tabs. Bulk actions stay visible at 782px and below.
- Destructive queue cleanup sits in a labelled danger zone separated from non-destructive filter/export controls. The warning names what is deleted and what is retained.
- Empty queue/log states retain the table or card context, use one concise title and sentence, and never show a success state when the sample count is zero.

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
