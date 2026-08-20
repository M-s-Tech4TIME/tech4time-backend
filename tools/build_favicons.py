#!/usr/bin/env python3
"""
Generate the Tech4TIME favicon set from the master logo's clock mark.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/build_favicons.py

Design note: the clock face is the logo's distinctive, square-friendly element,
so it becomes the icon rather than the full wordmark (which is unreadable below
~64px). It is composited on the dark base tone with the silver ink so the icon
stays legible against both light and dark browser chrome — a transparent silver
mark would vanish on a light tab bar.
"""

from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
LOGO = ROOT / "tools" / "masters" / "logo" / "logo-dark-full.png"
OUT = ROOT / "assets" / "images" / "favicon"

# Clock-face bounding box within logo-dark.png (4495x1600), measured from the
# alpha channel: centre (3922, 522), diameter ~1041.
CLOCK_BOX = (3400, 0, 4444, 1044)

BG = (11, 11, 12, 255)  # --bg-base (dark mode) #0B0B0C

# size -> padding as a fraction of the tile (smaller icons need proportionally
# less padding to stay legible; iOS masks its own corners so 180 gets more).
SIZES = {
    16: 0.06,
    32: 0.08,
    48: 0.08,
    96: 0.10,
    192: 0.10,
    512: 0.10,
}
APPLE_TOUCH = (180, 0.14)

ICO_SIZES = [16, 32, 48]


def clock_mark() -> Image.Image:
    """The silver clock face, trimmed square, alpha-cropped and circle-masked."""
    mark = Image.open(LOGO).convert("RGBA").crop(CLOCK_BOX)
    bbox = mark.getchannel("A").getbbox()
    mark = mark.crop(bbox)

    # Pad to a perfect square so the circle never distorts on resize.
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

    alpha = square.getchannel("A").point(lambda v: v)
    square.putalpha(Image.composite(alpha, Image.new("L", (side, side), 0), mask))
    return square


def tile(mark: Image.Image, size: int, pad: float) -> Image.Image:
    inner = round(size * (1 - 2 * pad))
    canvas = Image.new("RGBA", (size, size), BG)
    resized = mark.resize((inner, inner), Image.LANCZOS)
    offset = (size - inner) // 2
    canvas.paste(resized, (offset, offset), resized)
    return canvas


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    mark = clock_mark()
    print(f"clock mark: {mark.width}x{mark.height}")

    # Transparent silver mark, for in-page use (e.g. the 404 page).
    mark.resize((512, 512), Image.LANCZOS).save(OUT / "mark-512.png", optimize=True)

    for size, pad in SIZES.items():
        tile(mark, size, pad).save(OUT / f"favicon-{size}.png", optimize=True)
        print(f"  favicon-{size}.png")

    size, pad = APPLE_TOUCH
    tile(mark, size, pad).save(OUT / "apple-touch-icon.png", optimize=True)
    print(f"  apple-touch-icon.png ({size}px)")

    # Multi-resolution .ico for legacy browsers and the address bar.
    base = tile(mark, 256, SIZES[48])
    base.save(
        OUT / "favicon.ico",
        format="ICO",
        sizes=[(s, s) for s in ICO_SIZES],
    )
    print(f"  favicon.ico ({', '.join(str(s) for s in ICO_SIZES)})")


if __name__ == "__main__":
    main()
