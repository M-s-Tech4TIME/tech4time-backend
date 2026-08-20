#!/usr/bin/env python3
"""
Copy, rename and optimise the content images the NextJS site actually uses.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/build_images.py

Only referenced images are ported. public/app-logo/ holds 75 files but the pages
reference 37 of them; the rest (plus SOC.drawio) are leftovers and are skipped so
they do not bloat the repo.

Every raster gets a WebP sibling and a resized original as fallback, so pages can
use <picture> with an explicit width/height and avoid layout shift. SVGs are
copied verbatim -- they are already resolution independent.
"""

import shutil
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
NEXT = Path("/home/alsechemist/CodeSpace/Tech4TIME-web-ui")
PUBLIC = NEXT / "public"
IMGS = NEXT / "src" / "app" / "imgs"

# Third-party product logos shown in the company-profile technology grid.
# source filename -> kebab-case destination stem (the product's real name)
TECH = {
    "belkasoft.png": "belkasoft",
    "bunker.png": "bunkerweb",
    "burpsuite-logo.png": "burp-suite",
    "cobalt-strike-logo.png": "cobalt-strike",
    "cortex-logo.png": "cortex",
    "eicomsoft-logo.jpeg": "elcomsoft",
    "elasticsearch.png": "elasticsearch",
    "encase.png": "encase",
    "fortinet-logo.svg": "fortinet",
    "ghidra-logo.webp": "ghidra",
    "grr-rapid-reponse-logo.png": "grr-rapid-response",
    "ibm-qradar.webp": "ibm-qradar",
    "iris-logo.png": "iris",
    "magnet-forensic.png": "magnet-forensics",
    "metasploit-logo.svg": "metasploit",
    "microsoft_sentinel-logo.png": "microsoft-sentinel",
    "ModSecurity_Logo.png": "modsecurity",
    "nessus-logo.png": "nessus",
    "OpenCTI_Logo.png": "opencti",
    "openstack-logo.png": "openstack",
    "openvas-logo.png": "openvas",
    "OPNsense.png": "opnsense",
    "oxygen-forensic-logo.png": "oxygen-forensic",
    "paloalto-logo.png": "palo-alto-networks",
    "pfsense-logo.png": "pfsense",
    "proxmox-logo.svg": "proxmox",
    "shuffle.avif": "shuffle",
    "splunk-logo.png": "splunk",
    "suricata.jpeg": "suricata",
    "thehive-logo.png": "thehive",
    "Tracecat.png": "tracecat",
    "Velociraptor-logo.svg": "velociraptor",
    "Wazuh_Logo.png": "wazuh",
    "wireguard.png": "wireguard",
    "x-ways-logo.jpeg": "x-ways-forensics",
    "Zabbix_logo.png": "zabbix",
    "zeek-logo.png": "zeek",
}

# Client logos. The NextJS markup labels every one of these "Government Sector",
# which is neither accurate nor useful as alt text; the real organisations are
# named here so the port can carry descriptive alt attributes.
CLIENTS = {
    "CCA.jpg": "cca",
    "Information_and_Communication_Technology_Division.svg": "ict-division",
    "large_color_logo.png": "aitken-spence",
    "MGC final logo-8.webp": "mgc",
    "Petronas_Logo.svg.png": "petronas",
    "Roundel_of_Bangladesh_–_Army_Aviation.svg.png": "bangladesh-army-aviation",
    "United_Parcel_Service_logo_2014.svg.png": "ups",
    "cdbl-logo.png": "cdbl",
}

# Company celebration photography (company-profile "Our Journey" gallery).
PHOTOS = {
    "celebration-1.jpeg": "celebration-1",
    "celebration-2.png": "celebration-2",
    "celebration-3.jpeg": "celebration-3",
}

# Section illustrations used by the About page and the services portfolio.
SECTIONS = {
    "Our Goal.png": "our-goal",
    "Our Mission.png": "our-mission",
    "Our Vision.png": "our-vision",
    "Our Ambition.png": "our-ambition",
    "Security Policy.png": "security-policy",
    "Custom Software Development.png": "custom-software-development",
    "On-Demand IT Equipment Supply.png": "it-equipment-supply",
    "Consultancy.png": "consultancy",
}

# Line-art illustrations for the homepage's three destination cards. These come
# from the current live site (curated in the earlier project iteration and kept
# at the v2-archive tag); the NextJS build has no equivalent artwork.
PAGE_CARDS = {
    "about-us.jpg": "about-us",
    "services.jpg": "services",
    "company-profile.jpg": "company-profile",
}

# (source dir, mapping, destination dir, max width)
# Logos render at ~120px, photos and section art span a card or half a section.
JOBS = [
    (PUBLIC / "app-logo", TECH, "tech", 320),
    (PUBLIC / "c-logo", CLIENTS, "clients", 320),
    (PUBLIC / "spic", PHOTOS, "photos", 1200),
    (IMGS, SECTIONS, "sections", 1000),
    (ROOT / "tools" / "masters" / "pages", PAGE_CARDS, "pages", 800),
]

# Copied byte-for-byte rather than re-encoded.
#   .svg  - already resolution independent.
#   .avif - this machine's Pillow has no AVIF decoder (and no ImageMagick,
#           ffmpeg or python3-venv to add one), so shuffle.avif cannot be
#           transcoded here. AVIF is itself a modern format with ~95% browser
#           support, so it ships as-is; the <img> alt text covers the rest.
PASSTHROUGH = {".svg", ".avif"}


def process(src: Path, dest_dir: Path, stem: str, max_w: int) -> str:
    ext = src.suffix.lower()

    if ext in PASSTHROUGH:
        shutil.copy2(src, dest_dir / f"{stem}{ext}")
        return f"{stem}{ext} (copied verbatim)"

    im = Image.open(src)
    im.load()

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


def main() -> None:
    total, missing = 0, []

    for src_dir, mapping, dest_name, max_w in JOBS:
        dest_dir = ROOT / "assets" / "images" / dest_name
        dest_dir.mkdir(parents=True, exist_ok=True)
        print(f"\n{src_dir.name}/ -> assets/images/{dest_name}/  ({len(mapping)} files)")

        for filename, stem in sorted(mapping.items(), key=lambda kv: kv[1]):
            src = src_dir / filename
            if not src.exists():
                missing.append(str(src))
                print(f"  MISSING  {filename}")
                continue
            print(f"  {process(src, dest_dir, stem, max_w)}")
            total += 1

    print(f"\n{total} images ported")
    if missing:
        raise SystemExit(f"{len(missing)} source files not found:\n  " + "\n  ".join(missing))


if __name__ == "__main__":
    main()
