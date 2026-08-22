#!/usr/bin/env python3
"""
Exercise the job post editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_careers_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
admin/index.php writes content/careers.json, and pages/careers/index.php
renders whatever it finds there. Code that writes files is worth a test: a bug
in the save path does not show up as an error, it shows up as a job post that
quietly disappeared.

Every test runs against a COPY of the real data file, which is restored
afterwards whether the run passes or fails.

WHAT IT CANNOT COVER
The cPanel Directory Privacy that protects /admin in production. The test
harness supplies REMOTE_USER itself, which is exactly what Apache does once
the directory is protected — so what is tested is the editor's behaviour after
authentication, not the authentication.
"""

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
from http.cookiejar import CookieJar
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "content" / "careers.json"

# The admin gained a second editor and an icon rail, so /admin/ is now the
# overview and each editor has its own address. The job posts are here.
ADMIN = "/admin/?s=careers"

ROUTER = """<?php
/* Test harness only. Stands in for the Basic auth that cPanel Directory
   Privacy applies to /admin in production. */
$_SERVER['REMOTE_USER'] = 'testadmin';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/admin')) {
    require __DIR__ . '/admin/index.php';
    return true;
}
if (rtrim($path, '/') === '/pages/careers') {
    require __DIR__ . '/pages/careers/index.php';
    return true;
}
return false;
"""


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


def start_server(port: int, router: Path):
    proc = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT), str(router)],
        stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, start_new_session=True,
    )
    for _ in range(50):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.2):
                return proc
        except OSError:
            if proc.poll() is not None:
                raise SystemExit("php exited:\n" + proc.stderr.read().decode("utf-8", "replace"))
            time.sleep(0.1)
    raise SystemExit("php server did not come up")


class Client:
    """Keeps the session cookie, which is what carries the CSRF token."""

    def __init__(self, port):
        self.base = f"http://127.0.0.1:{port}"
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(CookieJar()),
            NoRedirect(),
        )

    def get(self, path):
        try:
            with self.opener.open(self.base + path) as r:
                return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    def post(self, path, fields):
        data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(self.base + path, data=data, method="POST")
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            with self.opener.open(req) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """A 302 after a save is the thing being asserted, so do not follow it."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

    http_error_302 = http_error_301 = http_error_303 = http_error_307 = \
        lambda self, req, fp, code, msg, headers: None


def csrf_of(html: str) -> str:
    m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    return m.group(1) if m else ""


def job_ids() -> list:
    import json
    return [j.get("id") for j in json.loads(DATA.read_text())["jobs"]]


def statuses() -> dict:
    import json
    return {j["id"]: j.get("status") for j in json.loads(DATA.read_text())["jobs"]}


SANITISER_CASES = [
    ("a script tag and its contents", '<p>ok</p><script>alert(1)</script>', 'alert'),
    ("an event handler", '<p onclick="steal()">hi</p>', 'onclick'),
    ("an inline style", '<p style="text-align:center">hi</p>', 'style'),
    ("a javascript: link", '<a href="javascript:alert(1)">x</a>', 'javascript'),
    ("a tab-obfuscated javascript: link", '<a href="java&#09;script:alert(1)">x</a>', 'script:'),
    ("a data: link", '<a href="data:text/html,<b>x</b>">y</a>', 'data:'),
    ("an iframe", '<iframe src="//evil.test"></iframe>', 'iframe'),
    ("an img onerror", '<img src=x onerror=alert(1)>', 'onerror'),
    ("an svg animate payload", '<svg><animate onbegin=alert(1)></svg>', 'onbegin'),
    ("a form", '<form action="//evil.test"><input name=p></form>', '<form'),
    ("a class that is not an alignment", '<p class="evil ta-center">x</p>', 'evil'),
    ("a style block and its contents", '<style>body{display:none}</style><p>x</p>', 'display:none'),
    ("a meta refresh", '<meta http-equiv="refresh" content="0;url=//e.test">', 'refresh'),
    ("a base tag", '<base href="//evil.test/">', '<base'),
]

KEEP_CASES = [
    ("plain text in a paragraph", '<p>plain</p>', '<p>plain</p>'),
    ("bold, italic and underline", '<p><strong>b</strong><em>i</em><u>u</u></p>',
     '<p><strong>b</strong><em>i</em><u>u</u></p>'),
    ("a bulleted list", '<ul><li>one</li></ul>', '<ul><li>one</li></ul>'),
    ("a numbered list", '<ol><li>one</li></ol>', '<ol><li>one</li></ol>'),
    ("an alignment class", '<p class="ta-center">x</p>', '<p class="ta-center">x</p>'),
    ("b and i normalised to strong and em", '<p><b>x</b><i>y</i></p>',
     '<p><strong>x</strong><em>y</em></p>'),
    ("an unclosed tag is balanced", '<p>x', '<p>x</p>'),
    ("entities are not double-encoded", '<p>a &amp; b</p>', '<p>a &amp; b</p>'),
]


def check_sanitiser(r: Results):
    """
    Run careers_sanitise_html() directly.

    This is the one boundary that matters: whatever it returns is printed on
    the public page without escaping. The editor's own restrictions are a
    convenience for whoever is typing and are trivially bypassed by posting to
    the endpoint directly, so the assertions belong here.
    """
    print("\nsanitiser — what must not survive")

    script = (
        "<?php require 'lib/careers.php';\n"
        "$in = stream_get_contents(STDIN);\n"
        "echo careers_sanitise_html($in);"
    )

    def clean(markup: str) -> str:
        out = subprocess.run(
            ["php", "-r", script.replace("<?php ", "")],
            input=markup, capture_output=True, text=True, cwd=str(ROOT),
        )
        return out.stdout

    for label, payload, forbidden in SANITISER_CASES:
        out = clean(payload)
        r.check(f"{label} is removed", forbidden.lower() not in out.lower(),
                f"survived as: {out}")

    print("\nsanitiser — what must survive")
    for label, payload, expected in KEEP_CASES:
        out = clean(payload)
        r.check(f"{label} is kept", out == expected, f"got: {out}")


def run(client: Client, r: Results):
    check_sanitiser(r)

    NEW = {
        "title": "Test Automation Engineer",
        "employment_type": "Full-Time",
        "work_arrangement": "Remote",
        "location": "Dhaka, Bangladesh",
        "salary": "Negotiable",
        "posted": "2026-08-21",
        "closes": "",
        "status": "open",
        "apply_url": "https://forms.gle/exampleTEST123",
        "about": "<p>First paragraph about the role.</p>"
                 "<p class=\"ta-center\">Second paragraph, centred.</p>",
        "responsibilities": "<ul><li>Write <strong>tests</strong>.</li>"
                            "<li>Run tests.</li><li>Read the failures.</li></ul>",
        "requirements": "<ol><li>Patience.</li></ol>",
        "must_have": "",
        "nice_to_have": "",
        "certifications": "<p>An <em>example</em> with a "
                          "<a href=\"https://example.com\">link</a>.</p>",
        "offers": "<ul><li>Coffee.</li></ul>",
    }

    print("\nreading")
    status, html = client.get(ADMIN)
    r.check("the editor loads once authenticated", status == 200 and "Job posts" in html,
            f"{status}")
    token = csrf_of(html)
    r.check("it issues a CSRF token", len(token) == 64, token[:20])

    before = job_ids()

    print("\ncreating")
    status, headers, _ = client.post(ADMIN, dict(NEW, action="save", csrf=token, id=""))
    r.check("a new post redirects rather than re-rendering",
            status == 302, f"{status}")
    ids = job_ids()
    r.check("it is written to careers.json", len(ids) == len(before) + 1, str(ids))
    r.check("its id is derived from the title",
            "test-automation-engineer" in ids, str(ids))

    status, page = client.get("/pages/careers/")
    r.check("it appears on the careers page", "Test Automation Engineer" in page)
    r.check("its bullets render as list items", page.count("<li>") >= 4, page[:0])
    r.check("its paragraphs survive the round trip",
            "<p>First paragraph about the role.</p>" in page)
    r.check("bold survives the round trip",
            "<strong>tests</strong>" in page)
    r.check("a numbered list stays numbered", "<ol><li>Patience.</li></ol>" in page)
    r.check("an alignment class survives",
            'class="ta-center"' in page, "alignment must arrive as a class, not a style")
    r.check("no inline style reaches the page (CSP is style-src 'self')",
            not re.search(r"<[^>]+\sstyle=", page), "an inline style would be blocked")
    r.check("an author link opens safely",
            'href="https://example.com" target="_blank" rel="noopener noreferrer"' in page)
    r.check("it carries a JobPosting for Google Jobs",
            '"title": "Test Automation Engineer"' in page)
    r.check("a role with no closing date emits no validThrough",
            page.count('"validThrough"') == 0, "an empty date must not be published")

    print("\nvalidating")
    status, _, html = client.post(ADMIN, dict(NEW, action="save", csrf=token, id="",
                                                  title=""))
    r.check("a post with no title is refused", "A job title is required" in html)
    status, _, html = client.post(ADMIN, dict(NEW, action="save", csrf=token, id="",
                                                  apply_url="not-a-url"))
    r.check("a post with a broken apply link is refused",
            "must be a full URL" in html, html[:200])
    status, _, html = client.post(ADMIN, dict(NEW, action="save", csrf=token, id="",
                                                  closes="31-10-2026"))
    r.check("a misformatted closing date is refused", "YYYY-MM-DD" in html)
    r.check("none of those wrote anything", len(job_ids()) == len(before) + 1)

    print("\npublishing")
    client.post(ADMIN, {"action": "toggle", "csrf": token,
                            "id": "test-automation-engineer"})
    r.check("unpublishing sets the post to draft",
            statuses().get("test-automation-engineer") == "draft", str(statuses()))
    _, page = client.get("/pages/careers/")
    r.check("a draft is hidden from visitors", "Test Automation Engineer" not in page)
    r.check("a draft emits no JobPosting either",
            '"Test Automation Engineer"' not in page)

    client.post(ADMIN, {"action": "toggle", "csrf": token,
                            "id": "test-automation-engineer"})
    r.check("publishing brings it back",
            statuses().get("test-automation-engineer") == "open")

    print("\nordering")
    ids = job_ids()
    if len(ids) >= 2:
        last = ids[-1]
        client.post(ADMIN, {"action": "move", "csrf": token, "id": last,
                                "direction": "up"})
        r.check("moving up reorders the file", job_ids()[-2] == last, str(job_ids()))
        client.post(ADMIN, {"action": "move", "csrf": token, "id": ids[0],
                                "direction": "up"})
        r.check("moving the first post up is a no-op, not an error",
                job_ids()[0] == ids[0], str(job_ids()))

    print("\nCSRF")
    status, _, _ = client.post(ADMIN, {"action": "delete", "csrf": "wrong",
                                           "id": "test-automation-engineer"})
    r.check("a request with a bad token is rejected", status == 400, f"{status}")
    r.check("and nothing was deleted", "test-automation-engineer" in job_ids())

    print("\ndeleting")
    client.post(ADMIN, {"action": "delete", "csrf": token,
                            "id": "test-automation-engineer"})
    r.check("the post is removed", "test-automation-engineer" not in job_ids())
    r.check("the others are untouched", job_ids() and len(job_ids()) == len(before),
            str(job_ids()))
    r.check("a backup of the previous version exists",
            (DATA.parent / "careers.json.bak").is_file())

    print("\nempty state")
    for jid in list(job_ids()):
        client.post(ADMIN, {"action": "delete", "csrf": token, "id": jid})
    _, page = client.get("/pages/careers/")
    r.check("with no posts the page invites a CV instead",
            "Stay Tuned for Opportunities" in page and "empty-state" in page)
    r.check("and emits no JobPosting", '"JobPosting"' not in page)
    r.check("the CV form link still shows", "forms.gle" in page)


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found. This test needs the PHP CLI:\n"
                         "  sudo apt install php-cli")
    if not DATA.is_file():
        raise SystemExit(f"{DATA} not found")

    backup = DATA.read_bytes()
    router = ROOT / f".test-router-{os.getpid()}.php"
    router.write_text(ROUTER)
    port = free_port()

    print(f"php -S 127.0.0.1:{port}   (content/careers.json is restored afterwards)")
    proc = start_server(port, router)
    results = Results()

    try:
        run(Client(port), results)
    finally:
        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        proc.wait(timeout=5)
        router.unlink(missing_ok=True)
        DATA.write_bytes(backup)
        (DATA.parent / "careers.json.bak").unlink(missing_ok=True)
        print("\ncontent/careers.json restored")

    total = results.passed + len(results.failed)
    print(f"\n{results.passed}/{total} checks passed")

    if results.failed:
        print("\nfailed:")
        for name in results.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
