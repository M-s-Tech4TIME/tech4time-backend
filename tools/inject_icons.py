#!/usr/bin/env python3
"""
Inline each page's icon subset from the master sprite.

Build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:
    python3 tools/inject_icons.py              # every page
    python3 tools/inject_icons.py index.html   # one page
    python3 tools/inject_icons.py --check      # verify, change nothing

WHY THIS EXISTS
The obvious approach is one shared sprite file referenced across pages:

    <svg><use href="/assets/icons/sprite.svg#bug"></use></svg>

Chromium and WebKit do not resolve <use> across documents, so that renders
nothing outside Firefox. The workarounds are a JS polyfill (icons vanish
without script) or inlining. Inlining wins: no extra request, no script
dependency, and each page carries only the handful of symbols it uses -- a page
with 30 icons costs roughly 15KB before gzip, against 64KB for the full set.

Pages reference icons as same-document fragments (href="#bug"), and this script
keeps the matching <symbol> definitions in sync at the top of <body>.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SPRITE = ROOT / "assets" / "icons" / "sprite.svg"

START = "<!-- icon-sprite:start -->"
END = "<!-- icon-sprite:end -->"

USE_REF = re.compile(r'<use\s+href="#([a-z0-9-]+)"')
# Ids the page defines itself, outside the injected sprite block.
LOCAL_ID = re.compile(r'<(?!symbol\b)[a-z]+[^>]*\sid="([a-z0-9-]+)"')
SYMBOL = re.compile(r'<symbol id="([a-z0-9-]+)".*?</symbol>', re.S)
BLOCK = re.compile(re.escape(START) + r".*?" + re.escape(END), re.S)
BODY_OPEN = re.compile(r"<body[^>]*>")


def load_symbols() -> dict[str, str]:
    if not SPRITE.exists():
        raise SystemExit(
            f"Master sprite missing: {SPRITE}\nRun tools/build_icon_sprite.py first."
        )
    text = SPRITE.read_text()
    return {m.group(1): m.group(0) for m in SYMBOL.finditer(text)}


def pages() -> list[Path]:
    found = [p for p in ROOT.glob("*.html")]
    found += sorted(ROOT.glob("pages/**/*.html"))
    # The careers page is PHP, but its icon references are plain markup and
    # need the same symbols inlined as every other page.
    found += sorted(ROOT.glob("pages/**/*.php"))
    return sorted(found)


def build_block(names: list[str], symbols: dict[str, str]) -> tuple[str, list[str]]:
    missing = [n for n in names if n not in symbols]
    body = "\n".join(f"  {symbols[n]}" for n in names if n in symbols)
    block = (
        f"{START}\n"
        '<svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">\n'
        f"{body}\n"
        "</svg>\n"
        f"{END}"
    )
    return block, missing


def process(path: Path, symbols: dict[str, str], check_only: bool) -> tuple[bool, list[str]]:
    original = path.read_text()

    # Only count references outside the injected block, or the block would keep
    # pinning symbols a page no longer actually uses.
    without_block = BLOCK.sub("", original)

    # A <use href="#x"> is only an icon reference when nothing in the page
    # already defines #x. The dock draws its circuit by pointing <use> at its
    # own <defs>, which is a local reference and has no business in the sprite.
    local_ids = set(LOCAL_ID.findall(without_block))
    names = sorted(set(USE_REF.findall(without_block)) - local_ids)

    if not names:
        # Nothing to inject; drop any stale block that remains.
        updated = BLOCK.sub("", original).replace("\n\n\n", "\n\n")
        changed = updated != original
        if changed and not check_only:
            path.write_text(updated)
        return changed, []

    block, missing = build_block(names, symbols)

    if BLOCK.search(original):
        updated = BLOCK.sub(lambda _: block, original, count=1)
    else:
        match = BODY_OPEN.search(original)
        if not match:
            raise SystemExit(f"{path}: no <body> tag found")
        insert_at = match.end()
        updated = original[:insert_at] + "\n" + block + original[insert_at:]

    changed = updated != original
    if changed and not check_only:
        path.write_text(updated)

    return changed, missing


def main() -> None:
    args = [a for a in sys.argv[1:]]
    check_only = "--check" in args
    targets = [ROOT / a for a in args if not a.startswith("--")]

    symbols = load_symbols()
    files = targets or pages()

    if not files:
        print("No HTML pages yet — nothing to inject.")
        return

    changed_any = False
    problems = []

    for path in files:
        if not path.exists():
            problems.append(f"{path}: not found")
            continue

        changed, missing = process(path, symbols, check_only)
        rel = path.relative_to(ROOT)
        body = BLOCK.sub("", path.read_text())
        icons = len(set(USE_REF.findall(body)) - set(LOCAL_ID.findall(body)))

        state = "would update" if (changed and check_only) else ("updated" if changed else "up to date")
        print(f"  {rel}  — {icons} icons, {state}")

        changed_any = changed_any or changed
        for name in missing:
            problems.append(f"{rel}: no symbol '{name}' in the master sprite")

    if problems:
        print("\nPROBLEMS:")
        for p in problems:
            print(f"  {p}")
        sys.exit(1)

    if check_only and changed_any:
        print("\nIcon blocks are stale. Run: python3 tools/inject_icons.py")
        sys.exit(1)


if __name__ == "__main__":
    main()
