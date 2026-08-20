#!/usr/bin/env python3
"""
Generate the Tech4TIME favicon set from the supplied 512px master.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/build_favicons.py

SOURCE
  tools/masters/favicon/tech4time-favicon-512.ico — the clock dial at 512x512.
  Every output size derives from this one file, so the mark is identical at
  every scale.

  An earlier 32x32 simplified version (plain ticks, no numerals) is kept beside
  it as tech4time-favicon-32-simplified.ico. It is not used: it holds up better
  at 16px, but one dial that goes slightly soft at the smallest size beats two
  different dials across the set.

TRANSPARENCY
  The dial has a light face with dark linework, so it reads against both light
  and dark browser chrome with no backing plate. Browser favicons therefore ship
  transparent, exactly as supplied.

  App icons (Apple touch, PWA manifest) are composited onto the dark base tone.
  Transparent icons render against an unpredictable background on an iOS home
  screen or in an app switcher, and the light dial sits well on near-black.

favicon.ico is rebuilt as a multi-size icon (16/32/48) rather than shipping the
512px master directly: browsers pick the size they need from it, and the master
is 136KB for an asset requested on every page load.
"""

import warnings
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
MASTER = ROOT / "tools" / "masters" / "favicon" / "tech4time-favicon-512.ico"
OUT = ROOT / "assets" / "images" / "favicon"

BG = (11, 11, 12, 255)  # --bg-base (dark mode) #0B0B0C

# Transparent, as supplied.
BROWSER_SIZES = [16, 32, 48, 96]

# Opaque tile. size -> (filename, padding as a fraction of the tile).
# iOS masks its own corners, so apple-touch gets more breathing room.
APP_ICONS = {
    180: ("apple-touch-icon.png", 0.14),
    192: ("favicon-192.png", 0.10),
    512: ("favicon-512.png", 0.10),
}

ICO_SIZES = [16, 32, 48]


def load_master() -> Image.Image:
    """The supplied dial, alpha-trimmed and padded back to a square."""
    # Pillow warns that the .ico's declared size differs from its payload; the
    # payload decodes correctly, which is what matters.
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        mark = Image.open(MASTER).convert("RGBA")

    bbox = mark.getchannel("A").getbbox()
    if bbox:
        mark = mark.crop(bbox)

    side = max(mark.size)
    square = Image.new("RGBA", (side, side), (0, 0, 0, 0))
    square.paste(mark, ((side - mark.width) // 2, (side - mark.height) // 2), mark)
    return square


def tile(mark: Image.Image, size: int, pad: float) -> Image.Image:
    inner = round(size * (1 - 2 * pad))
    canvas = Image.new("RGBA", (size, size), BG)
    resized = mark.resize((inner, inner), Image.LANCZOS)
    offset = (size - inner) // 2
    canvas.paste(resized, (offset, offset), resized)
    return canvas


def main() -> None:
    if not MASTER.exists():
        raise SystemExit(f"Master favicon missing: {MASTER}")

    OUT.mkdir(parents=True, exist_ok=True)
    mark = load_master()
    print(f"master: {MASTER.name} -> {mark.width}x{mark.height} after trim")

    for size in BROWSER_SIZES:
        mark.resize((size, size), Image.LANCZOS).save(
            OUT / f"favicon-{size}.png", optimize=True
        )
        print(f"  favicon-{size}.png (transparent)")

    for size, (filename, pad) in sorted(APP_ICONS.items()):
        tile(mark, size, pad).save(OUT / filename, optimize=True)
        print(f"  {filename} ({size}px, on {BG[:3]})")

    # Multi-size .ico so browsers pick the resolution they need.
    mark.resize((256, 256), Image.LANCZOS).save(
        OUT / "favicon.ico", format="ICO", sizes=[(s, s) for s in ICO_SIZES]
    )
    print(f"  favicon.ico ({', '.join(str(s) for s in ICO_SIZES)})")

    # Clean up artefacts from earlier revisions of this script.
    for stale in ("mark-256.png", "mark-512.png"):
        path = OUT / stale
        if path.exists():
            path.unlink()
            print(f"  removed stale {stale}")


if __name__ == "__main__":
    main()
