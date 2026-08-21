#!/usr/bin/env python3
"""
Build the self-hosted SVG icon sprite from Font Awesome Free metadata.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/build_icon_sprite.py

Why a sprite: the NextJS source pulls Font Awesome's full webfont from a CDN on
every page. This project forbids render-blocking third-party CSS, so the icons
the site actually uses are baked into one same-origin sprite instead. Icons are
referenced as:

    <svg class="icon" aria-hidden="true"><use href="/assets/icons/sprite.svg#bug"></use></svg>

and inherit `currentColor`, so they pick up the silver accent for free.

Name resolution: the source markup uses Font Awesome 5 names (fa-shield-alt,
fa-times, fa-search…). Font Awesome 6 renamed many of these, keeping the old
names only as aliases, so names are resolved through the official icons.json
alias index rather than by guesswork.
"""

import json
import subprocess
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "assets" / "icons" / "sprite.svg"
CACHE = Path("/tmp/claude-1000/-home-alsechemist-CodeSpace-tech4time-website"
             "/00510023-1809-4d72-ba7e-f02f1376e333/scratchpad/fa-icon-families.json")

FA_VERSION = "6.5.2"
# The npm package ships icon-families.json (not icons.json); it carries the same
# per-icon svg path data plus the FA5 -> FA6 alias names.
METADATA_URL = (
    f"https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@{FA_VERSION}"
    "/metadata/icon-families.json"
)

# Source of truth for which icons the site needs: every fa-* token in the
# NextJS .tsx files, minus animation modifiers.
NEXTJS_SRC = Path("/home/alsechemist/CodeSpace/Tech4TIME-web-ui/src")
NOT_ICONS = {"spin"}  # fa-spin is an animation class, not an icon

# Icons whose style is not "solid" in the source markup.
STYLE_OVERRIDES = {
    "github": "brands",
    "linkedin": "brands",
    "calendar": "regular",
}
STYLE_ORDER = ["solid", "brands", "regular"]

# Names in the source that Font Awesome Free does not provide. fa-shield-cross
# is a Pro-only icon, so it already renders blank on the live NextJS site; the
# sprite keeps the id the markup expects and points it at a Free equivalent.
SUBSTITUTIONS = {
    "shield-cross": "shield",
}

# Symbols this project draws itself, because Font Awesome Free has no
# equivalent. They are appended after the generated ones and use the same
# currentColor fill, so they tint like every other icon.
#
# grid-dots: the dock's menu button. Font Awesome's "th" is a grid of filled
# squares; the design calls for nine dots, which FA Free does not carry in any
# style. Drawn on a 24-unit grid so the spacing stays even at any size.
# pause / play: the slideshow control. Font Awesome Free does carry both, but
# only as glyphs sized against its own 448-unit box; drawn here on the same
# 24-unit grid as grid-dots they line up with each other and with the arrows
# beside them at any size, which is what matters for a row of controls.
CUSTOM_SYMBOLS = {
    "grid-dots": (
        '<symbol id="grid-dots" viewBox="0 0 24 24">'
        + "".join(
            f'<circle cx="{x}" cy="{y}" r="2.1"/>'
            for y in (5, 12, 19)
            for x in (5, 12, 19)
        )
        + "</symbol>"
    ),
    "pause": (
        '<symbol id="pause" viewBox="0 0 24 24">'
        '<rect x="6" y="4.5" width="4" height="15" rx="1.4"/>'
        '<rect x="14" y="4.5" width="4" height="15" rx="1.4"/>'
        "</symbol>"
    ),
    "play": (
        '<symbol id="play" viewBox="0 0 24 24">'
        '<path d="M7.5 4.9v14.2a1 1 0 0 0 1.53.85l11.2-7.1a1 1 0 0 0 0-1.7L9.03 4.05'
        'A1 1 0 0 0 7.5 4.9z"/>'
        "</symbol>"
    ),
}


def collect_names() -> list[str]:
    out = subprocess.run(
        ["grep", "-rhoE", "fa-[a-z0-9-]+", "--include=*.tsx", str(NEXTJS_SRC)],
        check=True,
        capture_output=True,
        text=True,
    ).stdout
    names = {line[3:] for line in out.splitlines() if line.startswith("fa-")}
    return sorted(names - NOT_ICONS)


def load_metadata() -> dict:
    if not CACHE.exists():
        print(f"Downloading Font Awesome {FA_VERSION} metadata…")
        CACHE.parent.mkdir(parents=True, exist_ok=True)
        with urllib.request.urlopen(METADATA_URL) as r:
            CACHE.write_bytes(r.read())
    print(f"metadata: {CACHE} ({CACHE.stat().st_size:,} bytes)")
    return json.loads(CACHE.read_text())


def build_alias_index(meta: dict) -> dict[str, str]:
    """Map every canonical name AND legacy alias to its canonical key."""
    index = {}
    for key, entry in meta.items():
        index.setdefault(key, key)
        for alias in entry.get("aliases", {}).get("names", []):
            index.setdefault(alias, key)
    return index


def styles_for(entry: dict) -> dict:
    """The classic-family style map: {"solid": {...}, "brands": {...}, ...}."""
    return entry.get("svgs", {}).get("classic", {})


def pick_style(svgs: dict, requested: str | None) -> str | None:
    if requested and requested in svgs:
        return requested
    for style in STYLE_ORDER:
        if style in svgs:
            return style
    return next(iter(svgs), None)


def main() -> None:
    names = collect_names()
    print(f"{len(names)} icon names found in the NextJS source")

    meta = load_metadata()
    index = build_alias_index(meta)

    symbols, missing, renamed, substituted = [], [], [], []

    for name in names:
        lookup = SUBSTITUTIONS.get(name, name)
        if lookup != name:
            substituted.append((name, lookup))

        key = index.get(lookup)
        if key is None:
            missing.append(name)
            continue
        if key != lookup:
            renamed.append((name, key))

        svgs = styles_for(meta[key])
        style = pick_style(svgs, STYLE_OVERRIDES.get(name))
        if style is None:
            missing.append(name)
            continue

        svg = svgs[style]
        view_box = " ".join(str(v) for v in svg["viewBox"])
        # Keep the sprite id as the name the markup already uses, so the source
        # markup ports across unchanged.
        symbols.append(
            f'<symbol id="{name}" viewBox="{view_box}">'
            f'<path d="{svg["path"]}"/>'
            f"</symbol>"
        )

    symbols.extend(CUSTOM_SYMBOLS[name] for name in sorted(CUSTOM_SYMBOLS))

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(
        "<!-- Tech4TIME icon sprite. Generated by tools/build_icon_sprite.py -->\n"
        f"<!-- Source: Font Awesome Free {FA_VERSION} (CC BY 4.0 icons, "
        "https://fontawesome.com/license/free) -->\n"
        "<!-- Plus this project's own symbols; see CUSTOM_SYMBOLS in the "
        "generator. -->\n"
        '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">\n'
        + "\n".join(symbols)
        + "\n</svg>\n"
    )

    print(f"\nwrote {OUT.relative_to(ROOT)}  "
          f"({len(symbols)} symbols, {OUT.stat().st_size:,} bytes)")

    if renamed:
        print(f"\n{len(renamed)} FA5 names resolved through FA6 aliases:")
        for old, new in renamed:
            print(f"  {old:24s} -> {new}")

    if substituted:
        print(f"\n{len(substituted)} Pro-only names substituted with Free icons:")
        for old, new in substituted:
            print(f"  {old:24s} -> {new}")

    if missing:
        print(f"\nWARNING — {len(missing)} names had no Font Awesome match:")
        for name in missing:
            print(f"  {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
