# WooPayments and WooCommerce admin design evidence

## Evidence boundary

This inventory was extracted from the installed packages in the WordPress Studio acceptance site, not from screenshots or a remote repository:

- WooPayments `11.0.0`: `/Users/feibisi-studio/Studio/wei-alipay-wc1101/wp-content/plugins/woocommerce-payments`
- WooCommerce `11.0.1`: `/Users/feibisi-studio/Studio/wei-alipay-wc1101/wp-content/plugins/woocommerce`

The deterministic evidence file is `docs/design/woopayments-admin-tokens.json`. It contains every selected artifact path, byte size and SHA-256 plus the complete unique-value inventory for the selected administration scope. The extractor is `scripts/extract-woopayments-admin-design.py`.

```bash
./scripts/extract-woopayments-admin-design.py \
  /Users/feibisi-studio/Studio/wei-alipay-wc1101/wp-content/plugins/woocommerce-payments \
  /Users/feibisi-studio/Studio/wei-alipay-wc1101/wp-content/plugins/woocommerce \
  docs/design/woopayments-admin-tokens.json
```

Scope: 30 WooPayments CSS artifacts, 45 WooPayments JavaScript artifacts, 27 WooCommerce CSS artifacts and 152 WooCommerce JavaScript artifacts. RTL duplicates, minified WooCommerce duplicates and checkout/cart/express frontend bundles are excluded. This is an administration design inventory, not a whole-package code audit.

## What the evidence shows

### Visual foundation

The two products converge on the WordPress component visual language rather than a separate brand-heavy dashboard:

- system UI font stack;
- primary text `#1e1e1e`, secondary text `#757575`, white surface and `#e0e0e0` structural border;
- admin theme color through `var(--wp-admin-theme-color)` for focus and selected state;
- dense 13px body/control copy with 20px line height, 16px section text and 20/24px headings;
- spacing rhythm dominated by 4, 8, 12, 16 and 24px;
- 2px and 4px control radii, 8px elevated-card radius, pill radius only for compact statuses;
- cards use either a quiet one-pixel boundary or a low-opacity layered shadow, not both at maximum strength;
- responsive transitions cluster at 600px, WordPress 782px, 960px and WooPayments content breakpoints 660/801px.

Raw occurrence counts are evidence of recurrence, not permission to select a value blindly. The semantic token map in `DESIGN.md` chooses one role for each value and rejects decorative outliers, onboarding spotlight values and private brand treatments.

### Component families

WooPayments uses WordPress component class families heavily: Button, Card, Modal, Notice, TabPanel, SelectControl, Tooltip and WooCommerce list/table patterns. WooCommerce adds ToggleControl, TextControl, CheckboxControl, Popover, Snackbar and settings layouts. Wei Alipay should therefore use:

1. WordPress/WooCommerce native HTML, settings APIs and controls where they exist.
2. A thin Wei visual composition layer for page headers, section cards, status pills, compact rows and empty states.
3. No copied WooPayments React runtime, private selectors or compiled JavaScript.

### Interaction findings

Compiled administration JavaScript contains evidence for the following repeatable contracts:

| Contract | WooPayments evidence | WooCommerce evidence | Wei Alipay rule |
| --- | ---: | ---: | --- |
| Explicit loading/busy state | 472 marker hits | 521 | Disable only the initiating control, keep its label stable, show spinner/progress text and restore on all outcomes. |
| Notice/snackbar feedback | 222 | 442 | Inline notice for page-bound results; transient snackbar only when a persistent notice would interrupt the task. |
| Keyboard/focus handling | 471 | 1,824 | Preserve tab order, Escape for dismissible overlays, Enter only where form semantics permit it, and visible focus rings. |
| URL-addressable tabs | 99 | 245 | Tabs and filters remain links or GET state; reload/back must preserve selection. |
| Search/filter | 962 | 2,992 | Debounce remote search, use fixed allow-lists and separate filter state from mutation state. |
| Unsaved changes | 95 | 160 | Mark a form dirty after a real value change; clear only after a confirmed save. |
| Confirmation | 9 | 33 | Consequential remote or destructive actions require a contextual confirmation naming the effect. |
| Clipboard | 11 | 9 | Copy only non-secret identifiers/URLs and announce success or failure. |
| Pagination | 51 | 94 | Preserve search/filter state and expose total/current page text. |
| Toggle/disclosure | 89 | 417 | A toggle changes a boolean; a disclosure only reveals content. Do not use one as the other. |
| Drag/drop | 146 | 785 | Not adopted for feature activation: ordering is not a Wei Alipay requirement and keyboard parity would add complexity. |

Counts are substring signatures in compiled artifacts; they prove presence, not exact component semantics. Implementation decisions were checked against CSS state selectors and ARIA contracts. Common ARIA evidence includes `aria-label`, `aria-describedby`, `aria-expanded`, `aria-busy`, `aria-live`, `aria-selected`, `aria-controls` and `aria-invalid`.

## Adopted semantic model

### Color roles

| Role | Alias | Evidence-aligned value |
| --- | --- | --- |
| Text strong | `--wei-color-text` | `#1e1e1e` |
| Text secondary | `--wei-color-text-secondary` | `#757575` |
| Text WordPress secondary | `--wei-color-text-wp` | `#646970` |
| Surface | `--wei-color-surface` | `#fff` |
| Surface quiet | `--wei-color-surface-subtle` | `#f6f7f7` |
| Surface hover | `--wei-color-surface-hover` | `#f0f0f0` |
| Border | `--wei-color-border` | `#e0e0e0` |
| Border strong | `--wei-color-border-strong` | `#c3c4c7` |
| Accent | `--wei-color-accent` | `var(--wp-admin-theme-color, #2271b1)` |
| Accent quiet | `--wei-color-accent-subtle` | `#f0f6fc` |
| Success text/surface | `--wei-color-success-*` | `#005c12` / `#edfaef` |
| Warning text/surface | `--wei-color-warning-*` | `#614200` / `#fcf9e8` |
| Error text/surface | `--wei-color-error-*` | `#8a2424` / `#fcf0f1` |
| Info text/surface | `--wei-color-info-*` | `#0a4b78` / `#f0f6fc` |

State color is always paired with text or an icon and must meet WCAG 2.2 AA contrast.

### Type, spacing, shape and elevation

- Type scale: 11px metadata, 12px compact label, 13px body, 14px emphasized body, 16px section title, 20px page title, 24px hero/empty-state title.
- Line-height scale: 16, 20, 24, 28 and 32px.
- Weights: 400 regular, 500 medium and 600 semibold. Avoid simulated 499 except when inherited from WordPress Design System.
- Spacing: 4, 8, 12, 16, 24, 32, 40 and 48px. The first six cover routine UI; 40/48 are page-section separation only.
- Radius: 2px small control, 4px standard control/card, 8px elevated card, 9999px status pill.
- Elevation: quiet `0 2px 6px rgba(0,0,0,.05)` and layered card elevation from WooPayments; no decorative shadow on tables or settings rows.
- Motion: 100ms control feedback, 150–200ms disclosure/fade, 300ms maximum ordinary transition. Loading animation may repeat; all animation is removed under reduced motion.

### Density and layout

- Standard page content: 1,120px maximum; operational wide tables: 1,280px maximum.
- Reading measure: 680px.
- Card padding: 24px desktop, 16px below 782px.
- Compact row minimum height: 48px; ordinary setting/feature row: 64–72px.
- Breakpoints: 1,280 wide, 960 admin navigation transition, 782 WordPress mobile, 600 narrow composition, 400 compact fallback.

## Explicit non-adoptions

- WooPayments brand purple, onboarding spotlight styling and proprietary icon font are not Wei Alipay tokens.
- WooPayments private React components, class names and copied compiled CSS/JavaScript are prohibited.
- WooCommerce draggable provider ordering is not copied into the feature centre.
- Frontend checkout/cart/express styles are outside this evidence set.
- Frequency alone never overrides WordPress accessibility, WooCommerce settings compatibility or financial-action safety.

## Verification requirements

Regeneration must be byte-stable for unchanged packages. Review must compare source versions, artifact counts and hashes before accepting a new inventory. Visual implementation is separately tested in WordPress Studio at desktop, 782px and 480px widths; a token extraction is not a visual PASS.
