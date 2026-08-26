#!/usr/bin/env python3
"""
Make the key the two halves sign content with.

Operations tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/make_publish_key.py            # create it, print it
    python3 tools/make_publish_key.py --show     # print the one already there
    python3 tools/make_publish_key.py --force    # replace it

WHY IT IS NOT MADE ON DEMAND
Every other secret in this project creates itself on first use, and this one
must not. The backend and the frontend have separate private stores on separate
hosts, so a key that appears by itself appears DIFFERENTLY on each of them —
and the two would then sign and check with different bytes for as long as it
took somebody to work out why every publish was being rejected.

So it is made once, by a person, and the same value is put in both stores. The
signature carries the key's fingerprint, so a mismatch answers "the live site
holds a different publish key" rather than "signature rejected", which is the
difference between a five-minute fix and an afternoon.

WHERE IT GOES
The private store, beside the document root, never inside it. This writes it
into THIS side's store; copy the printed value into the other side's by hand.

    frontend   /home/USER/t4t-private/publish.key
    backend    /home/USER/t4t-private-admin/publish.key

It is not a derived key and never will be. Deriving it from secret.key would
tie it to a master key the other host does not have, which is the same failure
in a more expensive form — and it would mean rotating the master key silently
broke publishing.
"""

import argparse
import os
import secrets
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def store_path() -> Path:
    """Ask lib/private.php where the store is, rather than guessing.

    The path arithmetic is its business — it has an environment override, a
    containment check and a default that differs between the two repositories.
    A second copy of that reasoning here is a second thing to get wrong.
    """
    out = subprocess.run(
        ["php", "-r", "require 'lib/private.php'; echo t4t_private_path('publish');"],
        cwd=str(ROOT), capture_output=True, text=True,
    )
    if out.returncode != 0 or not out.stdout.strip():
        raise SystemExit(
            "could not ask lib/private.php where the publish key belongs:\n"
            + (out.stderr or out.stdout)[:400]
        )
    return Path(out.stdout.strip())


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--show", action="store_true",
                    help="print the existing key without creating one")
    ap.add_argument("--force", action="store_true",
                    help="replace an existing key (both halves must be updated)")
    args = ap.parse_args()

    path = store_path()

    if path.is_file() and not args.force:
        value = path.read_text().strip()
        if args.show:
            print(value)
            return
        print(f"A publish key is already here:\n  {path}\n")
        print("  " + value)
        print("\nThe other half's store must hold exactly this value.")
        print("Use --force to replace it — and then update BOTH stores, or")
        print("every publish will be refused as 'unknown-key'.")
        return

    if args.show:
        raise SystemExit(f"There is no publish key at {path}.")

    value = secrets.token_hex(32)

    path.parent.mkdir(parents=True, exist_ok=True)
    os.chmod(path.parent, 0o700)
    path.write_text(value + "\n")
    os.chmod(path, 0o600)

    print(f"Written to {path}\n")
    print("  " + value)
    print("\nPut the SAME value in the other half's store, as publish.key,")
    print("owned by the web user and mode 600. Nothing else needs configuring.")


if __name__ == "__main__":
    main()
