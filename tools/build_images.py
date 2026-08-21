#!/usr/bin/env python3
"""
Copy, rename and optimise the site's content images.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/build_images.py

Every source is the CURRENT LIVE SITE, staged into tools/masters/ by
tools/stage_live_images.py. Nothing here reads the NextJS repository: the live
site is the authority on which images the pages use, and an earlier pass that
mixed NextJS artwork in produced logos the live pages do not carry and missed
thirteen that they do.

Only referenced images are ported, so the repo carries what the pages actually
use and nothing else.

Every raster gets a WebP sibling and a resized original as fallback, so pages can
use <picture> with an explicit width/height and avoid layout shift. SVGs are
copied verbatim -- they are already resolution independent.
"""

import shutil
from pathlib import Path

from PIL import Image, ImageChops

ROOT = Path(__file__).resolve().parent.parent
MASTERS = ROOT / "tools" / "masters"

# Company-profile artwork — client logos, the technology grid and the journey
# photographs — all comes from the CURRENT LIVE SITE, staged into
# tools/masters/ by tools/stage_live_images.py, which is where the live site's
# hashed filenames are mapped to these readable ones. A mapping of None means
# "port every file in this directory, keeping its stem".

# Section illustrations for the About page, taken from the CURRENT LIVE SITE
# (copied into tools/masters/sections/). The NextJS build has its own Goal /
# Mission / Vision / Ambition artwork, but those are blue-tinted illustrations
# on dark plates that fight the monochrome palette; the live site's are pure
# black-and-white line art and match the rest of the rebuild.
SECTIONS = {
    "our-goal.jpg": "our-goal",
    "our-mission.jpg": "our-mission",
    "our-vision.jpg": "our-vision",
    "our-ambition.jpg": "our-ambition",
}

# Line-art illustrations for the homepage's three destination cards. These come
# from the current live site (curated in the earlier project iteration and kept
# at the v2-archive tag); the NextJS build has no equivalent artwork.
PAGE_CARDS = {
    "about-us.jpg": "about-us",
    "services.jpg": "services",
    "company-profile.jpg": "company-profile",
}

# (source dir, mapping, destination dir, max width, trim)
# Logos render at ~120px, photos and section art span a card or half a section.
# `trim` crops the flat white margin the live site's exports carry, so the art
# fills its box instead of floating in a wide border.
JOBS = [
    (MASTERS / "tech", None, "tech", 320, False),
    (MASTERS / "clients", None, "clients", 320, False),
    (MASTERS / "photos", None, "photos", 1200, False),
    (MASTERS / "flags", None, "flags", 160, False),
    (MASTERS / "branding", None, "branding", 800, False),
    (MASTERS / "sections", SECTIONS, "sections", 1000, True),
    (MASTERS / "pages", PAGE_CARDS, "pages", 800, False),
]

# The branding page offers its four logos as downloads, so each is written a
# second time at a size worth downloading. These are NOT loaded by the page —
# only the 800px previews above are — so their weight costs a visitor nothing
# unless they ask for one.
#
# PNG only. The point of the download is a file the recipient can drop into a
# deck or a print job without thinking about format support, and 1600px is
# wide enough for both while keeping each file to a few hundred KB. The
# originals run to 6000px and 1.3MB, which is more than the use warrants.
BRAND_DOWNLOADS = (MASTERS / "branding", "branding", 1600, "-full")

# Copied byte-for-byte rather than re-encoded.
#   .svg  - already resolution independent.
#   .avif - this machine's Pillow has no AVIF decoder (and no ImageMagick,
#           ffmpeg or python3-venv to add one), so shuffle.avif cannot be
#           transcoded here. AVIF is itself a modern format with ~95% browser
#           support, so it ships as-is; the <img> alt text covers the rest.
PASSTHROUGH = {".svg", ".avif"}


def trim_margin(im: Image.Image, keep: float = 0.04) -> Image.Image:
    """Crop a flat near-white border, leaving a small proportional margin."""
    rgb = im.convert("RGB")
    diff = ImageChops.difference(rgb, Image.new("RGB", rgb.size, (255, 255, 255)))
    bbox = diff.convert("L").point(lambda v: 255 if v > 12 else 0).getbbox()
    if not bbox:
        return im

    pad = round(max(bbox[2] - bbox[0], bbox[3] - bbox[1]) * keep)
    return im.crop((
        max(0, bbox[0] - pad),
        max(0, bbox[1] - pad),
        min(im.width, bbox[2] + pad),
        min(im.height, bbox[3] + pad),
    ))


def process(src: Path, dest_dir: Path, stem: str, max_w: int, trim: bool = False) -> str:
    ext = src.suffix.lower()

    if ext in PASSTHROUGH:
        shutil.copy2(src, dest_dir / f"{stem}{ext}")
        return f"{stem}{ext} (copied verbatim)"

    im = Image.open(src)
    im.load()

    if trim:
        im = trim_margin(im)

    if im.width > max_w:
        im = im.resize((max_w, round(max_w * im.height / im.width)), Image.LANCZOS)

    # Keep transparency only where it is actually used. Several sources carry an
    # alpha channel that is fully opaque (a 534KB "transparent" photo, for one),
    # which would otherwise force a needlessly heavy PNG fallback.
    declares_alpha = im.mode in ("RGBA", "LA") or (
        im.mode == "P" and "transparency" in im.info
    )
    im = im.convert("RGBA" if declares_alpha else "RGB")
    has_alpha = declares_alpha and im.getchannel("A").getextrema()[0] < 255
    if declares_alpha and not has_alpha:
        im = im.convert("RGB")

    im.save(dest_dir / f"{stem}.webp", format="WEBP", quality=85, method=6)

    # Fallback in a format every browser and crawler handles.
    if has_alpha:
        fallback = f"{stem}.png"
        im.save(dest_dir / fallback, optimize=True)
    else:
        fallback = f"{stem}.jpg"
        im.save(dest_dir / fallback, format="JPEG", quality=86, optimize=True, progressive=True)

    return f"{stem}.webp + {fallback}  ({im.width}x{im.height})"


def build_downloads() -> int:
    """Write the full-size PNGs the branding page's download buttons hand over."""
    src_dir, dest_name, max_w, suffix = BRAND_DOWNLOADS
    dest_dir = ROOT / "assets" / "images" / dest_name
    dest_dir.mkdir(parents=True, exist_ok=True)
    print(f"\n{src_dir.name}/ -> assets/images/{dest_name}/  (downloads)")

    count = 0
    for src in sorted(p for p in src_dir.iterdir() if p.is_file()):
        im = Image.open(src)
        im.load()

        if im.width > max_w:
            im = im.resize((max_w, round(max_w * im.height / im.width)), Image.LANCZOS)

        # Alpha is kept whether or not it is used here. On the plated variants
        # the flat background IS the asset — flattening it to JPEG would hand
        # someone a logo they cannot place on their own background.
        out = dest_dir / f"{src.stem}{suffix}.png"
        im.save(out, optimize=True)
        print(f"  {out.name}  ({im.width}x{im.height}, {out.stat().st_size // 1024}KB)")
        count += 1

    return count


def main() -> None:
    total, missing = 0, []

    for src_dir, mapping, dest_name, max_w, trim in JOBS:
        dest_dir = ROOT / "assets" / "images" / dest_name
        dest_dir.mkdir(parents=True, exist_ok=True)
        print(f"\n{src_dir.name}/ -> assets/images/{dest_name}/")

        if mapping is None:
            mapping = {p.name: p.stem for p in sorted(src_dir.iterdir()) if p.is_file()}

        for filename, stem in sorted(mapping.items(), key=lambda kv: kv[1]):
            src = src_dir / filename
            if not src.exists():
                missing.append(str(src))
                print(f"  MISSING  {filename}")
                continue
            print(f"  {process(src, dest_dir, stem, max_w, trim)}")
            total += 1

    total += build_downloads()

    print(f"\n{total} images ported")
    if missing:
        raise SystemExit(f"{len(missing)} source files not found:\n  " + "\n  ".join(missing))


if __name__ == "__main__":
    main()
