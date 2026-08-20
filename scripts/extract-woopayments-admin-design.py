#!/usr/bin/env python3
"""Extract reproducible WooPayments and WooCommerce admin design evidence.

The extractor reads shipped artifacts only. It records values, component families
and interaction signatures for an independent Wei Alipay implementation.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
from collections import Counter
from pathlib import Path

VALUE_PATTERNS = {
    "colors": re.compile(r"(?<![-\w])(?:#[0-9a-fA-F]{3,8}|rgba?\([^;{}]+\)|hsla?\([^;{}]+\)|var\(--[\w-]+(?:,[^)]+)?\))"),
    "font_families": re.compile(r"font-family\s*:\s*([^;{}]+)"),
    "font_sizes": re.compile(r"font-size\s*:\s*([^;{}]+)"),
    "line_heights": re.compile(r"line-height\s*:\s*([^;{}]+)"),
    "font_weights": re.compile(r"font-weight\s*:\s*([^;{}]+)"),
    "border_widths": re.compile(r"border(?:-[\w-]+)?-width\s*:\s*([^;{}]+)"),
    "border_radii": re.compile(r"border-radius\s*:\s*([^;{}]+)"),
    "box_shadows": re.compile(r"box-shadow\s*:\s*([^;{}]+)"),
    "durations": re.compile(r"(?:animation|transition)(?:-duration)?\s*:\s*([^;{}]+)"),
    "easings": re.compile(r"(?:animation|transition)-timing-function\s*:\s*([^;{}]+)"),
    "breakpoints": re.compile(r"@media\s*\((?:min|max)-width\s*:\s*([^)]+)\)"),
    "z_indices": re.compile(r"z-index\s*:\s*([^;{}]+)"),
}
INTERACTION_MARKERS = {
    "api_fetch": ("apiFetch", "wp.apiFetch", "/wp-json/"),
    "async_state": ("isLoading", "isBusy", "loading", "spinner"),
    "confirmation": ("confirm(", "ConfirmationModal", "confirmModal", "isConfirm"),
    "clipboard": ("clipboard", "writeText", "copyToClipboard"),
    "debounce": ("debounce", "useDebounce"),
    "drag_and_drop": ("drag", "drop", "sortable", "dnd"),
    "focus_keyboard": ("focus", "keydown", "keyup", "Escape", "Enter"),
    "navigation_tabs": ("TabPanel", "tabIndex", "aria-selected", 'role:"tab"'),
    "notices_snackbar": ("Snackbar", "Notice", "createNotice", "removeNotice"),
    "pagination": ("Pagination", "perPage", "totalPages", "pageSize"),
    "search_filter": ("SearchControl", "searchTerm", "filter", "query"),
    "toggle_disclosure": ("ToggleControl", "Collapsible", "expand", "isOpen"),
    "unsaved_changes": ("beforeunload", "isDirty", "dirty", "unsaved"),
}
COMPONENT_FAMILIES = (
    "components-button", "components-card", "components-checkbox-control", "components-dropdown",
    "components-form-toggle", "components-modal", "components-notice", "components-panel",
    "components-popover", "components-search-control", "components-select-control", "components-snackbar",
    "components-spinner", "components-tab-panel", "components-text-control", "components-toggle-control",
    "components-tooltip", "woocommerce-admin", "woocommerce-card", "woocommerce-list",
    "woocommerce-settings", "woocommerce-table",
)

def stable_counts(values: list[str]) -> list[dict[str, object]]:
    counts = Counter(value.strip() for value in values if value.strip())
    return [{"value": value, "occurrences": count} for value, count in sorted(counts.items(), key=lambda item: (-item[1], item[0]))]

def artifact(path: Path, root: Path) -> dict[str, object]:
    raw = path.read_bytes()
    return {"path": path.relative_to(root).as_posix(), "bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}

def plugin_version(header: Path) -> str:
    text = header.read_text(encoding="utf-8", errors="replace")
    match = re.search(r"^\s*\*?\s*Version:\s*(\S+)", text, re.MULTILINE)
    return match.group(1) if match else "unknown"

def admin_artifacts(woopayments: Path, woocommerce: Path) -> dict[str, list[Path]]:
    wcpay_css = sorted(path for path in (woopayments / "dist").glob("*.css") if not path.name.endswith("-rtl.css") and not any(term in path.stem for term in ("checkout", "cart", "express", "woopay")))
    wcpay_css.extend(sorted(path for path in (woopayments / "assets/css").glob("*.css") if not path.name.endswith(".rtl.css")))
    wcpay_js = sorted(path for path in (woopayments / "dist").glob("*.js") if not any(term in path.stem for term in ("checkout", "cart", "express", "woopay", "frontend")))
    wcpay_js.extend(sorted((woopayments / "dist/chunks").glob("*.js")))
    wc_css_names = {"admin.css", "brands-admin.css", "dashboard.css", "forms.css", "menu.css", "network-order-widget.css", "order-review.css", "privacy.css", "select2.css", "wc-setup.css"}
    wc_css = sorted(path for path in (woocommerce / "assets/css").glob("*.css") if path.name in wc_css_names)
    wc_css.extend(sorted((woocommerce / "assets/client/admin").glob("**/style.css")))
    wc_js = [path for path in sorted((woocommerce / "assets/js/admin").glob("*.js")) if not path.name.endswith(".min.js")]
    wc_js.extend(path for path in sorted((woocommerce / "assets/client/admin").glob("**/*.js")) if not path.name.endswith(".min.js"))
    return {"woopayments_css": wcpay_css, "woopayments_js": wcpay_js, "woocommerce_css": wc_css, "woocommerce_js": wc_js}

def css_inventory(paths: list[Path]) -> dict[str, object]:
    css = "\n".join(path.read_text(encoding="utf-8", errors="replace") for path in paths)
    inventory: dict[str, object] = {name: stable_counts(pattern.findall(css)) for name, pattern in VALUE_PATTERNS.items()}
    spacing_atoms: list[str] = []
    for value in re.findall(r"(?:margin|padding|gap|top|right|bottom|left)(?:-[\w-]+)?\s*:\s*([^;{}]+)", css):
        spacing_atoms.extend(re.findall(r"-?(?:\d*\.)?\d+(?:px|rem|em|%)|\b0\b", value))
    inventory["spacing_atoms"] = stable_counts(spacing_atoms)
    custom_properties = re.findall(r"(?<![-\w])(--[\w-]+)\s*:\s*([^;{}]+)", css)
    inventory["custom_properties"] = [{"name": name, "value": value, "occurrences": count} for (name, value), count in sorted(Counter((name.strip(), value.strip()) for name, value in custom_properties).items(), key=lambda item: (item[0][0], item[0][1]))]
    class_names = re.findall(r"\.([a-zA-Z_][\w-]+)", css)
    inventory["component_families"] = [{"family": family, "occurrences": sum(1 for name in class_names if name.startswith(family))} for family in COMPONENT_FAMILIES if any(name.startswith(family) for name in class_names)]
    inventory["state_selectors"] = stable_counts(re.findall(r":(hover|focus-visible|focus|active|disabled|checked|invalid|empty|first-child|last-child)\b", css))
    inventory["attribute_contracts"] = stable_counts(re.findall(r"\[(aria-[\w-]+|data-[\w-]+|disabled|hidden|role)(?:[~|^$*]?=[^\]]+)?\]", css))
    return inventory

def js_inventory(paths: list[Path]) -> dict[str, object]:
    combined = "\n".join(path.read_text(encoding="utf-8", errors="replace") for path in paths)
    signatures = []
    for name, markers in INTERACTION_MARKERS.items():
        evidence = [{"marker": marker, "occurrences": combined.count(marker)} for marker in markers]
        signatures.append({"interaction": name, "occurrences": sum(item["occurrences"] for item in evidence), "evidence": [item for item in evidence if item["occurrences"]]})
    return {"interaction_signatures": signatures, "aria_contracts": stable_counts(re.findall(r"aria-[a-z-]+", combined)), "wordpress_packages": stable_counts(re.findall(r"wp\.([a-zA-Z][\w]*)", combined))}

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("woopayments", type=Path)
    parser.add_argument("woocommerce", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    groups = admin_artifacts(args.woopayments, args.woocommerce)
    group_roots = {"woopayments_css": args.woopayments, "woopayments_js": args.woopayments, "woocommerce_css": args.woocommerce, "woocommerce_js": args.woocommerce}
    output = {
        "schema": "https://weixiaoduo.com/schemas/woo-admin-design-evidence-v2.json",
        "sources": {
            "woopayments": {"version": plugin_version(args.woopayments / "woocommerce-payments.php"), "path_at_extraction": str(args.woopayments)},
            "woocommerce": {"version": plugin_version(args.woocommerce / "woocommerce.php"), "path_at_extraction": str(args.woocommerce)},
        },
        "scope": {
            "rule": "Shipped non-RTL administration CSS and non-minified or chunk administration JavaScript; frontend checkout/cart assets excluded.",
            "artifact_counts": {name: len(paths) for name, paths in groups.items()},
            "artifacts": {name: [artifact(path, group_roots[name]) for path in paths] for name, paths in groups.items()},
        },
        "semantic_contract_for_wei_alipay": {
            "foundation": "WordPress and WooCommerce controls, accessibility and page routing",
            "visual_language": "WooPayments density, surfaces, status treatments and progressive disclosure",
            "implementation": "Independent --wei-* aliases; no copied WooPayments JavaScript, React components or private selectors",
            "interaction_priority": ["server-rendered baseline", "progressive disclosure", "explicit loading and disabled state", "inline validation", "notice or snackbar feedback", "confirmation for consequential actions", "URL-addressable tabs and filters", "keyboard and reduced-motion support"],
        },
        "inventories": {
            "woopayments_css": css_inventory(groups["woopayments_css"]),
            "woocommerce_css": css_inventory(groups["woocommerce_css"]),
            "woopayments_js": js_inventory(groups["woopayments_js"]),
            "woocommerce_js": js_inventory(groups["woocommerce_js"]),
        },
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(output, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

if __name__ == "__main__":
    main()
