#!/usr/bin/env python3
"""
Copy the current live site's imagery into tools/masters/ under readable names.

One-off build tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/stage_live_images.py

WHY THIS EXISTS
Page artwork follows the CURRENT LIVE SITE. The live site stores every image
under a hashed path — /images/<size>/<id>/<name>-<22charhash>.<ext> — with the
same picture present at several sizes. That is unusable as a build source, so
this resolves each one to its best rendition and copies it into tools/masters/
with the name the site will actually use. build_images.py then works purely
from tools/masters/, which keeps the build reproducible without a checkout of
the live site sitting next to this repo.

Re-run this only when the live site's artwork changes. The staged copies are
committed, so a normal build does not need it.
"""

import re
import shutil
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LIVE = Path("/home/alsechemist/CodeSpace/public_html/images")

# Clients shown in the company profile's "Proud Clients" grid, in the order the
# live page lists them. AIUB is a client there, not an accreditation badge.
CLIENTS = {
    "cca-logo.jpg": "cca",
    "ict-logo.svg": "ict-division",
    "aitkenspense-logo.png": "aitken-spence",
    "bdarmy-logo.png": "bangladesh-army-aviation",
    "mgc-logo.webp": "mgc",
    "petronas-logo.png": "petronas",
    "ups-logo.png": "ups",
    "cdbl-logo.png": "cdbl",
    "AIUB_Logo.png": "aiub",
}

# The technology grid under "Our Professional Excellence", in live page order.
# The last four are standards bodies and communities rather than products; the
# live site puts them in the same grid, so they are staged the same way.
TECH = {
    "Wazuh_Logo.png": "wazuh",
    "suricata.jpeg": "suricata",
    "ModSecurity_Logo.png": "modsecurity",
    "bunker.png": "bunkerweb",
    "pfsense-logo.png": "pfsense",
    "OPNsense.png": "opnsense",
    "paloalto-logo.png": "palo-alto-networks",
    "fortinet-logo.svg": "fortinet",
    "zeek-logo.png": "zeek",
    "OpenCTI_Logo.png": "opencti",
    "wireguard.png": "wireguard",
    "iris-logo.png": "iris",
    "Velociraptor-logo.svg": "velociraptor",
    "thehive-logo.png": "thehive",
    "cortex-logo.png": "cortex",
    "misp.png": "misp",
    "tracecat.svg": "tracecat",
    "shuffle.avif": "shuffle",
    "burpsuite-logo.png": "burp-suite",
    "openvas-logo.png": "openvas",
    "ghidra-logo.webp": "ghidra",
    "grr-rapid-reponse-logo.png": "grr-rapid-response",
    "elastic-stack.png": "elastic-stack",
    "grafana-logo.png": "grafana",
    "yara-logo.png": "yara",
    "magnet-forensic.png": "magnet-forensics",
    "belkasoft.png": "belkasoft",
    "oxygen-forensic-logo.png": "oxygen-forensic",
    "eicomsoft-logo.jpeg": "elcomsoft",
    "x-waysforensics.png": "x-ways-forensics",
    "encase.png": "encase",
    "ibm-qradar.webp": "ibm-qradar",
    "splunk-logo.png": "splunk",
    "microsoft_sentinel-logo.png": "microsoft-sentinel",
    "nessus-logo.png": "nessus",
    "cobalt-strike-logo.png": "cobalt-strike",
    "metasploit-logo.svg": "metasploit",
    "proxmox-logo.svg": "proxmox",
    "Zabbix_logo.png": "zabbix",
    "openstack-logo.png": "openstack",
    "nextjs-logo.png": "nextjs",
    "golang-gin-logo.webp": "golang-gin",
    "postgresql-logo.png": "postgresql",
    "nodejs-logo.png": "nodejs",
    "reactjs-logo.webp": "reactjs",
    "flutter-logo.png": "flutter",
    "Open-CSIRT-Logo.png": "open-csirt",
    "FIRST-Logo.png": "first",
    "CREST-Logo.png": "crest",
    "ENISA-Logo.png": "enisa",
}

# The "Our Journey of Growth" gallery. On the live site this section is a video
# plus a slider that is hidden at every breakpoint; the photographs behind it
# are the usable part.
PHOTOS = {
    "celebration-1.jpeg": "celebration-1",
    "celebration-2.jpeg": "celebration-2",
    "celebration-3.jpeg": "celebration-3",
}

# The four logo variants offered on the branding page, in the live page's
# order. "Light" and "Dark" name the theme the mark is placed ON, not the
# colour of the mark itself: the light-theme logo is dark ink for a pale
# background, and the dark-theme logo is pale ink for a dark one.
BRANDING = {
    "Tech4TimeLogo_Branding_Light_Transparent.png": "logo-light-transparent",
    "Tech4TimeLogo_Branding_Dark_Transparent.png": "logo-dark-transparent",
    "Tech4TIME_Logo_Light.png": "logo-light-background",
    "Tech4TIME_Logo_Dark.png": "logo-dark-background",
}

# Office flags on the contact page.
FLAGS = {
    "Flag_of_Bangladesh.png": "bangladesh",
    "Flag_of_Malaysia.png": "malaysia",
    "Flag_of_Belgium.jpg": "belgium",
}

# Section illustrations already staged from the live site by hand for the About
# page and the homepage cards; listed here so the whole picture is in one place.
SECTIONS = {
    "Our-Goal.jpg": "our-goal",
    "Our-Mission.jpg": "our-mission",
    "Our-Vision.jpg": "our-vision",
    "Our-Ambition.jpg": "our-ambition",
}

# (folder, mapping, rendition preference)
# Everything is served from the live site's 1024px rendition, which is ample
# for page artwork. The branding logos are the exception: they are offered as
# downloads, so they are staged from the untouched original instead.
JOBS = [
    ("clients", CLIENTS, (1024, 0)),
    ("tech", TECH, (1024, 0)),
    ("photos", PHOTOS, (1024, 0)),
    ("flags", FLAGS, (1024, 0)),
    ("branding", BRANDING, (0, 1024)),
    ("sections", SECTIONS, (1024, 0)),
]

HASH_SUFFIX = re.compile(r"-[A-Za-z0-9_-]{22}(\.\w+)$")


def index_live() -> dict:
    """Map clean basename -> {rendition size: path}."""
    found = defaultdict(dict)
    for path in LIVE.rglob("*"):
        if not path.is_file():
            continue
        try:
            size = int(path.parent.parent.name)
        except ValueError:
            continue
        found[HASH_SUFFIX.sub(r"\1", path.name)][size] = path
    return found


def best(renditions: dict, prefer: tuple = (1024, 0)) -> Path:
    """Pick a rendition. Size 0 is the untouched original."""
    for want in prefer:
        if want in renditions:
            return renditions[want]
    return renditions[max(renditions)]


def main() -> None:
    if not LIVE.is_dir():
        raise SystemExit(f"live site images not found at {LIVE}")

    live = index_live()
    total, missing = 0, []

    for folder, mapping, prefer in JOBS:
        dest = ROOT / "tools" / "masters" / folder
        dest.mkdir(parents=True, exist_ok=True)
        print(f"\n{folder}/  ({len(mapping)} files)")

        for live_name, stem in mapping.items():
            if live_name not in live:
                missing.append(f"{folder}/{live_name}")
                print(f"  MISSING  {live_name}")
                continue
            src = best(live[live_name], prefer)
            shutil.copy2(src, dest / f"{stem}{src.suffix.lower()}")
            total += 1

        print(f"  staged {len(mapping) - sum(1 for m in missing if m.startswith(folder))}")

    print(f"\n{total} images staged into tools/masters/")
    print("next: python3 tools/build_images.py")

    if missing:
        raise SystemExit(f"{len(missing)} not found on the live site:\n  " + "\n  ".join(missing))


if __name__ == "__main__":
    main()
