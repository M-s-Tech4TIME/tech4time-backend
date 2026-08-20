#!/usr/bin/env python3
"""
Assemble a page from the shared templates plus a per-page <main> block.

Build tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/assemble_page.py <spec.json>

WHY
The project forbids runtime partials, so the head, header, footer and script
tags are pasted into all thirteen pages. Pasting by hand is what makes them
drift; this composes them from tools/templates/ so they are byte-identical by
construction and tools/check_shared_markup.py passes on the first try.

IMPORTANT
This is for creating a page, not for maintaining one. Once a page exists, edit
the file directly — re-running the tool would discard any hand edits made to its
<main>. Changes to the shared blocks are made in tools/templates/ and then
propagated to every page.

SPEC FORMAT (JSON)
    {
      "out":         "pages/about/index.html",   relative to the repo root
      "main":        "/abs/path/to/main.html",   the page's <main> element
      "title":       "…",                        <title> and og:title
      "og_title":    "…",                        optional; defaults to title
      "description": "…",                        150-160 chars
      "canonical":   "https://tech4time.bd/pages/about/",
      "og_type":     "website",                  optional
      "page_css":    "about",                    optional; assets/css/pages/<name>.css
      "nav_current": "/pages/about/",            optional; href to mark aria-current
      "extra_jsonld": "…"                        optional; raw <script> block(s)
    }
"""

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TPL = ROOT / "tools" / "templates"


def read(name: str) -> str:
    return (TPL / name).read_text().rstrip("\n")


def build(spec: dict) -> str:
    head = read("head.html")
    head = (
        head.replace("{{TITLE}}", spec["title"])
        .replace("{{DESCRIPTION}}", spec["description"])
        .replace("{{CANONICAL}}", spec["canonical"])
        .replace("{{OG_TITLE}}", spec.get("og_title", spec["title"]))
        .replace("{{OG_TYPE}}", spec.get("og_type", "website"))
    )

    leftover = re.findall(r"\{\{[A-Z_]+\}\}", head)
    if leftover:
        raise SystemExit(f"Unfilled placeholders in head: {sorted(set(leftover))}")

    # Page stylesheet goes after animations.css, keeping the cascade order.
    if spec.get("page_css"):
        anchor = '<link rel="stylesheet" href="/assets/css/animations.css">'
        head = head.replace(
            anchor,
            anchor + f'\n<link rel="stylesheet" href="/assets/css/pages/{spec["page_css"]}.css">',
        )

    header = read("header.html")
    if spec.get("nav_current"):
        href = spec["nav_current"]
        needle = f'<a class="nav-link" href="{href}">'
        if needle not in header:
            raise SystemExit(f"nav_current href not found in header template: {href}")
        header = header.replace(needle, f'<a class="nav-link" href="{href}" aria-current="page">', 1)

    parts = [
        "<!DOCTYPE html>",
        '<html lang="en">',
        "<head>",
        head,
        "",
        read("jsonld-base.html"),
    ]

    if spec.get("extra_jsonld"):
        parts += ["", spec["extra_jsonld"].rstrip("\n")]

    parts += [
        "</head>",
        "",
        '<body class="page">',
        "",
        header,
        "",
        Path(spec["main"]).read_text().rstrip("\n"),
        "",
        read("footer.html"),
        "",
        read("scripts.html"),
        "</body>",
        "</html>",
        "",
    ]
    return "\n".join(parts)


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)

    spec = json.loads(Path(sys.argv[1]).read_text())
    out = ROOT / spec["out"]
    out.parent.mkdir(parents=True, exist_ok=True)

    if out.exists():
        print(f"NOTE: overwriting existing {spec['out']} — hand edits to its <main> are lost.")

    out.write_text(build(spec))
    print(f"wrote {spec['out']}  ({out.stat().st_size:,} bytes)")
    print("next: python3 tools/inject_icons.py && python3 tools/audit_pages.py")


if __name__ == "__main__":
    main()
