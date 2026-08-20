#!/usr/bin/env python3
"""Extract a deterministic admin-design inventory from a WooPayments build."""

import argparse
import hashlib
import json
import re
from collections import Counter
from pathlib import Path

ARTIFACTS = (
    "dist/settings.css",
    "dist/index.css",
    "dist/wcpay-overview.css",
    "dist/plugins-page.css",
)

PATTERNS = {
    "colors": re.compile(r"(?<![-\w])(?:#[0-9a-fA-F]{3,8}|rgba?\([^;{}]+\)|var\(--[\w-]+(?:,[^)]+)?\))"),
    "font_sizes": re.compile(r"font-size\s*:\s*([^;{}]+)"),
    "line_heights": re.compile(r"line-height\s*:\s*([^;{}]+)"),
    "font_weights": re.compile(r"font-weight\s*:\s*([^;{}]+)"),
    "border_radii": re.compile(r"border-radius\s*:\s*([^;{}]+)"),
    "box_shadows": re.compile(r"box-shadow\s*:\s*([^;{}]+)"),
    "durations": re.compile(r"(?:animation|transition)(?:-duration)?\s*:\s*([^;{}]+)"),
    "breakpoints": re.compile(r"@media\s*\((?:min|max)-width\s*:\s*([^)]+)\)"),
}


def stable_counts(values):
    counts = Counter(value.strip() for value in values if value.strip())
    return [
        {"value": value, "occurrences": count}
        for value, count in sorted(counts.items(), key=lambda item: (-item[1], item[0]))
    ]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("plugin", type=Path, help="WooPayments plugin directory")
    parser.add_argument("output", type=Path, help="JSON output path")
    args = parser.parse_args()

    inventory = {name: [] for name in PATTERNS}
    artifacts = []
    combined = []
    for relative in ARTIFACTS:
        path = args.plugin / relative
        if not path.is_file():
            raise SystemExit(f"missing WooPayments artifact: {path}")
        raw = path.read_bytes()
        text = raw.decode("utf-8")
        combined.append(text)
        artifacts.append(
            {
                "path": relative,
                "bytes": len(raw),
                "sha256": hashlib.sha256(raw).hexdigest(),
            }
        )

    css = "\n".join(combined)
    for name, pattern in PATTERNS.items():
        inventory[name] = stable_counts(pattern.findall(css))

    spacing = re.findall(
        r"(?:margin|padding|gap|top|right|bottom|left)(?:-[\w-]+)?\s*:\s*([^;{}]+)", css
    )
    spacing_atoms = []
    for value in spacing:
        spacing_atoms.extend(re.findall(r"-?(?:\d*\.)?\d+(?:px|rem|em|%)|\b0\b", value))
    inventory["spacing_atoms"] = stable_counts(spacing_atoms)

    plugin_header = (args.plugin / "woocommerce-payments.php").read_text(encoding="utf-8", errors="replace")
    version_match = re.search(r"^\s*\*?\s*Version:\s*(\S+)", plugin_header, re.MULTILINE)
    version = version_match.group(1) if version_match else "unknown"

    output = {
        "schema": "https://weixiaoduo.com/schemas/woopayments-admin-design-inventory-v1.json",
        "source": {
            "product": "WooPayments",
            "version": version,
            "scope": "compiled WordPress admin settings, overview and plugins-page CSS",
            "artifacts": artifacts,
        },
        "semantic_tokens_adopted_by_wei_alipay": {
            "layout_max_width": "1032px",
            "layout_horizontal_padding": "56px",
            "spacing_scale": ["4px", "8px", "12px", "16px", "24px", "32px"],
            "text": "#1e1e1e",
            "text_secondary": "#646970",
            "surface": "#fff",
            "surface_subtle": "#f6f7f7",
            "border": "#e0e0e0",
            "accent": "var(--wp-components-color-accent, var(--wp-admin-theme-color, #007cba))",
            "success": {"background": "#edfaef", "text": "#005c12"},
            "warning": {"background": "#fcf9e8", "text": "#614200"},
            "danger": {"background": "#fcf0f1", "text": "#8a2424"},
            "chip": {"font_size": "13px", "padding": "4px 8px", "radius": "3px"},
            "row": {"padding": "24px", "title": "600 14px/20px", "description": "400 13px/20px"},
            "card_shadow": "0 2px 6px rgba(0, 0, 0, .05)",
            "responsive_stack": "800px",
            "compact_mobile": "600px",
            "motion": "150ms; disabled with prefers-reduced-motion",
        },
        "raw_inventory": inventory,
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(output, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
