#!/usr/bin/env python3
"""
Drive the job post editor in a real browser.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_editor.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing, so it does not block a machine that only has PHP.

WHY A BROWSER
The PHP tests prove what gets stored. They cannot see what a click does, and
the bug that prompted this file was invisible from the server side: the editor
was being inserted inside a <label>, which forwards a click from anywhere
within it to its first labelable descendant — the Bold button. Every click in
the text silently pressed it. Nothing about the stored data looked wrong; the
data was faithfully recording formatting the author never asked for.

So this asserts on behaviour: that clicking text does nothing, that clicking a
button does exactly one thing, and that alignment arrives as a class rather
than the inline style the CSP would block.
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
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import admin_session  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "content" / "careers.json"
W3C = "element-6066-11e4-a52e-4f735466cecf"

ROUTER = """<?php
/* No faked sign-in: the browser goes through /admin/login.php like a person. */
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($p, '/admin')) {
    $f = __DIR__ . $p;
    require is_file($f) && str_ends_with($f, '.php') ? $f : __DIR__ . '/admin/index.php';
    return true;
}
if (rtrim($p, '/') === '/pages/careers') { require __DIR__ . '/pages/careers/index.php'; return true; }
return false;
"""

INSTRUMENT = """
window.__calls = [];
var orig = document.execCommand.bind(document);
document.execCommand = function (cmd, ui, val) {
    window.__calls.push(cmd);
    return orig(cmd, ui, val);
};
"""

# Find a word inside the first editor and return where to click, in viewport
# coordinates. Measured immediately before the click so nothing has scrolled.
FIND_WORD = """
var s = document.querySelectorAll('.rte__surface')[0];
s.scrollIntoView({block: 'center'});
var walker = document.createTreeWalker(s, NodeFilter.SHOW_TEXT);
var node = walker.nextNode();
while (node && node.textContent.trim().length < 30) node = walker.nextNode();
var r = document.createRange();
r.setStart(node, 6); r.setEnd(node, 13);
var box = r.getBoundingClientRect();
return {x: Math.round(box.left + box.width / 2),
        y: Math.round(box.top + box.height / 2)};
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


def rq(method, url, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method,
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        raise SystemExit("WebDriver error:\n" + e.read().decode()[:600])


def wait_for(port, tries=120) -> bool:
    for _ in range(tries):
        try:
            with socket.create_connection(("127.0.0.1", port), 0.2):
                return True
        except OSError:
            time.sleep(0.15)
    return False


class Browser:
    def __init__(self, drv_port):
        self.base = f"http://127.0.0.1:{drv_port}"
        r = rq("POST", self.base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "moz:firefoxOptions": {"args": ["-headless"]}}}})
        self.s = f"{self.base}/session/{r['value']['sessionId']}"
        rq("POST", self.s + "/window/rect",
           {"width": 1500, "height": 1300, "x": 0, "y": 0})

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(1.5)

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def click_at(self, x, y, times=1):
        actions = [{"type": "pointerMove", "duration": 0,
                    "origin": "viewport", "x": x, "y": y}]
        for _ in range(times):
            actions.append({"type": "pointerDown", "button": 0})
            actions.append({"type": "pointerUp", "button": 0})
        rq("POST", self.s + "/actions", {"actions": [{
            "type": "pointer", "id": "mouse",
            "parameters": {"pointerType": "mouse"}, "actions": actions}]})
        time.sleep(0.5)

    def click_button(self, index):
        eid = rq("POST", self.s + "/elements",
                 {"using": "css selector", "value": ".rte__btn"})["value"][index][W3C]
        rq("POST", self.s + f"/element/{eid}/click", {})
        time.sleep(0.4)

    def sign_in(self, web_port, secret):
        """Through the real login page, in the browser, in two steps.

        Submitting the form rather than clicking the button: the point here is
        to arrive at the editor, and the login page's own behaviour is the
        subject of tools/test_admin_auth.py.
        """
        base = f"http://127.0.0.1:{web_port}"

        self.go(base + "/admin/login.php")
        self.js(
            "var f = document.querySelector('.signin__form');"
            f"f.querySelector('#user').value = {json.dumps(admin_session.USER)};"
            f"f.querySelector('#password').value = {json.dumps(admin_session.PASSWORD)};"
            "f.submit();"
        )
        time.sleep(1.5)

        self.js(
            "var f = document.querySelector('.signin__form');"
            f"f.querySelector('#code').value = {json.dumps(admin_session.totp(secret))};"
            "f.submit();"
        )
        time.sleep(1.5)

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def run(b: Browser, web_port: int, r: Results):
    # The admin gained a second editor and an icon rail: the job posts moved
    # from /admin/ to /admin/?s=careers.
    b.go(f"http://127.0.0.1:{web_port}/admin/?s=careers&action=edit&id=security-engineer")

    print("\nsetup")
    r.check("the editors are built", b.js(
        "return document.querySelectorAll('.rte__surface').length") == 7)
    r.check("the textareas are hidden behind them", b.js(
        "return document.querySelectorAll('textarea[data-editor][hidden]').length") == 7)

    print("\nclicking in the text must not format it")
    b.js(INSTRUMENT)
    before = b.js("return document.querySelectorAll('.rte__surface')[0].innerHTML")

    spot = b.js(FIND_WORD)
    b.click_at(spot["x"], spot["y"], times=2)

    after = b.js("return document.querySelectorAll('.rte__surface')[0].innerHTML")
    r.check("a double-click leaves the text alone", before == after,
            "the field changed when nothing but a selection should have")
    r.check("a double-click issues no formatting command",
            b.js("return window.__calls") == [],
            f"execCommand ran: {b.js('return window.__calls')}")
    r.check("it does select a word", len(b.js("return String(window.getSelection())")) > 0)
    r.check("Bold does not report itself pressed over plain text",
            b.js("return document.querySelectorAll('.rte__btn')[0]"
                 ".getAttribute('aria-pressed')") == "false")

    spot = b.js(FIND_WORD)
    b.click_at(spot["x"], spot["y"], times=1)
    r.check("a single click leaves the text alone too", b.js(
        "return document.querySelectorAll('.rte__surface')[0].innerHTML") == before)

    print("\nthe buttons still work")
    spot = b.js(FIND_WORD)
    b.click_at(spot["x"], spot["y"], times=2)
    b.click_button(0)
    r.check("Bold wraps the selection", b.js(
        "return document.querySelectorAll('.rte__surface')[0]"
        ".querySelectorAll('strong,b').length") == 1)
    r.check("and now reports itself pressed", b.js(
        "return document.querySelectorAll('.rte__btn')[0]"
        ".getAttribute('aria-pressed')") == "true")

    b.click_button(0)
    r.check("clicking it again removes the formatting", b.js(
        "return document.querySelectorAll('.rte__surface')[0]"
        ".querySelectorAll('strong,b').length") == 0)

    print("\nalignment is a class, never an inline style")
    spot = b.js(FIND_WORD)
    b.click_at(spot["x"], spot["y"], times=1)
    # 0-2 bold/italic/underline, 3-5 lists and link, 6 align-left, 7 centre.
    b.click_button(7)
    html = b.js("return document.querySelectorAll('.rte__surface')[0].innerHTML")
    r.check("centring adds a class", "ta-center" in html, html[:200])
    r.check("centring adds no style attribute", "style=" not in html, html[:200])

    print("\nthe textarea keeps up")
    r.check("the hidden field matches the surface", b.js(
        "var s = document.querySelectorAll('.rte__surface')[0];"
        "return s.innerHTML === document.querySelector('textarea[name=about]').value"))


def main() -> None:
    missing = [n for n in ("php", "geckodriver", "firefox") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        print("This test needs Firefox and geckodriver as well as the PHP CLI.")
        return

    backup = DATA.read_bytes()
    router = ROOT / f".test-router-{os.getpid()}.php"
    router.write_text(ROUTER)
    web_port, drv_port = free_port(), free_port()

    work = Path(tempfile.mkdtemp(prefix="t4t-editor-"))
    private = work / "private"

    php = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{web_port}", "-t", str(ROOT), str(router)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True,
        env=dict(os.environ, T4T_PRIVATE=str(private)))
    drv = subprocess.Popen(
        ["geckodriver", "--port", str(drv_port)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)

    if not (wait_for(web_port) and wait_for(drv_port)):
        raise SystemExit("php or geckodriver did not start")

    print(f"firefox (headless) against 127.0.0.1:{web_port}")
    results = Results()
    browser = None

    try:
        browser = Browser(drv_port)
        browser.sign_in(web_port, admin_session.make_account(private))
        run(browser, web_port, results)
    finally:
        if browser:
            browser.quit()
        for proc in (drv, php):
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=5)
            except Exception:
                pass
        router.unlink(missing_ok=True)
        DATA.write_bytes(backup)
        shutil.rmtree(work, ignore_errors=True)

    total = results.passed + len(results.failed)
    print(f"\n{results.passed}/{total} checks passed")

    if results.failed:
        print("\nfailed:")
        for name in results.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
