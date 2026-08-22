#!/usr/bin/env python3
"""
Exercise the admin's sign-in against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_admin_auth.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
/admin used to be protected by cPanel Directory Privacy, which meant Apache
did the checking and there was nothing here to test. It has its own accounts
now, and code that decides who may edit the website is the code most worth
proving — a mistake in it does not look like an error, it looks like a stranger
signing in.

So this drives the real flow over HTTP: first-run setup, the password, the
authenticator app, the lockout, signing out, and a whole password reset by
emailed code. The codes are generated here in Python from the same secret the
server stores, which is the only honest way to test a second factor.

WHAT IT PROVES THAT IS EASY TO GET WRONG
  - a wrong password and an unknown username give the SAME answer
  - being locked out refuses even the RIGHT password
  - the emailed code alone cannot set a new password
  - a code cannot be used twice, or in another browser
  - the stored file never contains the password

Everything runs against a private directory in /tmp that is created and thrown
away, so the account you use locally is untouched.
"""

import base64
import hashlib
import hmac
import json
import os
import re
import shutil
import signal
import socket
import struct
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ROUTER = ROOT / "tools" / "dev-router.php"

USER = "testadmin"
EMAIL = "testadmin@tech4time.bd"
PASSWORD = "a long enough test passphrase"
NEWPASSWORD = "another entirely different passphrase"


# --------------------------------------------------------------------- TOTP


def totp(secret: str, at: float | None = None, step: int = 30, digits: int = 6) -> str:
    """RFC 6238, independently of the PHP that will be checking it.

    Written out here rather than imported so that the two implementations are
    genuinely separate: if lib/totp.php drifts, this disagrees with it.
    """
    clean = re.sub(r"[^A-Za-z2-7]", "", secret).upper()
    key = base64.b32decode(clean + "=" * (-len(clean) % 8))
    counter = int((time.time() if at is None else at) // step)
    mac = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    offset = mac[-1] & 0x0F
    number = struct.unpack(">I", mac[offset:offset + 4])[0] & 0x7FFFFFFF
    return str(number % (10 ** digits)).zfill(digits)


# ------------------------------------------------------------------ harness


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []

    def check(self, case, ok, detail=""):
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))

    def section(self, name):
        print(f"\n{name}")


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def start_server(port: int, private: Path, sendmail: Path):
    env = dict(os.environ, T4T_PRIVATE=str(private))
    proc = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT),
         "-d", f"sendmail_path={sendmail}", str(ROUTER)],
        stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
        start_new_session=True, env=env,
    )
    for _ in range(60):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.2):
                return proc
        except OSError:
            if proc.poll() is not None:
                raise SystemExit("php exited:\n"
                                 + proc.stderr.read().decode("utf-8", "replace"))
            time.sleep(0.1)
    raise SystemExit("php server did not come up")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """A 302 is usually the thing being asserted, so do not follow it."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

    http_error_302 = http_error_301 = http_error_303 = http_error_307 = \
        lambda self, req, fp, code, msg, headers: None


class Client:
    """One browser: its own cookie jar, so two of these are two browsers."""

    def __init__(self, port):
        self.base = f"http://127.0.0.1:{port}"
        self.jar = CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect(),
        )

    def get(self, path):
        req = urllib.request.Request(self.base + path)
        try:
            with self.opener.open(req) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")

    def post(self, path, fields):
        data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(self.base + path, data=data, method="POST")
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            with self.opener.open(req) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")

    def session_id(self):
        for c in self.jar:
            if c.name == "t4tadm":
                return c.value
        return None


def csrf_of(html: str) -> str:
    m = re.search(r'name="csrf" value="([^"]+)"', html)
    return m.group(1) if m else ""


def setup_key_of(html: str) -> str:
    m = re.search(r'class="signin__secret-value">([^<]+)<', html)
    return re.sub(r"\s+", "", m.group(1)) if m else ""


def recovery_codes_of(html: str) -> list[str]:
    block = re.search(r'<ul class="signin__codes"[^>]*>(.*?)</ul>', html, re.S)
    return re.findall(r"<li>([A-Z0-9-]+)</li>", block.group(1)) if block else []


class Mailbox:
    def __init__(self, path: Path):
        self.path = path

    def all(self) -> list[str]:
        return [p.read_text("utf-8", "replace") for p in sorted(self.path.glob("*.txt"))]

    def latest(self) -> str:
        got = self.all()
        return got[-1] if got else ""

    def clear(self):
        for p in self.path.glob("*.txt"):
            p.unlink()

    def code(self) -> str:
        m = re.search(r"Code:\s+(\d{6})", self.latest())
        return m.group(1) if m else ""


# -------------------------------------------------------------------- tests


def test_setup(c: Client, r: Results, private: Path) -> tuple[str, list[str]]:
    r.section("first run")

    status, _, _ = c.get("/admin/")
    r.check("with no account, /admin/ sends you to setup", status == 302)

    status, _, page = c.get("/admin/setup.php")
    r.check("setup opens", status == 200 and "Set up the admin" in page)
    r.check("no setup key is demanded from the machine itself",
            'name="token"' not in page)

    token = csrf_of(page)
    status, _, page = c.post("/admin/setup.php", {
        "csrf": token, "do": "details", "user": USER, "name": "Test Admin",
        "email": EMAIL, "password": "short", "password2": "short",
    })
    r.check("a short password is refused", "at least 12 characters" in page.lower())

    status, _, page = c.post("/admin/setup.php", {
        "csrf": csrf_of(page), "do": "details", "user": USER, "name": "Test Admin",
        "email": EMAIL, "password": PASSWORD, "password2": PASSWORD + "x",
    })
    r.check("two different passwords are refused", "not the same" in page)

    status, headers, _ = c.post("/admin/setup.php", {
        "csrf": csrf_of(page), "do": "details", "user": USER, "name": "Test Admin",
        "email": EMAIL, "password": PASSWORD, "password2": PASSWORD,
    })
    r.check("good details move on to the authenticator", status == 302)

    status, _, page = c.get("/admin/setup.php")
    secret = setup_key_of(page)
    r.check("a setup key is shown", len(secret) >= 16, secret)
    r.check("with a link for apps that take one", "otpauth://totp/" in page)

    status, _, page = c.post("/admin/setup.php", {
        "csrf": csrf_of(page), "do": "enrol", "code": "000000",
    })
    r.check("a wrong code does not create the account",
            "not right" in page and not (private / "admins.json").exists())

    status, _, page = c.post("/admin/setup.php", {
        "csrf": csrf_of(page), "do": "enrol", "code": totp(secret),
    })
    r.check("the right code creates it", status == 302)

    status, _, page = c.get("/admin/setup.php")
    codes = recovery_codes_of(page)
    r.check("ten recovery codes are shown once", len(codes) == 10, str(codes))

    stored = (private / "admins.json").read_text()
    r.check("the account file exists", USER in stored)
    r.check("and holds no plaintext password", PASSWORD not in stored)
    r.check("and holds an argon2id hash", "$argon2id$" in stored)
    r.check("and holds no plaintext recovery code",
            all(code not in stored for code in codes))
    r.check("the setup key file is gone", not (private / "setup-token.txt").exists())

    status, _, _ = c.post("/admin/setup.php", {"csrf": csrf_of(page), "do": "finish"})
    r.check("finishing sends you to sign in", status == 302)

    status, _, _ = c.get("/admin/setup.php")
    r.check("setup refuses to run a second time", status == 302)

    return secret, codes


def sign_in(c: Client, secret: str, password: str = PASSWORD, code: str | None = None):
    """Both steps, as (status, headers, page).

    status is None when the password step never got as far as asking for a
    code — which is itself the assertion in several tests below.
    """
    _, _, page = c.get("/admin/login.php")
    _, _, page = c.post("/admin/login.php", {
        "csrf": csrf_of(page), "do": "password", "user": USER, "password": password,
    })
    if "Two-step check" not in page:
        return None, {}, page
    return c.post("/admin/login.php", {
        "csrf": csrf_of(page), "do": "second",
        "code": fresh_code(secret) if code is None else code,
    })


def test_login(c: Client, r: Results, secret: str):
    r.section("signing in")

    status, _, page = c.get("/admin/")
    r.check("signed out, /admin/ sends you to the login page", status == 302)

    _, _, page = c.get("/admin/login.php")
    r.check("the login page opens", "Sign in" in page)
    r.check("and offers a way through a forgotten password", "forgot.php" in page)

    _, _, bad_user = c.post("/admin/login.php", {
        "csrf": csrf_of(page), "do": "password",
        "user": "nobody-at-all", "password": "whatever it is",
    })
    _, _, bad_pass = c.post("/admin/login.php", {
        "csrf": csrf_of(bad_user), "do": "password",
        "user": USER, "password": "not the password",
    })
    wrong = "do not match"
    r.check("an unknown username is refused", wrong in bad_user)
    r.check("a wrong password is refused", wrong in bad_pass)
    r.check("and the two say exactly the same thing",
            bad_user.count(wrong) == bad_pass.count(wrong) == 1)

    _, _, page = c.post("/admin/login.php", {
        "csrf": csrf_of(bad_pass), "do": "password",
        "user": USER, "password": PASSWORD,
    })
    r.check("the right password asks for the app", "Two-step check" in page)
    r.check("and does not sign you in on its own", "admin-bar" not in page)

    _, _, page = c.post("/admin/login.php", {
        "csrf": csrf_of(page), "do": "second", "code": "000000",
    })
    r.check("a wrong code is refused", "not right" in page)

    before = c.session_id()
    status, headers, _ = c.post("/admin/login.php", {
        "csrf": csrf_of(page), "do": "second", "code": fresh_code(secret),
    })
    r.check("the right code signs you in", status == 302)
    r.check("the session id is replaced on the way", c.session_id() != before)

    cookie = headers.get("Set-Cookie", "")
    r.check("the session cookie is HttpOnly", "HttpOnly" in cookie, cookie)
    r.check("and SameSite", "SameSite" in cookie, cookie)

    status, headers, page = c.get("/admin/")
    r.check("the overview now opens", status == 200 and "admin-bar" in page)
    r.check("it is not stored in a shared cache",
            "no-store" in headers.get("Cache-Control", ""))
    r.check("it names who is signed in", USER in page)

    for path, want in [("/admin/?s=careers", "Job posts"),
                       ("/admin/?s=contact", "Reach us directly"),
                       ("/admin/?s=account", "Recovery codes")]:
        status, _, page = c.get(path)
        r.check(f"{path} opens", status == 200 and want.lower() in page.lower())


def test_signout(c: Client, r: Results, secret: str):
    r.section("signing out")

    status, _, page = c.get("/admin/")
    token = csrf_of(page)

    status, _, _ = c.get("/admin/logout.php")
    r.check("a link cannot sign you out", status in (302, 405))
    status, _, _ = c.get("/admin/")
    r.check("so you are still signed in", status == 200)

    status, _, _ = c.post("/admin/logout.php", {"csrf": "wrong"})
    r.check("signing out without a token is refused", status == 400)

    status, _, _ = c.post("/admin/logout.php", {"csrf": token})
    r.check("signing out with one works", status == 302)

    status, _, _ = c.get("/admin/")
    r.check("and /admin/ sends you back to the login page", status == 302)


_spent = {"counter": -1}


def fresh_code(secret: str) -> str:
    """A code the server has not already accepted.

    Now that a code really is good only once, any test that signs in twice
    inside the same thirty seconds would be refused — correctly, and for a
    reason that has nothing to do with what it was checking. This waits for a
    new step when the last one has been used, so a failure here always means
    what it says.
    """
    counter = int(time.time() // 30)

    if counter <= _spent["counter"]:
        next_step()
        counter = int(time.time() // 30)

    _spent["counter"] = counter
    return totp(secret, at=counter * 30)


def next_step():
    """Wait for a new 30-second step to begin.

    Two reasons, and both would otherwise make this test lie. An earlier test
    has already signed in during the current step, so its code is spent and a
    replay test starting here would fail for the wrong reason. And a step that
    is nearly over would see the second attempt refused because time passed
    rather than because the code was used — which would go on passing with the
    replay defence taken out.

    Costs up to thirty seconds, once, and leaves a full window to work in.
    """
    time.sleep(30 - (time.time() % 30) + 0.2)


def test_totp_replay(c: Client, r: Results, secret: str, private: Path):
    r.section("a code is good once")

    (private / "throttle.json").unlink(missing_ok=True)
    next_step()

    code = fresh_code(secret)
    status, _, _ = sign_in(c, secret, code=code)
    r.check("a fresh code signs you in", status == 302)

    c.post("/admin/logout.php", {"csrf": csrf_of(c.get("/admin/")[2])})
    (private / "throttle.json").unlink(missing_ok=True)

    status, _, _ = sign_in(c, secret, code=code)
    r.check("the very same code will not do it again",
            status != 302, "a captured code could be replayed inside its 30 seconds")


def test_csrf_and_redirect(c: Client, r: Results):
    r.section("tokens and redirects")

    status, _, _ = c.post("/admin/login.php", {
        "do": "password", "user": USER, "password": PASSWORD,
    })
    r.check("posting to the login page without a token is refused", status == 400)

    _, _, page = c.get("/admin/login.php?next=https://example.com/")
    r.check("an off-site next= is dropped", "https://example.com" not in page)

    _, _, page = c.get("/admin/login.php?next=//example.com/")
    r.check("a protocol-relative next= is dropped", "//example.com" not in page)

    _, _, page = c.get("/admin/login.php?next=%2Fadmin%2F%3Fs%3Dcontact")
    r.check("an in-admin next= is kept", "/admin/?s=contact" in page)


def test_lockout(c: Client, r: Results, secret: str, private: Path):
    r.section("guessing costs something")

    (private / "throttle.json").unlink(missing_ok=True)

    _, _, page = c.get("/admin/login.php")

    # AUTH_ALLOW failures are free; the wait starts on the one after.
    for _ in range(6):
        _, _, page = c.post("/admin/login.php", {
            "csrf": csrf_of(page), "do": "password",
            "user": USER, "password": "wrong every time",
        })
    r.check("six wrong passwords are each just refused", "do not match" in page)

    _, _, page = c.post("/admin/login.php", {
        "csrf": csrf_of(page), "do": "password",
        "user": USER, "password": "wrong every time",
    })
    r.check("the seventh is made to wait", "Try again in" in page)

    _, _, page = c.post("/admin/login.php", {
        "csrf": csrf_of(page), "do": "password", "user": USER, "password": PASSWORD,
    })
    r.check("and the RIGHT password is refused while locked out",
            "Try again in" in page and "Two-step check" not in page)

    (private / "throttle.json").unlink(missing_ok=True)
    _, _, page = c.post("/admin/login.php", {
        "csrf": csrf_of(page), "do": "password", "user": USER, "password": PASSWORD,
    })
    r.check("once the wait is over it works again", "Two-step check" in page)


def test_reset(c: Client, r: Results, mail: Mailbox, secret: str, private: Path):
    r.section("forgetting the password")

    (private / "throttle.json").unlink(missing_ok=True)
    mail.clear()

    _, _, page = c.get("/admin/forgot.php")
    r.check("the forgotten-password page opens", "Forgotten password" in page)

    status, headers, _ = c.post("/admin/forgot.php", {
        "csrf": csrf_of(page), "who": "somebody-who-does-not-exist",
    })
    unknown_to = headers.get("Location", "")
    r.check("an unknown account is accepted without comment", status == 302)
    r.check("and no mail is sent for it", mail.all() == [])

    _, _, page = c.get("/admin/forgot.php")
    status, headers, _ = c.post("/admin/forgot.php", {
        "csrf": csrf_of(page), "who": USER,
    })
    r.check("a real account is answered identically",
            status == 302 and headers.get("Location", "") == unknown_to)
    r.check("and a code is emailed", len(mail.all()) == 1)

    body = mail.latest()
    code = mail.code()
    r.check("the message carries six digits", len(code) == 6)
    r.check("it goes to the address on the account, not the one typed",
            EMAIL in body)
    r.check("it is sent from our own domain, for SPF",
            "no-reply@tech4time.bd" in body)
    r.check("and says the code alone is not enough",
            "cannot change your password" in body.lower())

    stored = (private / "resets.json").read_text()
    r.check("the code is stored only as a hash", code not in stored)

    _, _, page = c.get("/admin/reset.php?sent=1")
    r.check("the reset page says a code is on its way", "on its way" in page)

    _, _, page = c.post("/admin/reset.php", {
        "csrf": csrf_of(page), "do": "code", "code": "000000",
    })
    r.check("a wrong code is refused", "not right" in page)
    r.check("and says how many tries are left", "tries left" in page)

    # A second browser, which never asked for this code.
    other = Client(int(c.base.rsplit(":", 1)[1]))
    _, _, opage = other.get("/admin/reset.php")
    _, _, opage = other.post("/admin/reset.php", {
        "csrf": csrf_of(opage), "do": "code", "code": code,
    })
    r.check("the code does not work in another browser", "not right" in opage)

    status, _, _ = c.post("/admin/reset.php", {
        "csrf": csrf_of(page), "do": "code", "code": code,
    })
    r.check("the right code is accepted", status == 302)

    _, _, page = c.get("/admin/reset.php")
    r.check("which asks for the app AND a new password",
            "Authenticator code" in page and "New password" in page)

    _, _, page = c.post("/admin/reset.php", {
        "csrf": csrf_of(page), "do": "finish", "second": "000000",
        "password": NEWPASSWORD, "password2": NEWPASSWORD,
    })
    r.check("an emailed code alone will NOT set a password",
            "authenticator code is not right" in page.lower())

    _, _, page = c.post("/admin/reset.php", {
        "csrf": csrf_of(page), "do": "finish", "second": totp(secret),
        "password": "short", "password2": "short",
    })
    r.check("a weak new password is refused", "at least 12" in page.lower())

    mail.clear()
    status, headers, page = c.post("/admin/reset.php", {
        "csrf": csrf_of(page), "do": "finish", "second": fresh_code(secret),
        "password": NEWPASSWORD, "password2": NEWPASSWORD,
    })
    r.check("app plus code plus a good password does set it",
            status == 302 and "reset=1" in headers.get("Location", ""))
    r.check("and a notice is emailed about it",
            "was changed" in mail.latest())

    # A fresh page: the successful reset replaced the session id, so the token
    # from before it is no longer the one this session carries.
    _, _, page = c.get("/admin/reset.php")
    _, _, page = c.post("/admin/reset.php", {
        "csrf": csrf_of(page), "do": "code", "code": code,
    })
    r.check("the used code cannot be used again", "not right" in page)

    (private / "throttle.json").unlink(missing_ok=True)
    status, _, _ = sign_in(c, secret, password=PASSWORD)
    r.check("the old password no longer works", status is None)

    (private / "throttle.json").unlink(missing_ok=True)
    status, _, _ = sign_in(c, secret, password=NEWPASSWORD)
    r.check("the new one does", status == 302)


def test_recovery_code(c: Client, r: Results, codes: list[str], private: Path):
    r.section("recovery codes")

    (private / "throttle.json").unlink(missing_ok=True)
    c.post("/admin/logout.php", {"csrf": csrf_of(c.get("/admin/")[2])})

    status, _, _ = sign_in(c, "", password=NEWPASSWORD, code=codes[0])
    r.check("a recovery code stands in for the app", status == 302)

    status, _, _ = c.get("/admin/")
    r.check("and really signs you in", status == 200)

    c.post("/admin/logout.php", {"csrf": csrf_of(c.get("/admin/")[2])})
    (private / "throttle.json").unlink(missing_ok=True)

    status, _, _ = sign_in(c, "", password=NEWPASSWORD, code=codes[0])
    r.check("the same code will not work twice", status != 302)

    (private / "throttle.json").unlink(missing_ok=True)
    status, _, _ = sign_in(c, "", password=NEWPASSWORD, code=codes[1])
    r.check("but the next one does", status == 302)


def test_audit(r: Results, private: Path, codes: list[str], secret: str):
    r.section("the record")

    lines = [json.loads(l) for l in
             (private / "audit.log").read_text().strip().splitlines() if l.strip()]
    events = {row.get("event") for row in lines}

    for want in ["setup-complete", "login", "login-failed", "logout",
                 "login-throttled", "second-factor-failed", "reset-code-sent",
                 "reset-request-unknown", "password-reset", "recovery-code-used"]:
        r.check(f"records {want}", want in events, str(sorted(events)))

    raw = (private / "audit.log").read_text()
    r.check("holds no password", PASSWORD not in raw and NEWPASSWORD not in raw)
    r.check("holds no recovery code", all(c not in raw for c in codes))
    r.check("holds no authenticator secret", secret not in raw)


def test_refuses_bad_setup(r: Results, sendmail: Path):
    r.section("refusing to run unsafely")

    port = free_port()
    inside = ROOT / "content" / ".test-private"
    env = dict(os.environ, T4T_PRIVATE=str(inside))
    proc = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT),
         "-d", f"sendmail_path={sendmail}", str(ROUTER)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        start_new_session=True, env=env,
    )
    try:
        for _ in range(60):
            try:
                with socket.create_connection(("127.0.0.1", port), 0.2):
                    break
            except OSError:
                time.sleep(0.1)

        status, _, page = Client(port).get("/admin/")
        r.check("a private directory inside the web root is refused",
                status == 503, f"status {status}")
        r.check("and it says why", "cannot start safely" in page)
        r.check("and stays out of search results", 'content="noindex' in page)
    finally:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)
        shutil.rmtree(inside, ignore_errors=True)


# --------------------------------------------------------------------- main


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found. This test needs the PHP CLI:\n"
                         "  sudo apt install php-cli")

    work = Path(tempfile.mkdtemp(prefix="t4t-auth-"))
    private = work / "private"
    maildir = work / "mail"
    maildir.mkdir(parents=True)

    sendmail = work / "sendmail.sh"
    sendmail.write_text(
        "#!/bin/sh\n"
        f'cat > "{maildir}/mail-$$-$(date +%s%N).txt"\n'
    )
    sendmail.chmod(0o755)

    port = free_port()
    print(f"php -S 127.0.0.1:{port}   (private store in {private})")

    proc = start_server(port, private, sendmail)
    r = Results()
    mail = Mailbox(maildir)

    try:
        c = Client(port)
        secret, codes = test_setup(c, r, private)
        test_login(c, r, secret)
        test_signout(c, r, secret)
        test_totp_replay(c, r, secret, private)
        test_csrf_and_redirect(c, r)
        test_lockout(c, r, secret, private)
        test_reset(c, r, mail, secret, private)
        test_recovery_code(c, r, codes, private)
        test_audit(r, private, codes, secret)
    finally:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)

    test_refuses_bad_setup(r, sendmail)

    shutil.rmtree(work, ignore_errors=True)

    total = r.passed + len(r.failed)
    print(f"\n{r.passed}/{total} checks passed")

    if r.failed:
        print("\nfailed:")
        for name in r.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
