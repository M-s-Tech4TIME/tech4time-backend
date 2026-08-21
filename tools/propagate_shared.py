#!/usr/bin/env python3
"""
Push a change in tools/templates/ out to every page.

Build tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/propagate_shared.py            # write the changes
    python3 tools/propagate_shared.py --dry-run  # list what would change

WHY
The project forbids runtime partials, so the header, footer, dock and script
tags exist as literal markup in sixteen pages. check_shared_markup.py proves
they have not drifted; it cannot put them back in step. Editing sixteen copies
by hand is what makes them drift in the first place, so this does it instead
and check_shared_markup.py verifies the result.

THE ONE THING THAT IS NOT COPIED
aria-current="page". That marker is the single legitimate per-page difference
in shared markup, and a blind copy would wipe it from every page and mark the
active link nowhere. So it is read out of each page first — as the set of
hrefs that page marks — and re-applied to the new markup afterwards. A page
with no marker, like 404, keeps none.
"""

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TEMPLATES = ROOT / "tools" / "templates"

# name -> (template file, regex for the block in a page, anchor to insert
#          before when the page does not have the block yet, optional)
#
# "optional" means a page without the anchor simply does not have that block
# and is not missing anything: the circuit belongs to the page-title band, and
# the home page and the 404 do not have one.
BLOCKS = {
    "header": (
        "header.html",
        re.compile(r'<a class="skip-link".*?</header>', re.S),
        None,
        False,
    ),
    "dock": (
        "dock.html",
        re.compile(r"<!--dock:start-->.*?<!--dock:end-->", re.S),
        re.compile(r"<!-- Deferred so nothing blocks rendering\."),
        False,
    ),
    "footer": (
        "footer.html",
        re.compile(r'<footer class="site-footer">.*?</footer>', re.S),
        None,
        False,
    ),
    "hero-circuit": (
        "hero-circuit.html",
        re.compile(r"<!--hero-circuit:start-->.*?<!--hero-circuit:end-->", re.S),
        re.compile(r'<div class="container page-hero__inner">'),
        True,
    ),
}

CURRENT_ATTR = ' aria-current="page"'
# Both orders occur in the templates, so the marker is matched either side of
# the href rather than assumed to follow it.
MARKED = re.compile(
    r'aria-current="page"[^>]*?href="([^"]+)"|href="([^"]+)"[^>]*?aria-current="page"'
)


def pages() -> list[Path]:
    return sorted(
        list(ROOT.glob("*.html"))
        + list(ROOT.glob("pages/**/*.html"))
        + list(ROOT.glob("pages/**/*.php"))
    )


def marked_hrefs(markup: str) -> set[str]:
    out = set()
    for a, b in MARKED.findall(markup):
        out.add(a or b)
    return out


def apply_current(markup: str, hrefs: set[str]) -> str:
    """Re-mark the links this page had marked before the copy."""
    if not hrefs:
        return markup

    def mark(match: re.Match) -> str:
        tag = match.group(0)
        if 'aria-current' in tag:
            return tag
        href = match.group(1)
        if href not in hrefs:
            return tag
        return tag[:-1].rstrip() + CURRENT_ATTR + ">"

    return re.sub(r'<a\b[^>]*?href="([^"]+)"[^>]*?>', mark, markup)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    canonical = {}
    for name, (filename, _, _, _) in BLOCKS.items():
        path = TEMPLATES / filename
        if not path.exists():
            raise SystemExit(f"Missing template: {path}")
        canonical[name] = path.read_text().strip("\n")

    files = pages()
    if not files:
        raise SystemExit("No pages found.")

    changed = 0
    for page in files:
        original = page.read_text()
        markup = original
        notes = []

        # What the header marks is what the dock should mark, and it is read
        # before anything is rewritten.
        header_pattern = BLOCKS["header"][1]
        header_now = header_pattern.search(markup)
        header_current = marked_hrefs(header_now.group(0)) if header_now else set()

        for name, (_, pattern, anchor, optional) in BLOCKS.items():
            found = pattern.search(markup)

            # Markers are read from the block being replaced, never from the
            # page as a whole. Page-wide, index.html's "/" would also mark the
            # footer's Home link, quietly adding a marker the page never had.
            if found:
                current = marked_hrefs(found.group(0))
            elif name == "dock":
                current = header_current
            else:
                current = set()

            replacement = apply_current(canonical[name], current)

            if found:
                new = pattern.sub(lambda _m: replacement, markup, count=1)
                if new != markup:
                    notes.append(name)
                markup = new
                continue

            if anchor is None:
                notes.append(f"{name}: MISSING and no anchor to insert at")
                continue

            spot = anchor.search(markup)
            if not spot:
                if not optional:
                    notes.append(f"{name}: MISSING and anchor not found")
                continue
            at = spot.start()
            markup = markup[:at] + replacement + "\n\n" + markup[at:]
            notes.append(f"{name} (inserted)")

        rel = page.relative_to(ROOT)
        if markup == original:
            continue

        changed += 1
        print(f"  {rel}  —  {', '.join(notes)}")
        if not args.dry_run:
            page.write_text(markup)

    verb = "would change" if args.dry_run else "updated"
    print(f"\n{changed} of {len(files)} pages {verb}.")
    if not args.dry_run and changed:
        print("Now run: python3 tools/check_shared_markup.py")
        print("     and python3 tools/inject_icons.py")


if __name__ == "__main__":
    main()
