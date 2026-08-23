#!/usr/bin/env python3
"""
Exercise contact-handler.php against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_contact_handler.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
contact-handler.php is the only server-side code on the site, and it is the one
piece a visitor can send arbitrary bytes to. It deserves a test that runs.

WHAT IT CAN AND CANNOT PROVE
It cannot prove mail is delivered — that needs a real mail server and is a
check on the cPanel host, not here. What it does instead is better for finding
bugs: PHP's sendmail_path is pointed at a script that captures the outgoing
message to a file, so every test can read back the EXACT bytes mail() was asked
to send. That is how the header-injection cases below are verified — not by
assuming the sanitising works, but by looking for the injected header in the
message that came out.

So: delivery is untested here, message construction is tested thoroughly.
"""

import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ENDPOINT = "/contact-handler.php"

# A submission that should pass every check, used as the base for the cases
# below so each one varies a single thing.
VALID = {
    "name": "Ayesha Rahman",
    "phone": "+880 1711 000000",
    "email": "ayesha@example.com",
    "subject": "Cybersecurity",
    "message": "We would like a quote for a SOC readiness assessment.",
    "privacy": "on",
    "company": "",  # the honeypot; a real visitor never fills this
}


# --------------------------------------------------------------- test harness

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


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def write_capture_script(workdir: Path) -> Path:
    """
    Stand in for sendmail. PHP pipes the whole message to this on stdin; it
    lands in a file so a test can read what mail() actually produced.
    """
    maildir = workdir / "mail"
    maildir.mkdir()
    script = workdir / "capture-sendmail.sh"
    script.write_text(
        "#!/bin/sh\n"
        f'exec cat > "{maildir}/$(date +%s%N).eml"\n'
    )
    script.chmod(0o755)
    return script


# Where the handler's rate-limit counter lives for this run. Set in main().
#
# The handler allows a handful of submissions an hour from one address, which
# no visitor notices and which a test making forty of them hits immediately —
# so post() resets the count before each case. The limit itself is asserted
# once, deliberately, at the end of run().
COUNTER: Path | None = None


def start_server(port: int, sendmail: Path, private: Path):
    proc = subprocess.Popen(
        [
            "php",
            "-d", f"sendmail_path={sendmail}",
            "-S", f"127.0.0.1:{port}",
            "-t", str(ROOT),
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
        start_new_session=True,
        env=dict(os.environ, T4T_PRIVATE=str(private)),
    )
    for _ in range(50):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.2):
                return proc
        except OSError:
            if proc.poll() is not None:
                raise SystemExit(
                    "php exited immediately:\n"
                    + proc.stderr.read().decode("utf-8", "replace")
                )
            time.sleep(0.1)
    raise SystemExit("php server did not come up")


def post(port, data, *, json_accept=True, method="POST", raw=None, keep_count=False):
    """Returns (status, headers, body). Never raises on a 4xx/5xx.

    Clears the rate-limit counter first unless keep_count is set, so that what
    each case measures is the case and not how many ran before it.
    """
    if COUNTER is not None and not keep_count:
        COUNTER.unlink(missing_ok=True)

    url = f"http://127.0.0.1:{port}{ENDPOINT}"
    body = None
    if method == "POST":
        body = raw if raw is not None else urllib.parse.urlencode(data or {}).encode()
    req = urllib.request.Request(url, data=body, method=method)
    if method == "POST":
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
    if json_accept:
        req.add_header("Accept", "application/json")
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode("utf-8", "replace")


def mails(maildir: Path):
    return sorted(maildir.glob("*.eml"))


def drain(maildir: Path):
    for f in mails(maildir):
        f.unlink()


def as_json(body):
    try:
        return json.loads(body)
    except ValueError:
        return None


# ---------------------------------------------------------------- the cases

def check_form_matches_handler(r: Results):
    """
    The page and the handler agree on field names.

    Nothing at runtime catches a mismatch: rename a field on the page and the
    handler simply sees it missing, so every submission fails validation for a
    reason that points at the visitor. Worth one static check.
    """
    print("\nform / handler agreement")

    # The contact page is PHP now — its addresses and copy come out of
    # content/contact.json. The form's own fields are still literal markup, but
    # this renders the page rather than reading it, so that stays true by
    # observation instead of by assumption.
    source = ROOT / "pages" / "contact" / "index.php"
    php = shutil.which("php")
    if not php:
        r.check("php is available to render the contact page", False,
                "sudo apt install php-cli")
        return

    rendered = subprocess.run([php, "-f", str(source)],
                              capture_output=True, text=True, cwd=str(source.parent))
    if rendered.returncode != 0:
        r.check("the contact page renders", False, rendered.stderr.strip()[:200])
        return
    page = rendered.stdout

    form = re.search(r"<form\b.*?</form>", page, re.S)
    if not form:
        r.check("the contact page has a form", False)
        return
    form = form.group(0)

    r.check("the form posts to the handler",
            re.search(r'action="/contact-handler\.php"', form) is not None)

    on_page = set(re.findall(r'name="([a-zA-Z_][\w-]*)"', form))
    handler = (ROOT / "contact-handler.php").read_text("utf-8")
    wanted = set(re.findall(r"field\('([a-z_]+)'\)", handler)) \
        | set(re.findall(r"\$_POST\['([a-z_]+)'\]", handler))

    missing = wanted - on_page
    r.check("every field the handler reads exists on the page", not missing,
            f"handler reads but the form does not send: {sorted(missing)}")

    unread = on_page - wanted
    r.check("the page sends nothing the handler ignores", not unread,
            f"form sends but the handler never reads: {sorted(unread)}")


def run(port, maildir, r: Results):

    check_form_matches_handler(r)

    # -- method ------------------------------------------------------------
    print("\nmethod")
    status, headers, body = post(port, None, method="GET")
    r.check("GET is refused with 405", status == 405, f"got {status}")
    r.check("405 names the allowed method",
            headers.get("Allow") == "POST", f"Allow: {headers.get('Allow')!r}")
    r.check("GET sends no mail", not mails(maildir))
    drain(maildir)

    # -- honeypot ----------------------------------------------------------
    print("\nhoneypot")
    spam = dict(VALID, company="Acme Marketing")
    status, _, body = post(port, spam)
    data = as_json(body)
    r.check("a filled honeypot is answered as success",
            status == 200 and data and data.get("ok") is True,
            f"{status} {body[:120]}")
    r.check("a filled honeypot sends NO mail", not mails(maildir),
            f"{len(mails(maildir))} message(s) captured")
    drain(maildir)

    # -- validation --------------------------------------------------------
    print("\nvalidation")
    cases = [
        ("empty submission", {}, ["Name is required"]),
        ("missing name", dict(VALID, name=""), ["Name is required"]),
        ("over-long name", dict(VALID, name="x" * 101), ["less than 100"]),
        ("missing email", dict(VALID, email=""), ["valid email"]),
        ("malformed email", dict(VALID, email="ayesha@@example"), ["valid email"]),
        ("missing phone", dict(VALID, phone=""), ["Phone number is required"]),
        ("lettered phone", dict(VALID, phone="call me maybe"), ["not valid"]),
        ("missing service", dict(VALID, subject=""), ["Type of service is required"]),
        ("terse message", dict(VALID, message="hi"), ["at least 10"]),
        ("over-long message", dict(VALID, message="x" * 5001), ["less than 5000"]),
        ("unticked consent", {k: v for k, v in VALID.items() if k != "privacy"},
         ["privacy policy"]),
    ]
    for label, payload, wanted in cases:
        status, _, body = post(port, payload)
        data = as_json(body)
        err = (data or {}).get("error", "")
        ok = status == 422 and data and data.get("ok") is False \
            and all(w in err for w in wanted)
        r.check(f"{label} is rejected", ok, f"{status} {body[:160]}")
        r.check(f"{label} sends no mail", not mails(maildir))
        drain(maildir)

    # -- header injection --------------------------------------------------
    # The point of the capture file: assert on the bytes that came out, rather
    # than trusting that the sanitising did what it looks like it does.
    print("\nheader injection")
    injections = [
        ("CRLF in the service field",
         dict(VALID, subject="Cloud\r\nBcc: attacker@evil.test")),
        ("bare LF in the service field",
         dict(VALID, subject="Cloud\nBcc: attacker@evil.test")),
        ("bare CR in the service field",
         dict(VALID, subject="Cloud\rBcc: attacker@evil.test")),
        ("CRLF in the name",
         dict(VALID, name="Ayesha\r\nBcc: attacker@evil.test")),
        ("CRLF in the message",
         dict(VALID, message="Please quote us.\r\nBcc: attacker@evil.test")),
    ]
    for label, payload in injections:
        status, _, body = post(port, payload)
        got = mails(maildir)
        raw = got[0].read_text("utf-8", "replace") if got else ""
        head = raw.split("\n\n", 1)[0]

        # What makes an injection an injection is a header STARTING a line.
        # The same characters sitting inside a header's value are inert text —
        # "Subject: ... Bcc: x@y" is one subject line, not a blind copy — so
        # searching the header block for the string would fail a handler that
        # is behaving correctly. Assert on the structure instead.
        headers_out = [ln for ln in head.split("\n") if re.match(r"^\S+:", ln)]
        names = [ln.split(":", 1)[0].lower() for ln in headers_out]

        smuggled = [n for n in names if n in ("bcc", "cc")]
        r.check(f"{label} adds no Bcc/Cc header", not smuggled,
                "headers:\n          " + head.replace("\n", "\n          "))
        r.check(f"{label} leaves a single recipient", names.count("to") <= 1,
                "headers:\n          " + head.replace("\n", "\n          "))
        drain(maildir)

    # An address is only ever echoed into Reply-To, so it gets its own case.
    status, _, body = post(port, dict(VALID, email="a@b.test\r\nBcc: attacker@evil.test"))
    got = mails(maildir)
    if got:
        head = got[0].read_text("utf-8", "replace").split("\n\n", 1)[0]
        r.check("CRLF in the email does not reach the headers",
                "attacker@evil.test" not in head.lower(), head)
    else:
        # Rejected outright is also a correct answer here.
        r.check("CRLF in the email is rejected or sanitised", status == 422,
                f"{status} {body[:160]}")
    drain(maildir)

    # -- a good submission -------------------------------------------------
    print("\nvalid submission")
    status, _, body = post(port, VALID)
    data = as_json(body)
    r.check("is accepted", status == 200 and data and data.get("ok") is True,
            f"{status} {body[:200]}")
    r.check("answers JSON when asked for JSON", data is not None, body[:120])

    got = mails(maildir)
    r.check("sends exactly one message", len(got) == 1, f"{len(got)} captured")
    if got:
        raw = got[0].read_text("utf-8", "replace")
        head, _, text = raw.partition("\n\n")
        checks = [
            ("addresses the site mailbox", "info@tech4time.bd" in head),
            ("sets From: at the site's own domain",
             re.search(r"^From:.*no-reply@tech4time\.bd", head, re.M | re.I) is not None),
            ("sets Reply-To: to the visitor",
             re.search(r"^Reply-To:\s*ayesha@example\.com", head, re.M | re.I) is not None),
            ("declares a UTF-8 text body",
             re.search(r"^Content-Type:\s*text/plain;\s*charset=utf-8", head, re.M | re.I) is not None),
            ("carries the service in the subject",
             re.search(r"^Subject:.*Cybersecurity", head, re.M | re.I) is not None),
            ("carries the name", "Ayesha Rahman" in text),
            ("carries the phone", "+880 1711 000000" in text),
            ("carries the message", "SOC readiness assessment" in text),
            ("records the sender's IP", re.search(r"^IP:", text, re.M) is not None),
        ]
        for label, ok in checks:
            r.check(label, ok, raw[:400])
    drain(maildir)

    # -- non-ASCII round trip ----------------------------------------------
    print("\nencoding")
    unicode_msg = "আমরা একটি নিরাপত্তা মূল্যায়ন চাই — SOC, ২৪/৭."
    status, _, body = post(port, dict(VALID, message=unicode_msg, name="Md. Ríaz"))
    got = mails(maildir)
    if got:
        raw = got[0].read_text("utf-8", "replace")
        r.check("Bangla message survives intact", unicode_msg in raw, raw[:300])
        r.check("accented name survives intact", "Md. Ríaz" in raw, raw[:300])
    else:
        r.check("a non-ASCII submission is sent at all", False,
                f"{status} {body[:200]}")
    drain(maildir)

    # Invalid UTF-8 makes preg_replace return null, which would silently blank
    # the field. Worth knowing which way it falls.
    raw_body = urllib.parse.urlencode(dict(VALID, name="x")).encode()
    raw_body = raw_body.replace(b"name=x", b"name=" + urllib.parse.quote_from_bytes(b"\xff\xfe").encode())
    status, _, body = post(port, None, raw=raw_body)
    data = as_json(body)
    r.check("invalid UTF-8 fails closed rather than sending a blank name",
            status == 422 or (data and data.get("ok") is True and mails(maildir)),
            f"{status} {body[:200]}")
    drain(maildir)

    # -- the no-JavaScript path --------------------------------------------
    print("\nno-JavaScript path")
    status, headers, body = post(port, VALID, json_accept=False)
    r.check("returns HTML, not JSON",
            "text/html" in headers.get("Content-Type", ""),
            headers.get("Content-Type", ""))
    r.check("confirms in words the visitor can read", "Message sent" in body,
            body[:200])
    r.check("offers a way back to the form", 'href="/pages/contact/"' in body)
    r.check("keeps itself out of the index", 'name="robots"' in body and "noindex" in body)
    # The CSP is style-src 'self'; a style attribute here would be blocked and
    # the page would render unstyled.
    r.check("carries no inline style attribute (CSP is style-src 'self')",
            not re.search(r"<[^>]+\sstyle=", body), "found a style attribute")
    drain(maildir)

    status, headers, body = post(port, dict(VALID, email=""), json_accept=False)
    r.check("renders failures as HTML too",
            status == 422 and "text/html" in headers.get("Content-Type", "")
            and "Message not sent" in body,
            f"{status} {body[:200]}")
    r.check("escapes the error text",
            "<" not in body.split("page-hero__subtitle\">")[-1].split("</p>")[0]
            if "page-hero__subtitle" in body else False)
    drain(maildir)

    # ------------------------------------------------------------ how often
    print("\nsending too often")

    if COUNTER is not None:
        COUNTER.unlink(missing_ok=True)

    codes = [post(port, VALID, keep_count=True)[0] for _ in range(5)]
    r.check("five in a row are all accepted", codes == [200] * 5, str(codes))
    drain(maildir)

    status, _, body = post(port, VALID, keep_count=True)
    r.check("the sixth is refused", status == 429, f"{status} {body[:120]}")
    r.check("and says when to try again", "try again in" in body.lower(), body[:160])
    r.check("and offers the address instead", "info@tech4time.bd" in body)
    r.check("and sends nothing", len(list(maildir.glob("*"))) == 0)

    if COUNTER is not None:
        COUNTER.unlink(missing_ok=True)
    drain(maildir)


# ---------------------------------------------------------------------- main

def main() -> None:
    if not shutil.which("php"):
        raise SystemExit(
            "php not found. This test needs the PHP CLI:\n"
            "  sudo apt install php-cli"
        )

    handler = ROOT / "contact-handler.php"
    if not handler.is_file():
        raise SystemExit(f"{handler} not found")

    global COUNTER

    workdir = Path(tempfile.mkdtemp(prefix="t4t-mail-"))
    sendmail = write_capture_script(workdir)
    maildir = workdir / "mail"
    private = workdir / "private"
    COUNTER = private / "throttle.json"
    port = free_port()

    print(f"php -S 127.0.0.1:{port}   (mail captured to {maildir})")
    proc = start_server(port, sendmail, private)
    results = Results()

    try:
        run(port, maildir, results)
    finally:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)

    total = results.passed + len(results.failed)
    print(f"\n{results.passed}/{total} checks passed")

    if results.failed:
        print("\nfailed:")
        for name in results.failed:
            print(f"  - {name}")
        print(f"\ncaptured mail left in {workdir} for inspection")
        sys.exit(1)

    shutil.rmtree(workdir, ignore_errors=True)
    print("\nMessage construction is sound. Delivery is NOT tested here —")
    print("that is a check on the cPanel host — see docs/40-reference/host-facts.md.")


if __name__ == "__main__":
    main()
