# WooPayments admin design reference

This document records the design evidence used for the Wei Alipay feature centre. It is a reference, not a code fork. Wei Alipay uses native WordPress/WooCommerce controls and its own namespaced CSS; it does not copy WooPayments bundles, React components, class names or product identity.

## Evidence and scope

The inspected package is the locally installed WooPayments 11.0.0 at `wp-content/plugins/woocommerce-payments`. The reproducible extractor reads these compiled administration surfaces:

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `dist/settings.css` | 77,785 | `45869ae6f001b375818d6a759f829f2bc3f8b73c47ee92269cdb23111715fc1d` |
| `dist/index.css` | 20,924 | `27f4796142e0cc758a06c8a77aa10e8fb591e8b75c1313ff44c8c36495461aed` |
| `dist/wcpay-overview.css` | 24,822 | `538443bb5805b69859ee1b1b595e6ced00feb7b1e2417910f4a77ad0fc19a675` |
| `dist/plugins-page.css` | 1,090 | `2214169c9c519abbde10f3a04c3d00111b7ee0933b36201b6907952b4db7fd16` |

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

## Native-first adoption boundary

The inventory is evidence, not a mandate to recreate WooPayments. `DESIGN.md` is the implementation contract. Wei Alipay first uses public WordPress admin classes and inherited styles. Its tokens are aliases for the small number of gaps that WordPress core does not cover.

| Role | Value |
| --- | --- |
| Content width | `1180px` maximum for operational tables |
| Supplemental spacing | `4, 8, 12, 16, 20, 24px` |
| Primary / secondary text | `#1d2327` / `#646970` |
| Surface / subtle surface | `#fff` / `#f6f7f7` |
| Border | WordPress-aligned `#c3c4c7` fallback |
| Accent | `--wp-admin-theme-color`, then `#2271b1` |
| Responsive | WordPress admin breakpoint at `782px` |
| Motion | inherited; removed for `prefers-reduced-motion` |

Native components used are `wrap`, `nav-tab-wrapper`, `nav-tab`, `notice`, `card`, `form-table`, `wp-list-table`, `widefat`, `striped`, `tablenav`, `button`, `button-primary` and `button-link-delete`. `css/admin/design-system.css` adds only a wide-card escape, horizontal table overflow, compact status text, empty-state spacing, inline form layout, dirty-state copy and reduced-motion handling. The feature selector remains a deliberate custom screen in `css/admin/features.css` because WordPress has no native module-toggle list.

## Adopted interaction model

1. **Progressive disclosure.** The main Alipay gateway is always present. Optional payment methods and operational tools are off unless an explicit existing setting is migrated or an operator enables them.
2. **Two-step payment activation.** A feature toggle controls code registration. The corresponding WooCommerce gateway setting controls checkout availability. The page explains both states rather than silently enabling a payment method.
3. **Settings section layout.** Operational pages use native WordPress headings, cards, form tables, notices and list tables. Only the feature selector uses the studied two-column module layout.
4. **Semantic state text.** `Active/Enabled`, `Inactive`, `Action needed` and `Dependency missing` always remain visible as text; color is not the only signal.
5. **Immediate local feedback, explicit persistence.** Toggling updates the status preview, marks the form dirty and enables the Save button. A navigation guard protects unsaved changes. The server remains authoritative after POST.
6. **Dependency gating.** A missing WooCommerce Subscriptions dependency disables the relevant toggle and explains how to resolve it. Existing stored preference is preserved rather than silently erased.
7. **Accessible native form behavior.** Checkboxes remain actual form controls with screen-reader labels, keyboard focus rings and no JavaScript requirement for saving.
8. **Scoped loading.** Shared enhancement assets load only on Wei Alipay pages; feature-centre specialization loads only on `toplevel_page_wei-alipay`. Disabled modules do not register payment gateways, admin pages or their normal runtime hooks.
9. **Safe disablement.** Disabling a feature preserves settings, financial history and order metadata. Only module-owned pending schedules are unscheduled.
10. **Confirmation boundary.** High-impact actions name their consequences and use a confirmation prompt; cancel remains the safe default. Feature toggles preserve data and need no prompt.

## Deliberately not copied

- WooPayments private React components and compiled JavaScript.
- WooPayments trademarks, icons, onboarding flows or account-state assumptions.
- The WooCommerce core drag-and-drop payment ordering implementation.
- Skeleton loaders: this server-rendered form has no asynchronous initial load.
- Blanket CSS variables or arbitrary one-off raw values that do not serve this page.

## Wei Alipay feature contract

The allow-list is stored in `wei_alipay_features`. Stable gateway IDs, existing gateway settings, order metadata, callbacks and schedules are not renamed. First migration derives an enabled feature only from an explicit existing `enabled=yes`, feature-specific `yes`, or a non-empty operational settings record; absence means off. Turning a feature off never deletes its settings or transaction data.
