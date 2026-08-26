#!/usr/bin/env python3
"""
Build the set of files that goes to the web server, and prove what is in it.

Build/deploy tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/build_deploy_set.py --check        # assert, print, change nothing
    python3 tools/build_deploy_set.py --out _deploy  # build it

WHY THIS EXISTS
An upload set described by --exclude flags is correct only as long as everybody
types all of them, and the flags are not equally important: most save
bandwidth, and one is the only thing standing between a deploy and every job
post the client has written. There is no way to tell them apart by looking, and
the day one is dropped everything keeps working and the loss is silent.

So the set is built here instead, and CI rsyncs a directory rather than
assembling a rule. What may be uploaded stops being something to remember.

WHAT THE TARGET IS, AND WHY IT IS NOT THE DOCUMENT ROOT
This half deploys to /home/USER/admin.tech4time.bd/, and admin.tech4time.bd's document
root is /home/USER/admin.tech4time.bd/public/ — one level inside it. So the upload set
carries lib/ and sections/ as well as public/, and none of them is reachable
over HTTP because none of them is inside the document root. See ADR 0018.

That also means rsync --delete runs against a directory holding content/, the
system of record. It is protected the same way the frontend's is: never synced,
seeded once with --ignore-existing, and named in the deploy's protect list.

WHY AN ALLOW LIST, NOT AN IGNORE LIST
The two fail in opposite directions. Under an ignore list a new file in the
repository root ships unless somebody thought to exclude it, and the day that
file is a key, a dump or a note-to-self, it is on the internet. Under an allow
list it stays behind unless somebody thought to include it, and the day that
file is a new page, the page 404s.

One of those is discovered by a visitor; the other by a stranger. UPLOAD is
therefore exhaustive, and anything not named in it does not go.

CONTENT IS NOT PART OF THE SET
content/ is the client's data — job posts and contact details typed into
the admin — and the repository's copy is test data. It is
never synced. But the first deploy has to put something there or the two
dynamic pages have nothing to render, so it is built separately, into seed/,
and CI copies that with rsync --ignore-existing: it creates what is absent and
overwrites nothing. A file that exists on the host has been edited by somebody
and wins, permanently, without anyone deciding so on the day.
"""

import argparse
import fnmatch
import json
import shutil
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Everything that goes to the document root, named. Nothing else does.
# A directory here brings its contents, minus DENY below.
UPLOAD = [
    "public/",            # THE DOCUMENT ROOT — its .htaccess, the six entry
                          # points, and the assets a browser fetches
    "lib/",               # outside it: the sign-in, the contract, the client
    "sections/",          # outside it: included by public/index.php, never fetched
]

# Refused anywhere inside the above. Each is a thing that would otherwise be
# carried along by the directory it sits in.
DENY = [
    "*.md",               # documentation
    "*.py",               # tools that happen to sit beside site files
    "*.key",              # secret.key or publish.key, if one strays into the tree
    "admins.json",        # password hashes, likewise
    "setup-token.txt",
    "*.bak",              # content backups written by store_write()
    "*.tmp",
    ".DS_Store",
    "__pycache__/*",
]

# Absence is a broken site rather than a missing feature, so it is an error
# and not a warning. .htaccess is first for a reason: it is a dotfile, and
# both FTP clients and zip tools have been seen to drop it silently, taking
# the block on lib/ and content/ with it and leaving a site that looks fine.
REQUIRED = [
    "public/.htaccess",              # headers, and the blanket noindex
    "public/index.php",
    "public/login.php",
    "public/setup.php",
    "public/assets/css/admin.css",
    "public/assets/icons/sprite.svg",   # read from disk and inlined by lib/admin.php
    "lib/private.php",
    "lib/auth.php",
    "lib/contract.php",              # the shape both halves agree on
    "lib/publish.php",               # and the format they agree it travels in
    "lib/publish_client.php",        # without it a save writes and never sends
    "sections/careers.php",
    "sections/contact.php",
]

# Never in the set, whatever else changes. Stated separately from "not in
# UPLOAD" because that is the claim worth failing on out loud.
FORBIDDEN_TREES = ["content", "tools", "docs", "references", ".git", ".claude",
                   "deploy", "pages", "api"]

SEED = ROOT / "deploy" / "seed"


def denied(rel: str) -> bool:
    return any(fnmatch.fnmatch(rel, p) or fnmatch.fnmatch(Path(rel).name, p)
               for p in DENY)


def members() -> list[str]:
    """Every path in the upload set, relative to the document root."""
    out = []

    for entry in UPLOAD:
        src = ROOT / entry.rstrip("/")

        if not src.exists():
            raise SystemExit(f"UPLOAD names {entry!r}, which is not in the repository.")

        if src.is_file():
            if not denied(entry):
                out.append(entry)
            continue

        for path in sorted(src.rglob("*")):
            if not path.is_file():
                continue
            rel = path.relative_to(ROOT).as_posix()
            if not denied(rel):
                out.append(rel)

    return out


def build(out_dir: Path) -> list[str]:
    site = out_dir / "site"
    seed = out_dir / "seed"

    for d in (site, seed):
        if d.exists():
            shutil.rmtree(d)
        d.mkdir(parents=True)

    paths = members()

    for rel in paths:
        dst = site / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / rel, dst)

    # A BRAND NEW backend starts empty. This is not the migration path — if
    # the public site already has content, that content is the record and must
    # be copied into this host's content/ before the first save, or the first
    # save will publish an empty document over it. See
    # docs/20-deployment/first-deploy.md.
    shutil.copy2(SEED / "careers.json", seed / "careers.json")
    shutil.copy2(ROOT / "content" / "contact.json", seed / "contact.json")

    return paths


def check(paths: list[str], out_dir: Path) -> int:
    failed = []

    def assert_(case: str, ok: bool, detail: str = "") -> None:
        if ok:
            return
        failed.append(case)
        print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))

    for tree in FORBIDDEN_TREES:
        inside = [p for p in paths if p == tree or p.startswith(tree + "/")]
        assert_(f"{tree}/ is not in the upload set", not inside,
                f"{len(inside)} file(s), first: {inside[0] if inside else ''}")

    for rel in REQUIRED:
        assert_(f"{rel} is in the upload set", rel in paths)

    for pattern in DENY:
        hit = [p for p in paths if denied(p) and fnmatch.fnmatch(p, pattern)]
        assert_(f"nothing matching {pattern!r} survived", not hit,
                f"first: {hit[0] if hit else ''}")

    # Asked of the repository rather than listed above, so a library added
    # tomorrow is covered without anyone editing this file. A missing lib/ is
    # a 500 on the page that requires it and nothing at all on the others.
    for php in sorted((ROOT / "lib").glob("*.php")):
        rel = php.relative_to(ROOT).as_posix()
        assert_(f"{rel} is in the upload set", rel in paths)

    for php in sorted((ROOT / "admin").rglob("*.php")):
        rel = php.relative_to(ROOT).as_posix()
        assert_(f"{rel} is in the upload set", rel in paths)

    seeded = out_dir / "seed" / "careers.json"
    try:
        data = json.loads(seeded.read_text())
        assert_("the careers seed carries no job posts", data.get("jobs") == [],
                f"jobs: {len(data.get('jobs', []))} — a new host would launch "
                f"advertising test vacancies")
        assert_("the careers seed keeps the site-wide settings",
                "cv_form_url" in data)
    except (OSError, ValueError) as exc:
        assert_("the careers seed is readable JSON", False, str(exc))

    return len(failed)


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--out", metavar="DIR",
                    help="build into DIR/site and DIR/seed")
    ap.add_argument("--check", action="store_true",
                    help="build into a temporary directory and assert; change nothing")
    args = ap.parse_args()

    if not args.out and not args.check:
        ap.error("give --out DIR, or --check")

    with tempfile.TemporaryDirectory() as tmp:
        out_dir = Path(args.out).resolve() if args.out else Path(tmp)
        paths = build(out_dir)

        size = sum((out_dir / "site" / p).stat().st_size for p in paths)
        print(f"{len(paths)} files, {size / 1_048_576:.1f} MB")

        if args.out:
            print(f"  site  {out_dir / 'site'}")
            print(f"  seed  {out_dir / 'seed'}")

        if not args.check:
            return

        bad = check(paths, out_dir)

    total = len(FORBIDDEN_TREES) + len(REQUIRED) + len(DENY) + 2 \
        + len(list((ROOT / "lib").glob("*.php"))) \
        + len(list((ROOT / "admin").rglob("*.php")))
    print(f"\n{total - bad}/{total} checks passed")

    if bad:
        sys.exit(1)


if __name__ == "__main__":
    main()
