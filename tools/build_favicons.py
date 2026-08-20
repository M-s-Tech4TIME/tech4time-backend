#!/usr/bin/env python3
"""
Generate the Tech4TIME favicon set.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/build_favicons.py

TWO SOURCES, DELIBERATELY
  Browser favicons (16-48px) come from the supplied artwork at
  tools/masters/favicon/tech4time-favicon.ico — a simplified clock face (plain
  tick marks, no roman numerals, heavier strokes) drawn to stay readable at
  16px. It is served verbatim as favicon.ico.

  App icons (apple-touch 180px, manifest 192/512px) come from the clock face in
  the full-resolution logo. The supplied .ico only exists at 32x32, and
  upscaling that to 512 would be visibly soft on a PWA install or an iOS home
  screen. Using the detailed mark at large sizes and the simplified mark at
  small sizes is the normal split, not an inconsistency — the detail that would
  turn to mush at 16px is exactly what large icons want.

  Supply the favicon artwork at 512px or as SVG and this split can go away.

TRANSPARENCY
  The supplied mark is transparent-backed. Its dial ring, tick marks and hands
  carry enough dark linework to stay legible against both light and dark browser
  chrome, so it ships unmodified. App icons are composited onto the dark base
  tone regardless, because transparent PWA and iOS icons render against an
  unpredictable background.
"""

from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
SUPPLIED_ICO = ROOT / "tools" / "masters" / "favicon" / "tech4time-favicon.ico"
LOGO = ROOT / "tools" / "masters" / "logo" / "logo-dark-full.png"
OUT = ROOT / "assets" / "images" / "favicon"

# Clock-face bounding box within logo-dark-full.png (4495x1600), measured from
# the alpha channel: centre (3922, 522), diameter ~1041.
CLOCK_BOX = (3400, 0, 4444, 1044)

BG = (11, 11, 12, 255)  # --bg-base (dark mode) #0B0B0C

# Browser favicons: supplied artwork, transparency preserved. Capped at 48 —
# the source is 32px, and anything larger is better served by the app icons.
BROWSER_SIZES = [16, 32, 48]

# App icons: logo clock on an opaque tile. size -> padding fraction.
# iOS masks its own corners, so apple-touch gets more breathing room.
APP_ICONS = {
    180: ("apple-touch-icon.png", 0.14),
    192: ("favicon-192.png", 0.10),
    512: ("favicon-512.png", 0.10),
}


def logo_clock_mark() -> Image.Image:
    """The silver clock face from the logo, trimmed square and circle-masked."""
    mark = Image.open(LOGO).convert("RGBA").crop(CLOCK_BOX)
    mark = mark.crop(mark.getchannel("A").getbbox())

    side = max(mark.size)
    square = Image.new("RGBA", (side, side), (0, 0, 0, 0))
    square.paste(mark, ((side - mark.width) // 2, (side - mark.height) // 2), mark)

    # The crop box is a rectangle around a circular mark, so it also catches the
    # swoosh tip and a sliver of the wordmark in the corners. Mask to the circle
    # (supersampled for a clean edge) to drop everything outside the dial.
    ss = 4
    mask = Image.new("L", (side * ss, side * ss), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, side * ss - 1, side * ss - 1), fill=255)
    mask = mask.resize((side, side), Image.LANCZOS)

    square.putalpha(
        Image.composite(square.getchannel("A"), Image.new("L", (side, side), 0), mask)
    )
    return square


def tile(mark: Image.Image, size: int, pad: float) -> Image.Image:
    inner = round(size * (1 - 2 * pad))
    canvas = Image.new("RGBA", (size, size), BG)
    resized = mark.resize((inner, inner), Image.LANCZOS)
    offset = (size - inner) // 2
    canvas.paste(resized, (offset, offset), resized)
    return canvas


def main() -> None:
    if not SUPPLIED_ICO.exists():
        raise SystemExit(f"Supplied favicon missing: {SUPPLIED_ICO}")

    OUT.mkdir(parents=True, exist_ok=True)

    # --- Browser favicons, from the supplied artwork --------------------
    supplied = Image.open(SUPPLIED_ICO).convert("RGBA")
    print(f"supplied artwork: {supplied.width}x{supplied.height}")

    # Serve the original file byte-for-byte as favicon.ico.
    (OUT / "favicon.ico").write_bytes(SUPPLIED_ICO.read_bytes())
    print("  favicon.ico (supplied file, verbatim)")

    for size in BROWSER_SIZES:
        resized = supplied.resize((size, size), Image.LANCZOS)
        resized.save(OUT / f"favicon-{size}.png", optimize=True)
        note = "" if size <= supplied.width else "  [upscaled]"
        print(f"  favicon-{size}.png{note}")

    # --- App icons, from the logo clock ---------------------------------
    mark = logo_clock_mark()
    print(f"logo clock mark: {mark.width}x{mark.height}")

    for size, (filename, pad) in sorted(APP_ICONS.items()):
        tile(mark, size, pad).save(OUT / filename, optimize=True)
        print(f"  {filename} ({size}px, on {BG[:3]})")

    # Transparent mark for in-page use (currently unused; kept small).
    mark.resize((256, 256), Image.LANCZOS).save(OUT / "mark-256.png", optimize=True)
    print("  mark-256.png")

    # Clean up artefacts from earlier revisions of this script.
    for stale in ("mark-512.png", "favicon-96.png"):
        path = OUT / stale
        if path.exists():
            path.unlink()
            print(f"  removed stale {stale}")


if __name__ == "__main__":
    main()
