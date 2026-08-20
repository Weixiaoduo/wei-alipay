# WooPayments admin design reference

This document records the design evidence used for the Wei Alipay feature centre. It is a reference, not a code fork. Wei Alipay uses native WordPress/WooCommerce controls and its own namespaced CSS; it does not copy WooPayments bundles, React components, class names or product identity.

## Evidence and scope

The inspected package is the locally installed WooPayments 11.0.0 at `wp-content/plugins/woocommerce-payments`. The reproducible extractor reads these compiled administration surfaces:

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `dist/settings.css` | 77,785 | `45869ae6f001d81d8534f33eed182d870739d28bd23a6498ab5b6de836c9493f` |
| `dist/index.css` | 20,924 | `27f4796142e0ab4e010d30d725a2c43a8a6d09249e9128470b7542f754d01c61` |
| `dist/wcpay-overview.css` | 24,822 | `538443bb5805cb0bab501bc0248fc1f85ccd342417634437603862380d9ebf06` |
| `dist/plugins-page.css` | 1,090 | `2214169c9c512f4c597a0d7b2764fe2f2ab528dc6b1979b0a6538666155121de` |

`docs/design/woopayments-admin-tokens.json` is the full sorted inventory of colors, typography, spacing atoms, radii, shadows, breakpoints and motion values found in those four files. Rebuild it with:

```bash
./scripts/extract-woopayments-admin-design.py \
  /path/to/wp-content/plugins/woocommerce-payments \
  docs/design/woopayments-admin-tokens.json
```

The package source maps also expose the original component paths used to verify interaction structure:

- `dist/chunks/wcpay-multi-currency-setup.js.map` SHA-256 `03d2ca7a91c8ca4237fcd648143a603830238fcac49a118acbbf96fc1602024e`: `settings-layout`, `settings-section` and `confirmation-modal`.
- `dist/chunks/wcpay-fraud-protection.js.map` SHA-256 `13dd9d9d9344cfc329f0f46f3ef10247e47225aaa2368db6a273776336ea4211`: rule card, rule toggle, settings layout and settings section.
- `dist/chunks/wcpay-capital.js.map` SHA-256 `a32ddaf42b08c06d970547bcda88fb311e2810c5136a50929b6ce7eefe76d369`: semantic chip component.

The draggable payment-method list shown by WooCommerce is primarily a WooCommerce core Payments screen. It must not be described as a WooPayments-only component. Wei Alipay controls whether its optional gateways are registered into that screen; WooCommerce owns the list shell and ordering interaction.

## Adopted tokens

Wei Alipay adopts the recurring administration values rather than every one-off raw value:

| Role | Value |
| --- | --- |
| Content width | `1032px` |
| Main spacing | `4, 8, 12, 16, 24, 32px` |
| Primary / secondary text | `#1e1e1e` / `#646970` |
| Surface / subtle surface | `#fff` / `#f6f7f7` |
| Border | `#e0e0e0` |
| Accent | WordPress component accent, then admin theme color, then `#007cba` |
| Success | `#edfaef` / `#005c12` |
| Warning | `#fcf9e8` / `#614200` |
| Danger | `#fcf0f1` / `#8a2424` |
| Chip | `13px`, `4px 8px`, `3px` radius |
| Row | `24px` padding; `600 14px/20px` title; `400 13px/20px` description |
| Card | `4px` radius; `0 2px 6px rgba(0,0,0,.05)` shadow |
| Responsive | two-column above `800px`, compact treatment below `600px` |
| Motion | `150ms`; removed for `prefers-reduced-motion` |

All implementations live under the `wei-alipay-*` namespace in `css/admin/features.css`. This avoids coupling to private WooPayments selectors.

## Adopted interaction model

1. **Progressive disclosure.** The main Alipay gateway is always present. Optional payment methods and operational tools are off unless an explicit existing setting is migrated or an operator enables them.
2. **Two-step payment activation.** A feature toggle controls code registration. The corresponding WooCommerce gateway setting controls checkout availability. The page explains both states rather than silently enabling a payment method.
3. **Settings section layout.** Each group has a short explanatory column and a card of controls. It stacks at narrow widths.
4. **Semantic state chips.** `Active/Enabled`, `Inactive`, `Action needed` and `Dependency missing` use semantic color pairs and text; color is not the only signal.
5. **Immediate local feedback, explicit persistence.** Toggling updates the status preview, marks the form dirty and enables the Save button. A navigation guard protects unsaved changes. The server remains authoritative after POST.
6. **Dependency gating.** A missing WooCommerce Subscriptions dependency disables the relevant toggle and explains how to resolve it. Existing stored preference is preserved rather than silently erased.
7. **Accessible native form behavior.** Checkboxes remain actual form controls with screen-reader labels, keyboard focus rings and no JavaScript requirement for saving.
8. **Scoped loading.** Feature-centre assets load only on `toplevel_page_wei-alipay`. Disabled modules do not register payment gateways, admin pages or their normal runtime hooks.
9. **Safe disablement.** Disabling a feature preserves settings, financial history and order metadata. Only module-owned pending schedules are unscheduled.
10. **Confirmation boundary.** High-impact future actions such as deleting retained configuration or rotating credentials should use an explicit confirmation modal with consequences, cancel as the safe default and right-aligned actions. The current feature toggles do not delete data, so no modal is needed.

## Deliberately not copied

- WooPayments private React components and compiled JavaScript.
- WooPayments trademarks, icons, onboarding flows or account-state assumptions.
- The WooCommerce core drag-and-drop payment ordering implementation.
- Skeleton loaders: this server-rendered form has no asynchronous initial load.
- Blanket CSS variables or arbitrary one-off raw values that do not serve this page.

## Wei Alipay feature contract

The allow-list is stored in `wei_alipay_features`. Stable gateway IDs, existing gateway settings, order metadata, callbacks and schedules are not renamed. First migration derives an enabled feature only from an explicit existing `enabled=yes`, feature-specific `yes`, or a non-empty operational settings record; absence means off. Turning a feature off never deletes its settings or transaction data.
