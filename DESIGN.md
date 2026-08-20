# Wei Alipay Admin Design Guidelines

This document is the implementation contract for the Wei Alipay WordPress administration experience. It covers the feature centre, payment configuration entry points, profit sharing, jobs, compensation, reconciliation, webhook logs and subscriptions. It does not redefine the WooCommerce checkout UI or the WooCommerce core Payments list.

## 1. Product principles

1. **Progressive disclosure.** The main Alipay gateway is always available. Optional payment products and operational tools are loaded only after explicit feature enablement.
2. **One task per view.** A page may contain related panels, but a long configuration domain must use tabs or separate routes instead of a single uninterrupted form wall.
3. **Safe by default.** Reads and previews are visually separate from writes. Retry, compensation, clearing and other consequential actions require clear wording and confirmation.
4. **State before action.** Every operational page first explains whether the feature is active, configured, waiting, successful, failed or unavailable.
5. **WordPress native first.** Use WordPress admin markup and classes before writing CSS: `wrap`, `nav-tab-wrapper`, `nav-tab`, `notice`, `card`, `form-table`, `wp-list-table`, `widefat`, `striped`, `tablenav`, `button`, `button-primary` and `button-link-delete`. Custom styling is allowed only where core has no stable equivalent.
6. **Financial identity stability.** Visual or product naming changes do not rename gateway IDs, settings keys, order metadata, callback routes or schedules.
7. **No secret exposure.** Private keys, full signed payloads, buyer identifiers and unbounded provider errors never appear in status cards, tables, exports or screenshots.

## 2. Source and attribution boundary

The initial token study uses local WooPayments 11.0.0 administration artifacts. Hashes, raw values, source-map component paths and the extraction command are recorded in `docs/design/woopayments-admin-design-reference.md` and `docs/design/woopayments-admin-tokens.json`.

Wei Alipay adopts recurring WordPress-aligned values and interaction ideas, not WooPayments source code, compiled JavaScript, private React components, selectors, icons, trademarks or onboarding assumptions. The draggable payment provider list is owned by WooCommerce core.

## 3. Token architecture

Tokens live in `css/admin/design-system.css` and use the `--wei-*` prefix. They are aliases and fallbacks for values missing from WordPress admin CSS, not a replacement component system. Prefer `--wp-admin-theme-color` for accents and inherited WordPress typography/control dimensions.

### 3.1 Color

| Token | Value | Purpose |
| --- | --- | --- |
| `--wei-color-text` | `#1e1e1e` | Primary text |
| `--wei-color-muted` | `#646970` | Descriptions and metadata |
| `--wei-color-surface` | `#fff` | Cards, panels and tables |
| `--wei-color-subtle` | `#f6f7f7` | Code blocks and quiet states |
| `--wei-color-border` | `#c3c4c7` | WordPress-aligned structural border |
| `--wei-color-accent` | admin theme → `#2271b1` | Accent fallback only |

Semantic color must always be accompanied by text. Do not use red for an ordinary disabled state or green for an action button.

### 3.2 Spacing

The supplemental spacing scale is `4, 8, 12, 16, 20, 24px`, exposed as `--wei-space-1` through `--wei-space-6`. Native component padding is not overridden.

### 3.3 Typography

- Page and section headings inherit WordPress admin styles.
- Body, labels and descriptions inherit WordPress administration typography.
- Machine identifiers use native `code`, never a custom decorative monospace font.
- Do not use all caps for translated interface labels.

### 3.4 Layout

The only shared content-width token is `--wei-content-wide: 1180px`. WordPress owns radii, shadows, focus rings, button dimensions and table styling. The admin mobile breakpoint follows WordPress at `782px`. Motion is removed under `prefers-reduced-motion: reduce`.

## 4. Component contract

### Native component map

| Need | WordPress/WooCommerce component | Wei supplement allowed |
| --- | --- | --- |
| Page shell | `wrap`, native headings | maximum width and description measure |
| Sections | `card`, settings sections | wide-card width only |
| Navigation | `nav-tab-wrapper`, `nav-tab` | horizontal overflow on small screens |
| Feedback | `notice-*`, `inline`, `is-dismissible` | none |
| Settings | WooCommerce settings API, `form-table` | dirty-state text only |
| Lists | `wp-list-table`, `widefat`, `striped`, `tablenav`, `subsubsub` | horizontal overflow and compact status text |
| Actions | `button`, `button-primary`, `button-link-delete`, `spinner` | confirmation attribute and clipboard behavior |
| Empty results | `no-items`, `colspanchange` | centered spacing when no table exists |

The custom feature-selector rows and switches are the sole page-level exception because WordPress has no native feature-module selector. They must still use real checkboxes, native buttons, Dashicons and WordPress focus colors.

### Page shell

Use `<div class="wrap wei-alipay-admin">`, one native `h1`, and an optional `.wei-alipay-admin__description`. A page must not begin with an unexplained form.

### Panel

Use WordPress `.card` plus the narrow `.wei-alipay-card-wide` width escape when a task needs containment. Do not reproduce card borders, shadows or padding. Avoid nested cards.

### Tabs

Use `<nav class="nav-tab-wrapper wp-clearfix">` and WordPress `.nav-tab` / `.nav-tab-active`. The tab remains a normal link so the URL is shareable and reload-safe.

### Callout

Use WordPress `.notice`, `.notice-info`, `.notice-warning`, `.notice-error`, `.notice-success` and `.inline`. Do not create a parallel callout component.

### Status chip

Plain status text is preferred. The small `.wei-alipay-status` supplement is permitted only where row scanning materially benefits and WordPress has no public stable badge class. It must accompany text such as `Pending`, `Succeeded` or `Failed`.

### Metrics

Use native `subsubsub` status filters with counts. Do not add dashboard metric cards when the same information is already available in those filters.

### Forms

- Every mutating form requires a capability check and nonce.
- Inputs have programmatic labels and descriptions explain format, side effects and provider prerequisites.
- Settings forms may use `data-wei-dirty` and a `[data-wei-dirty-message]` live region.
- One panel has one primary submit button. Destructive buttons use WordPress delete styling and `data-wei-confirm`.
- Do not silently enable a WooCommerce gateway when enabling its feature module.

### Tables and empty states

Use `wp-list-table widefat fixed striped` and place only the overflow wrapper `.wei-alipay-table-wrap` around wide tables. Empty results use a native-style `no-items` row or the small `.wei-alipay-empty` supplement.

### Dangerous actions

Render consequential guidance as `notice notice-warning inline` or `notice notice-error inline`; use `button-link-delete` and `data-wei-confirm` for destructive actions. A warning does not replace server authorization, validation, idempotency or audit records.

## 5. Page information architecture

| Route | Primary task | Required pattern |
| --- | --- | --- |
| `page=wei-alipay` | Select loaded features | Grouped feature cards, two-step activation explanation |
| WooCommerce `section=alipay` | Configure main gateway | Native WooCommerce settings with Wei status/help styling only |
| `page=wei-alipay-profitsharing` | Configure profit sharing | Tabs: Settings, Query, Receivers, Rules |
| `page=wei-alipay-ps-jobs` | Inspect/retry queue | Filters, export, status chips, scroll table, separate danger zone |
| `page=wei-alipay-ps-compensation` | Create manual compensation | Danger callout, narrow form, explicit confirmation |
| `page=wei-alipay-reconcile-pro` | Schedule and manually run reconciliation | Separate configuration and manual-run panels; result summary |
| `page=wei-alipay-webhooks` | Inspect/retry callback processing | Native status filters with counts, bounded error text, retry controls |
| `page=wei-alipay-subscriptions` | Configure agreement payments | Dependency/provider callout, settings panel; admin sign shortcut only in sandbox |

The fallback single-order reconciliation page follows the same shell and panel pattern but is not shown when the full reconciliation module is active.

## 6. Interaction rules

- **Initial load:** server-render a complete usable page; JavaScript enhances confirmation and dirty-state behavior.
- **Save:** disable the unchanged feature-centre Save button; other forms may keep native WordPress behavior. After POST, redirect where practical to prevent resubmission.
- **Retry:** disable the triggering control while the request runs, retain a textual progress label and show server feedback. Retry never changes identifiers.
- **Filtering:** use GET, sanitize to a fixed allow-list and keep the correct page slug.
- **Pagination:** preserve the active filter in page links.
- **External links:** HTTPS only, `target="_blank"` requires `rel="noopener noreferrer"`.
- **Copy:** copying callback URLs is a local action; never expose or copy secret material.
- **Failure:** errors identify the failed operation without printing private keys, full provider payloads or customer personal data.

## 7. Accessibility and localization

- Meet WCAG 2.2 AA contrast for text, focus and state indicators.
- Preserve native keyboard behavior; every custom switch remains a checkbox.
- Use `:focus-visible` and never remove WordPress focus outlines without a replacement.
- Use headings in order, one page `h1`, and table header cells for tabular data.
- Use live regions only for concise state changes such as unsaved changes.
- English source strings are translation keys; `zh_CN` supplies complete user-facing Chinese. Product names and protocol identifiers are not translated inconsistently.
- Avoid concatenated sentence fragments that produce unnatural translations. Translator comments are mandatory for placeholders.

## 8. Responsive behavior

- At the WordPress `782px` breakpoint, native form-table rows stack and navigation tabs may scroll horizontally.
- Tables scroll within their own container; the whole WordPress administration page must not gain horizontal overflow.

## 9. Implementation boundaries

- Shared styles: `css/admin/design-system.css`.
- Shared progressive enhancement: `js/admin/design-system.js`.
- Feature-centre specialization: `css/admin/features.css` and `js/admin/features.js`.
- Design evidence: `docs/design/woopayments-admin-design-reference.md` and `docs/design/woopayments-admin-tokens.json`.
- Shared asset loading is restricted to the Wei Alipay top-level screen and its child pages.
- Do not add a frontend dependency, React runtime or remote asset for administration styling.

## 10. Review checklist

For every new or changed page verify:

1. Correct route, capability, nonce and redirect behavior.
2. One clear task per panel and no duplicate page title.
3. State label, empty state, loading state and error state.
4. Keyboard focus, label association, heading order and color-independent status.
5. WordPress `782px` responsive behavior and wide-table containment.
6. English source/Chinese translation completeness.
7. No secret, full callback payload or customer identifier in the DOM, log or screenshot.
8. No real Alipay request during automated or visual tests.
9. PHPCS security sniffs, PHPStan, contract tests and release-package Plugin Check classification.
10. Visual verification in the supported WordPress/WooCommerce matrix with sample counts; zero samples are not PASS.
