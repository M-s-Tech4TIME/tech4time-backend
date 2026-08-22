#!/usr/bin/env python3
"""
Preview the whole site locally, including the PHP parts.

Development tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/serve.py            # http://localhost:8000
    python3 tools/serve.py 8080       # a different port

Requires the PHP CLI:  sudo apt install php-cli

WHY NOT python3 -m http.server
Four pages need PHP now: the careers page renders job posts, the contact page
renders its addresses and numbers, the admin edits both, and the contact form
posts to a handler. A static file server shows you their source instead of
their output.

NOTHING IS FAKED ANY MORE
/admin used to be waved through here, because on the host cPanel's Directory
Privacy made Apache ask for a password before any PHP ran and there is no
Apache locally. The admin has its own accounts now, so the local sign-in is the
real one: visit /admin/setup.php once to create an account and pair an
authenticator app, then sign in as you would on the host.

The accounts, sessions and audit log go in ../t4t-private — beside this
repository, never inside it, the same shape as /home/USER/t4t-private on the
server. Delete that directory to start over.

It binds to localhost only, but it is still a real sign-in on a real port: do
not run it on a public interface.

WHAT STILL WILL NOT WORK LOCALLY
mail(). The contact form validates and answers correctly, then reports that it
could not send, because there is no mail server here — and a password reset
code has nowhere to go for the same reason. Both paths are verified on the host
with tools/host-probe.php. Locally, use a recovery code from setup instead.
"""

import os
import shutil
import signal
import socket
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ROUTER = ROOT / "tools" / "dev-router.php"

PAGES = [
    ("Home", "/"),
    ("Admin — sign in", "/admin/login.php"),
    ("Admin — first-run setup", "/admin/setup.php"),
    ("Admin — overview", "/admin/"),
    ("Admin — job posts", "/admin/?s=careers"),
    ("Admin — contact page", "/admin/?s=contact"),
    ("Admin — your account", "/admin/?s=account"),
    ("Careers  (renders content/careers.json)", "/pages/careers/"),
    ("Contact  (renders content/contact.json)", "/pages/contact/"),
    ("Resource Certifications", "/pages/resource-certifications/"),
    ("Branding & Advertisement", "/pages/branding-and-advertisement/"),
]


def port_is_free(port: int) -> bool:
    with socket.socket() as s:
        try:
            s.bind(("127.0.0.1", port))
            return True
        except OSError:
            return False


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit(
            "php not found. The site needs it for the careers page, the editor\n"
            "and the contact handler:\n"
            "  sudo apt install php-cli"
        )

    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
    if not port_is_free(port):
        raise SystemExit(f"port {port} is already in use — try: python3 tools/serve.py {port + 1}")

    base = f"http://localhost:{port}"
    width = max(len(label) for label, _ in PAGES)

    print(f"\n  Serving {ROOT}\n")
    for label, path in PAGES:
        print(f"    {label.ljust(width)}   {base}{path}")
    private = ROOT.parent / "t4t-private"
    first_run = not (private / "admins.json").is_file()

    if first_run:
        print(
            "\n  No admin account yet. Open /admin/setup.php to make one — you will\n"
            "  need an authenticator app to hand. Nothing is faked locally; this is\n"
            "  the same sign-in that runs on the host.\n"
        )
    else:
        print(f"\n  Signing in uses the account in {private}\n")

    print(
        "  Editing writes content/careers.json and content/contact.json for real.\n"
        "  Restore them with:\n"
        "    git checkout content/careers.json content/contact.json\n"
        "\n  Ctrl-C to stop.\n"
    )

    proc = subprocess.Popen(
        ["php", "-S", f"localhost:{port}", "-t", str(ROOT), str(ROUTER)],
        start_new_session=True,
    )

    try:
        proc.wait()
    except KeyboardInterrupt:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)
        print("\nstopped")


if __name__ == "__main__":
    main()
