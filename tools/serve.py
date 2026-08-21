#!/usr/bin/env python3
"""
Preview the whole site locally, including the PHP parts.

Development tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/serve.py            # http://localhost:8000
    python3 tools/serve.py 8080       # a different port

Requires the PHP CLI:  sudo apt install php-cli

WHY NOT python3 -m http.server
Three pages need PHP now: the careers page renders job posts, the admin edits
them, and the contact form posts to a handler. A static file server shows you
their source instead of their output.

WHAT IS FAKED HERE
The password on /admin. On the host, cPanel's Directory Privacy makes Apache
ask for it before any PHP runs; there is no Apache here, so tools/dev-router.php
supplies the authenticated user directly. The editor behaves exactly as it will
in production — but locally anyone who can reach the port can open it, so do
not run this on a public interface. It binds to localhost only.

WHAT STILL WILL NOT WORK LOCALLY
mail(). The contact form validates and answers correctly, then reports that it
could not send, because there is no mail server here. That path is verified on
the host with tools/mail-probe.php.
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
    ("Careers  (renders content/careers.json)", "/pages/careers/"),
    ("Job post editor", "/admin/"),
    ("Contact", "/pages/contact/"),
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
    print(
        "\n  The editor's password is FAKED locally — there is no Apache here to ask\n"
        "  for one. On the host it is cPanel > Directory Privacy that protects it.\n"
        "\n  Editing a post writes content/careers.json for real. Restore it with:\n"
        "    git checkout content/careers.json\n"
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
