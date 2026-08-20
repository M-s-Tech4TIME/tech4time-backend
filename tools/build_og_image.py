#!/usr/bin/env python3
"""
Build the 1200x630 Open Graph / Twitter Card share image.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/build_og_image.py

Design: the dark base tone, a metallic silver gradient sweep echoing the clock
hands in the logo, the silver wordmark, and the company tagline. Monochrome
only -- no hue -- matching the site's palette.

Needs the Inter variable TTF for text. Pillow cannot read the woff2 files the
site ships, so the TTF is fetched to the scratchpad at build time and is not
committed; the rendered PNG is the committed artefact.
"""

import urllib.request
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
LOGO = ROOT / "tools" / "masters" / "logo" / "logo-dark-full.png"
OUT = ROOT / "assets" / "images" / "og" / "tech4time-og.png"

SCRATCH = Path("/tmp/claude-1000/-home-alsechemist-CodeSpace-tech4time-website"
               "/00510023-1809-4d72-ba7e-f02f1376e333/scratchpad")
FONT_TTF = SCRATCH / "Inter-var.ttf"
FONT_URL = "https://github.com/google/fonts/raw/main/ofl/inter/Inter%5Bopsz%2Cwght%5D.ttf"

W, H = 1200, 630

# Palette (dark mode tokens)
BG_BASE = (11, 11, 12)            # --bg-base
BG_SURFACE = (21, 21, 23)         # --bg-surface
SILVER_START = (232, 233, 235)    # --silver-accent-start
SILVER_MID = (184, 186, 190)      # --silver-accent-mid
SILVER_END = (124, 126, 131)      # --silver-accent-end
TEXT_SECONDARY = (180, 180, 184)  # --text-secondary

TAGLINE = "Orchestrating Technology with Time"
STRAPLINE = "Cybersecurity  ·  Software Development  ·  Cloud Infrastructure  ·  HR Solutions"


def font(size: int, weight: int) -> ImageFont.FreeTypeFont:
    if not FONT_TTF.exists():
        FONT_TTF.parent.mkdir(parents=True, exist_ok=True)
        with urllib.request.urlopen(FONT_URL) as r:
            FONT_TTF.write_bytes(r.read())
    f = ImageFont.truetype(str(FONT_TTF), size)
    f.set_variation_by_axes([32, weight])  # [optical size, weight]
    return f


def lerp(a, b, t):
    return tuple(round(x + (y - x) * t) for x, y in zip(a, b))


def diagonal_gradient(size, stops):
    """135deg silver gradient, matching --silver-gradient in theme.css."""
    w, h = size
    grad = Image.new("RGB", (w, h))
    px = grad.load()
    for y in range(h):
        for x in range(w):
            t = (x / w + y / h) / 2
            if t < 0.5:
                px[x, y] = lerp(stops[0], stops[1], t * 2)
            else:
                px[x, y] = lerp(stops[1], stops[2], (t - 0.5) * 2)
    return grad


def main() -> None:
    OUT.parent.mkdir(parents=True, exist_ok=True)

    # Base: a soft vertical lift from base to surface so the card is not flat.
    card = Image.new("RGB", (W, H), BG_BASE)
    draw = ImageDraw.Draw(card)
    for y in range(H):
        draw.line([(0, y), (W, y)], fill=lerp(BG_BASE, BG_SURFACE, (y / H) ** 1.5))

    # Metallic sweep: a wide diagonal band of the silver gradient at low opacity,
    # evoking the shine on the clock hands.
    band = Image.new("L", (W, H), 0)
    ImageDraw.Draw(band).polygon(
        [(W * 0.52, 0), (W, 0), (W, H), (W * 0.18, H)], fill=26
    )
    card = Image.composite(
        diagonal_gradient((W, H), (SILVER_START, SILVER_MID, SILVER_END)),
        card,
        band,
    )
    draw = ImageDraw.Draw(card)

    # Top hairline in the silver gradient.
    rule = diagonal_gradient((W, 6), (SILVER_END, SILVER_START, SILVER_END))
    card.paste(rule, (0, 0))

    # Wordmark, left-aligned on a generous margin.
    margin = 80
    logo = Image.open(LOGO).convert("RGBA")
    logo_w = 620
    logo = logo.resize((logo_w, round(logo_w * logo.height / logo.width)), Image.LANCZOS)
    logo_y = 150
    card.paste(logo, (margin, logo_y), logo)

    # Tagline.
    y = logo_y + logo.height + 46
    draw.text((margin, y), TAGLINE, font=font(46, 700), fill=SILVER_START)

    # Strapline.
    y += 76
    draw.text((margin, y), STRAPLINE, font=font(24, 500), fill=TEXT_SECONDARY)

    # Domain, bottom-right, so it balances the left-aligned block rather than
    # crowding the strapline.
    domain_font = font(26, 600)
    domain = "tech4time.bd"
    dw = draw.textlength(domain, font=domain_font)
    draw.text((W - margin - dw, H - 86), domain, font=domain_font, fill=SILVER_MID)

    card.save(OUT, optimize=True)
    print(f"wrote {OUT.relative_to(ROOT)}  ({W}x{H}, {OUT.stat().st_size:,} bytes)")


if __name__ == "__main__":
    main()
