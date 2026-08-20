#!/usr/bin/env python3
"""
Normalise the Tech4TIME master logo artwork into the web asset set.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/build_logos.py

Source naming note: in the master files, "Light"/"Dark" describe the background
the logo is meant to sit on, not the ink colour.
  *_Light_Transparent.png  -> near-black ink   -> used in LIGHT mode
  *_Dark_Transparent.png   -> silver ink       -> used in DARK mode
"""

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
# Masters live under tools/ so the 2.3MB of source artwork is never uploaded to
# the web server; only the resized web variants land in assets/.
SRC = ROOT / "tools" / "masters" / "logo"
OUT = ROOT / "assets" / "images" / "logo"

# Master artwork -> output stem
MASTERS = {
    "Tech4Time Logo_New_Main_Light_Transparent.png": "logo-light",
    "Tech4Time Logo_New_Main_Dark_Transparent.png": "logo-dark",
}

# Widths emitted for srcset. The header renders the logo at 180px CSS width,
# so 180/360 cover 1x/2x; 540 covers 3x and the larger footer/404 usages.
WIDTHS = [180, 360, 540]


def trim(im: Image.Image) -> Image.Image:
    """Crop away fully transparent margins so the mark sits flush in its box."""
    bbox = im.getchannel("A").getbbox()
    return im.crop(bbox) if bbox else im


def emit(master: Path, stem: str) -> None:
    im = trim(Image.open(master).convert("RGBA"))
    ratio = im.height / im.width

    # Trimmed full-resolution copy, kept beside the masters as the build source
    # for the favicon and OG card (not served).
    im.save(SRC / f"{stem}-full.png", optimize=True)

    for w in WIDTHS:
        h = round(w * ratio)
        resized = im.resize((w, h), Image.LANCZOS)
        resized.save(OUT / f"{stem}-{w}.png", optimize=True)
        resized.save(OUT / f"{stem}-{w}.webp", format="WEBP", quality=90, method=6)
        print(f"  {stem}-{w}.png / .webp  ({w}x{h})")

    print(f"  {stem}-full.png (build source, {im.width}x{im.height})")


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)

    for filename, stem in MASTERS.items():
        master = SRC / filename
        if not master.exists():
            raise SystemExit(f"Missing master artwork: {master}")
        print(f"{filename} -> {stem}")
        emit(master, stem)


if __name__ == "__main__":
    main()
