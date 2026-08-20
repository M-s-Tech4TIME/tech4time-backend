#!/usr/bin/env python3
"""
Fetch and self-host the Inter variable font (latin + latin-ext subsets).

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/fetch_fonts.py

The NextJS source uses next/font/google's Inter; self-hosting keeps the same
typeface with no third-party request, which is what the CSP and Core Web Vitals
rules require. A variable font covers 400-700 in a single file.

Google Fonts CSS v2 labels each @font-face with a `/* subset */` comment rather
than naming the subset in the file URL, so the subset has to be read from the
comment that precedes each block.
"""

import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "assets" / "fonts"

CSS_URL = "https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap"
# A modern Chrome UA makes the endpoint serve woff2 with unicode-range blocks.
UA = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/126.0.0.0 Safari/537.36"
)

# English-only site: latin covers the copy, latin-ext covers accented names.
WANTED = {"latin", "latin-ext"}

BLOCK = re.compile(
    r"/\*\s*(?P<subset>[\w-]+)\s*\*/\s*@font-face\s*\{(?P<body>[^}]*)\}",
    re.S,
)


def curl(url: str) -> bytes:
    return subprocess.run(
        ["curl", "-fsSL", "-A", UA, url],
        check=True,
        capture_output=True,
    ).stdout


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    css = curl(CSS_URL).decode("utf-8")

    found = {}
    for m in BLOCK.finditer(css):
        subset = m.group("subset")
        if subset not in WANTED:
            continue
        url = re.search(r"url\((https://[^)]+\.woff2)\)", m.group("body"))
        rng = re.search(r"unicode-range:\s*([^;]+);", m.group("body"))
        if not url:
            continue
        found[subset] = (url.group(1), rng.group(1).strip() if rng else "")

    missing = WANTED - found.keys()
    if missing:
        raise SystemExit(f"Subsets not found in Google Fonts CSS: {sorted(missing)}")

    for subset, (url, rng) in sorted(found.items()):
        dest = OUT / f"inter-{subset}.woff2"
        dest.write_bytes(curl(url))
        print(f"  {dest.name}  ({dest.stat().st_size:,} bytes)")
        print(f"    unicode-range: {rng};")


if __name__ == "__main__":
    main()
