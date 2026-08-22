#!/usr/bin/env python3
"""
Give a test an admin account, and sign it in.

Development tool. NOT deployed to the web server (see tools/README.md).
Imported by the editor tests; not run on its own.

WHY THIS EXISTS
The editor tests used to write $_SERVER['REMOTE_USER'] into their router and
call it authentication, because that is genuinely what Apache did once cPanel's
Directory Privacy was switched on. The admin has its own accounts now, so there
is nothing to stand in for: a test that wants to reach the editors has to sign
in the way a person does.

Each test gets a private directory of its own under /tmp, so the account it
makes cannot collide with another test running beside it or with whatever
account you use locally.

WHAT IT DELIBERATELY DOES NOT DO
Drive /admin/setup.php. Those four screens are the subject of
tools/test_admin_auth.py, and making every other test walk through them would
mean a change to the setup wording broke tests about job posts. This writes the
account straight into the store through the same functions setup.php uses, then
signs in over HTTP like anybody else.
"""

import base64
import hashlib
import hmac
import re
import os
import struct
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

USER = "testadmin"
PASSWORD = "a long enough test passphrase"

# Runs with the repo as the working directory, so lib/ resolves.
_PROVISION = """
require 'lib/auth.php';
$secret = totp_secret();
auth_put(auth_defaults([
    'user'             => $argv[1],
    'name'             => 'Test Admin',
    'email'            => $argv[1] . '@tech4time.bd',
    'hash'             => auth_password_hash($argv[2]),
    'totp'             => $secret,
    'created'          => gmdate('c'),
    'password_changed' => gmdate('c'),
]));
echo $secret;
"""


def totp(secret: str, at: float | None = None) -> str:
    """RFC 6238, six digits, thirty-second step."""
    clean = re.sub(r"[^A-Za-z2-7]", "", secret).upper()
    key = base64.b32decode(clean + "=" * (-len(clean) % 8))
    counter = int((time.time() if at is None else at) // 30)
    mac = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    offset = mac[-1] & 0x0F
    number = struct.unpack(">I", mac[offset:offset + 4])[0] & 0x7FFFFFFF
    return str(number % 1000000).zfill(6)


def make_account(private: Path, user: str = USER, password: str = PASSWORD) -> str:
    """Create the account. Returns its authenticator secret."""
    done = subprocess.run(
        ["php", "-r", _PROVISION, "--", user, password],
        cwd=str(ROOT), capture_output=True, text=True,
        env=dict(os.environ, T4T_PRIVATE=str(private)),
    )

    if done.returncode != 0 or not done.stdout.strip():
        raise SystemExit("could not create the test admin account:\n"
                         + (done.stderr or done.stdout))

    return done.stdout.strip()


def _csrf(html: str) -> str:
    m = re.search(r'name="csrf" value="([^"]+)"', html)
    return m.group(1) if m else ""


def sign_in(opener, base: str, secret: str,
            user: str = USER, password: str = PASSWORD) -> None:
    """Both steps of the real login, through the caller's cookie jar.

    Takes the opener rather than a test's own client wrapper, because the three
    tests that need this each have their own and they do not agree on what a
    response looks like. Every one of them builds its opener the same way.
    """
    def fetch(path, fields=None):
        url = base + path
        if fields is None:
            req = urllib.request.Request(url)
        else:
            req = urllib.request.Request(
                url, data=urllib.parse.urlencode(fields).encode(), method="POST")
            req.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            with opener.open(req) as r:
                return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    _, page = fetch("/admin/login.php")
    _, page = fetch("/admin/login.php",
                    {"csrf": _csrf(page), "do": "password",
                     "user": user, "password": password})

    if "Two-step check" not in page:
        raise SystemExit("the test account could not get past the password step")

    status, page = fetch("/admin/login.php",
                         {"csrf": _csrf(page), "do": "second", "code": totp(secret)})

    if status != 302:
        raise SystemExit("the test account could not get past the second factor")

