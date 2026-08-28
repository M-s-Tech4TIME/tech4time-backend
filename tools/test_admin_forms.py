#!/usr/bin/env python3
"""
Drive the editors in a real browser and prove they submit without navigating.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_admin_forms.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing, so it does not block a machine that only has PHP.

WHY A BROWSER
The whole subject is what a click does to the page, and none of it is visible
from the server: the request admin-forms.js sends is byte for byte the request
the browser would have sent, and the response is the same page. What changed is
that the answer is put back in place instead of replacing the document — so the
things worth asserting are the scroll position, where the focus went, and that
the document was never navigated. A PHP test cannot see any of those.

WHAT IS BEING PROTECTED
Every control in these editors is a submit button. Before this, each one was a
full navigation, and a navigation lands at the top: pressing "Move down" on the
fiftieth technology logo returned you to the page title, thousands of pixels
away. The effect was bad enough that the company editor was reported as not
containing its own data — the only part anybody ever saw was the first field
group.

AND THAT IT STILL WORKS WITHOUT SCRIPT
The last group here disables JavaScript and does the same edits again. That is
the hard rule this project holds to, and it is the reason this was built as a
swap over the ordinary form post rather than as an endpoint of its own.
"""

import json
import os
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import admin_session  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DOCROOT = ROOT / "public"
ROUTER = ROOT / "tools" / "dev-router.php"
DATA = ROOT / "content" / "company.json"


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


def fresh_code(secret: str, used: str) -> str:
    """Wait until the authenticator says something new.

    lib/auth.php refuses a six-digit code that has already been presented
    inside the thirty seconds it is valid for — deliberately, and it is worth
    keeping. This file signs in twice, once per browser, so the second
    sign-in has to wait for the window to roll over. Up to thirty seconds,
    once per run.
    """
    while True:
        code = admin_session.totp(secret)
        if code != used:
            return code
        time.sleep(1)


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def rq(method, url, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())


def wait_for(port, tries=120) -> bool:
    for _ in range(tries):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.3):
                return True
        except OSError:
            time.sleep(0.25)
    return False


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """admin_session.sign_in() asserts on the 302 the second step answers
    with, so the opener it is given must not swallow it."""

    def redirect_request(self, *_args, **_kwargs):
        return None


class Browser:
    def __init__(self, drv_port, script=True):
        self.base = f"http://127.0.0.1:{drv_port}"
        prefs = {} if script else {"javascript.enabled": False}
        r = rq("POST", self.base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "moz:firefoxOptions": {"args": ["-headless"], "prefs": prefs}}}})
        self.s = f"{self.base}/session/{r['value']['sessionId']}"
        rq("POST", self.s + "/window/rect",
           {"width": 1400, "height": 900, "x": 0, "y": 0})

    def go(self, url, settle=1.6):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(settle)

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def click(self, css):
        """Click the first match, through the driver, the way a person does."""
        found = rq("POST", self.s + "/elements",
                   {"using": "css selector", "value": css})["value"]
        if not found:
            raise AssertionError(f"nothing matched {css}")
        eid = found[0]["element-6066-11e4-a52e-4f735466cecf"]
        rq("POST", self.s + f"/element/{eid}/click", {})
        time.sleep(2.0)

    def sign_in(self, web_port, secret):
        """Through the real login page, in two steps.

        Filling and submitting from script rather than driving the fields:
        the point here is to arrive at an editor, and the login page's own
        behaviour is the subject of tools/test_admin_auth.py. It is also the
        one part of this file that cannot be done with JavaScript off, so the
        no-script browser signs in with script and has it switched off after.
        """
        base = f"http://127.0.0.1:{web_port}"

        self.go(base + "/login.php")
        self.js(
            "var f = document.querySelector('.signin__form');"
            f"f.querySelector('#user').value = {json.dumps(admin_session.USER)};"
            f"f.querySelector('#password').value = {json.dumps(admin_session.PASSWORD)};"
            "f.submit();")
        time.sleep(1.6)

        code = admin_session.totp(secret)
        self.js(
            "var f = document.querySelector('.signin__form');"
            f"f.querySelector('#code').value = {json.dumps(code)};"
            "f.submit();")
        time.sleep(1.6)
        return code

    def adopt_session(self, base: str, cookies) -> None:
        """Carry a session in from outside, rather than signing in here.

        The no-script browser cannot use sign_in() above — that fills the
        fields from JavaScript — and driving the login form through the driver
        is a fight with element interactability that proves nothing about this
        file's subject. So the session is made over plain HTTP by
        admin_session, which is what every other test here uses, and its
        cookie is handed to the browser.

        What is being tested below is what the EDITORS do with script off. How
        somebody signs in with script off is tools/test_admin_auth.py's
        subject, and it covers it.
        """
        self.go(base + "/login.php", settle=0.6)
        rq("DELETE", self.s + "/cookie")
        for c in cookies:
            rq("POST", self.s + "/cookie", {"cookie": {
                "name": c.name, "value": c.value, "path": "/",
                "domain": c.domain or "127.0.0.1"}})

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


# How many rows a band has, what order they are in, and where the page is.
STATE = """
function vals(sel) {
  return Array.prototype.map.call(document.querySelectorAll(sel),
                                  function (e) { return e.value; });
}
return {
  y: Math.round(window.scrollY),
  clients: document.querySelectorAll('input[name^="clients[items]["][name$="[name]"]').length,
  years: vals('input[name^="milestones[items]["][name$="[year]"]'),
  focus: (document.activeElement.getAttribute('value') ||
          document.activeElement.getAttribute('name') || ''),
  marked: window.__stillHere === true
};
"""

# Left on the window object. It does not survive a navigation, so it is the
# thing that says whether one happened.
MARK = "window.__stillHere = true; return true;"


def run(b: Browser, base: str, r: Results) -> None:
    r.section("every form in the shell asks to be sent this way")
    for screen in ("/?s=careers", "/?s=contact", "/?s=company", "/?s=account"):
        b.go(base + screen)
        counts = b.js(
            "var all = document.querySelectorAll('#admin-main form');"
            "var async = document.querySelectorAll('#admin-main form[data-async]');"
            "return {all: all.length, async: async.length};")
        r.check(f"{screen}: all {counts['all']} forms carry data-async",
                counts["all"] == counts["async"] and counts["all"] > 0,
                f"{counts['async']} of {counts['all']} — a form without it "
                f"navigates, and lands back at the top of the page")

    b.go(base + "/?s=company")
    b.js(MARK)

    r.section("adding a row")
    b.js("window.scrollTo({top: 4000, behavior: 'instant'});")
    time.sleep(0.4)
    before = b.js(STATE)
    b.click('button[name="do"][value="clients-add:0"]')
    after = b.js(STATE)

    r.check("a client row is added", after["clients"] == before["clients"] + 1,
            f"{before['clients']} -> {after['clients']}")
    r.check("the page was not navigated", after["marked"] is True,
            "window.__stillHere was lost, so the document was replaced")
    r.check("focus lands in the new row",
            after["focus"] == f"clients[items][{after['clients'] - 1}][name]",
            f"focus went to {after['focus']!r}")

    r.section("moving a row, twice")
    # base.css sets scroll-behavior: smooth, so this has to be told not to
    # animate — otherwise the read below gets the position it is leaving.
    b.js("window.scrollTo({top: 1200, behavior: 'instant'});")
    time.sleep(0.4)
    start = b.js(STATE)
    b.click('button[name="do"][value="milestones-down:0"]')
    once = b.js(STATE)

    r.check("the row moved", once["years"][:2] == start["years"][:2][::-1],
            f"{start['years'][:3]} -> {once['years'][:3]}")
    r.check("the scroll position is kept", abs(once["y"] - start["y"]) <= 4,
            f"was at {start['y']}, now at {once['y']}")
    r.check("focus follows the row that moved",
            once["focus"] == "milestones-down:1",
            f"focus is on {once['focus']!r}, so a second press would move a "
            f"different row")

    # The point of following it: press again without touching the mouse.
    b.click('button[name="do"][value="milestones-down:1"]')
    twice = b.js(STATE)
    r.check("pressing again moves the same row again",
            twice["years"][:3] == [start["years"][1], start["years"][2],
                                   start["years"][0]],
            f"{start['years'][:3]} -> {twice['years'][:3]}")

    r.section("removing a row")
    b.click(f'button[name="do"][value="clients-remove:{after["clients"] - 1}"]')
    gone = b.js(STATE)
    r.check("the row is removed", gone["clients"] == before["clients"],
            f"{after['clients']} -> {gone['clients']}")
    r.check("still no navigation", gone["marked"] is True)

    r.section("saving")
    # The save button lives in .admin-bar and reaches the form with the
    # `form` attribute, so this is also the assertion that that works.
    b.click('.admin-bar__actions button[name="do"][value="save"]')
    saved = b.js(STATE)
    r.check("saving does not navigate either", saved["marked"] is True,
            "the redirect after a save was followed by the document, not by "
            "fetch()")
    r.check("the address bar was updated",
            "saved" in (b.js("return location.search;") or ""),
            "history.replaceState did not run, so a reload would show the "
            "state before the save")
    r.check("it says what happened",
            "Saved" in (b.js(
                "var n = document.querySelector('.admin__notice');"
                "return n ? n.textContent : '';") or ""))

    r.section("the account menu")
    r.check("it is a <details>, so it opens with no script",
            b.js("return !!document.querySelector('.rail__foot details.account "
                 "> summary.account__toggle');"),
            "a scripted dropdown would be empty with JavaScript off")
    r.check("the save button is in the bar and names its form",
            b.js("var b = document.querySelector('.admin-bar__actions "
                 "button[name=do][value=save]');"
                 "return !!(b && b.form && b.form.id === 'company-form');"),
            "the `form` attribute is what lets it sit outside the form")
    r.check("signing out is a POST with a token",
            b.js("var f = document.querySelector('.account__signout');"
                 "return !!(f && f.method === 'post' && f.querySelector("
                 "'input[name=csrf]'));"))


def run_without_script(b: Browser, base: str, r: Results) -> None:
    """The same edits, with JavaScript switched off in the browser."""
    r.section("with JavaScript off")

    b.go(base + "/?s=company")
    r.check("the editor renders at all",
            _count(b, 'input[name^="clients[items]["]') > 0,
            "the page did not render, so nothing below means anything")

    rows = _count(b, 'input[name^="clients[items]["][name$="[name]"]')
    _submit_without_script(b, "clients-add:0")
    r.check("adding a row still works",
            _count(b, 'input[name^="clients[items]["][name$="[name]"]') == rows + 1,
            "the form did not post, so the fallback is gone")

    _submit_without_script(b, f"clients-remove:{rows}")
    r.check("removing a row still works",
            _count(b, 'input[name^="clients[items]["][name$="[name]"]') == rows)

    r.check("the account menu still opens",
            _count(b, "details.account > summary") == 1,
            "there is nothing to press, and no way to sign out")


def _count(b: Browser, css: str) -> int:
    found = rq("POST", b.s + "/elements", {"using": "css selector", "value": css})
    return len(found["value"])


def _submit_without_script(b: Browser, value: str) -> None:
    """Click a submit button through the driver. With script off this is an
    ordinary navigation, which is exactly the point."""
    b.click(f'button[name="do"][value="{value}"]')


def main() -> None:
    missing = [n for n in ("php", "geckodriver", "firefox") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        print("This test needs Firefox and geckodriver as well as the PHP CLI.")
        return

    backup = DATA.read_bytes()
    web_port, drv_port = free_port(), free_port()
    work = Path(tempfile.mkdtemp(prefix="t4t-admin-forms-"))
    private = work / "private"

    php = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{web_port}", "-t", str(DOCROOT), str(ROUTER)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True,
        env=dict(os.environ, T4T_PRIVATE=str(private)))
    drv = subprocess.Popen(
        ["geckodriver", "--port", str(drv_port)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)

    if not (wait_for(web_port) and wait_for(drv_port)):
        raise SystemExit("php or geckodriver did not start")

    base = f"http://127.0.0.1:{web_port}"
    print(f"firefox (headless) against {base}")

    r = Results()
    scripted = plain = None
    try:
        secret = admin_session.make_account(private)

        scripted = Browser(drv_port, script=True)
        used = scripted.sign_in(web_port, secret)
        agent = scripted.js("return navigator.userAgent;")
        run(scripted, base, r)
        scripted.quit()
        scripted = None

        # THE SESSION IS BOUND TO THE USER-AGENT (auth_fingerprint), so the
        # cookie made over HTTP only works in the browser if the two present
        # the same one. Both Firefox sessions send the same string, so it is
        # read from the one that can still run script and handed to urllib.
        jar = CookieJar()
        opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(jar), NoRedirect())
        opener.addheaders = [("User-Agent", agent)]
        fresh_code(secret, used)
        admin_session.sign_in(opener, base, secret)

        plain = Browser(drv_port, script=False)
        plain.adopt_session(base, list(jar))
        run_without_script(plain, base, r)
    finally:
        for b in (scripted, plain):
            if b:
                b.quit()
        for proc in (drv, php):
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=5)
            except Exception:
                pass
        shutil.rmtree(work, ignore_errors=True)
        DATA.write_bytes(backup)
        for stray in (DATA.with_suffix(".json.bak"),):
            stray.unlink(missing_ok=True)
        print(f"\n{DATA.relative_to(ROOT)} restored")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        sys.exit(1)

    print(f"\n{total}/{total} checks passed")


if __name__ == "__main__":
    main()
