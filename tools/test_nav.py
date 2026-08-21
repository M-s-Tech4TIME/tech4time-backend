#!/usr/bin/env python3
"""
Prove the navigation is usable, at both widths, in a real browser.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_nav.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing.

WHY
Two bugs shipped that every other check in this repo called a pass, because
every other check asked about markup or colour rather than about use.

  1. The hamburger was on screen at desktop widths, beside the full nav it
     exists to replace. layout.css said `display: none` above 64em;
     components.css said `display: grid` with no media query at all. Both
     selectors are one class, a media query adds no specificity, and
     components.css loads second — so it won everywhere.

  2. Opening the drawer on mobile produced nothing usable. .site-header had
     backdrop-filter on it, which makes an element the containing block for
     its position:fixed descendants, so the drawer's inset:0 resolved against
     the header rather than the viewport. It opened 120px tall; all six links
     lay outside that box and could not be hit. data-open was "true", the
     transition ran, the attributes were right, and the DOM looked correct.

So the assertions here are about reachability: elementFromPoint at the centre
of each link has to return that link. That is the question both bugs failed
and no attribute check can ask.
"""

import json
import os
import shutil
import signal
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
W3C = "element-6066-11e4-a52e-4f735466cecf"
PAGE = "/pages/company-profile/"

# The drawer/desktop boundary in layout.css and components.css is 64em, which
# is 1024px against the browser's default font size. Firefox will not size a
# window below about 500px, so the mobile cases use 520.
DESKTOP, MOBILE = 1200, 520

PROBE = """
var toggle = document.querySelector('[data-nav-toggle]');
var drawer = document.querySelector('[data-nav-drawer]');
var links = drawer.querySelectorAll('.nav-link');
var reachable = 0;
for (var i = 0; i < links.length; i++) {
  var r = links[i].getBoundingClientRect();
  if (r.width < 1 || r.height < 1) continue;
  var at = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
  if (at && (at === links[i] || links[i].contains(at) || links[i].contains(at.parentNode))) {
    reachable++;
  }
}
var dr = drawer.getBoundingClientRect();
return {
  viewport: [window.innerWidth, window.innerHeight],
  toggle_display: getComputedStyle(toggle).display,
  expanded: toggle.getAttribute('aria-expanded'),
  open: drawer.getAttribute('data-open'),
  position: getComputedStyle(drawer).position,
  rect: [Math.round(dr.x), Math.round(dr.y), Math.round(dr.width), Math.round(dr.height)],
  links: links.length,
  reachable: reachable,
  body_overflow: document.body.style.overflow
};
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


def stop(proc: subprocess.Popen) -> None:
    for attempt in (proc.terminate, proc.kill):
        try:
            attempt()
            proc.wait(timeout=5)
            return
        except Exception:
            continue
    try:
        os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
    except Exception:
        pass


class Browser:
    def __init__(self, drv_port):
        base = f"http://127.0.0.1:{drv_port}"
        r = rq("POST", base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "moz:firefoxOptions": {"args": ["-headless"]}}}})
        self.s = f"{base}/session/{r['value']['sessionId']}"

    def size(self, w, h):
        rq("POST", self.s + "/window/rect", {"width": w, "height": h, "x": 0, "y": 0})
        time.sleep(0.3)

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(1.0)

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def probe(self):
        return self.js(PROBE)

    def toggle(self):
        """A real click, not element.click() from script.

        nav.js records document.activeElement when the drawer opens so it can
        hand focus back on close. A synthetic click never focuses the button,
        so scripting it would leave activeElement on <body> and make the
        focus-return assertion fail against working code."""
        eid = rq("POST", self.s + "/element",
                 {"using": "css selector", "value": "[data-nav-toggle]"})["value"][W3C]
        rq("POST", f"{self.s}/element/{eid}/click", {})
        # Both the transform and the visibility are transitioned, the slower
        # at 400ms. Measuring before they land reads the start of the
        # animation and reports a drawer that is still off screen.
        time.sleep(1.2)

    def press_escape(self):
        rq("POST", self.s + "/actions", {"actions": [{
            "type": "key", "id": "kb",
            "actions": [{"type": "keyDown", "value": ""},
                        {"type": "keyUp", "value": ""}]}]})
        time.sleep(1.2)

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def run(b: Browser, origin: str, r: Results) -> None:
    print(f"\ndesktop ({DESKTOP}px): the full nav, and no hamburger beside it")
    b.size(DESKTOP, 900)
    b.go(origin + PAGE)
    d = b.probe()
    r.check("the viewport really is above the 64em breakpoint",
            d["viewport"][0] >= 1024, f"got {d['viewport'][0]}px")
    r.check("the hamburger is hidden", d["toggle_display"] == "none",
            f"display is {d['toggle_display']!r}")
    r.check("the nav is in the bar, not a fixed overlay",
            d["position"] == "static", f"position is {d['position']!r}")
    r.check("every link can be clicked", d["reachable"] == d["links"] == 6,
            f"{d['reachable']} of {d['links']} reachable")

    print(f"\nmobile ({MOBILE}px), closed")
    b.size(MOBILE, 800)
    b.go(origin + PAGE)
    d = b.probe()
    r.check("the hamburger is shown", d["toggle_display"] != "none")
    r.check("it reports itself collapsed", d["expanded"] == "false")
    r.check("the drawer is a fixed overlay", d["position"] == "fixed")
    r.check("no link is reachable while it is closed", d["reachable"] == 0,
            f"{d['reachable']} reachable with the drawer shut")

    print(f"\nmobile ({MOBILE}px), opened — the case that was broken")
    b.toggle()
    d = b.probe()
    r.check("it reports itself expanded", d["expanded"] == "true")
    r.check("the drawer says it is open", d["open"] == "true")
    # The regression guard. With backdrop-filter on .site-header the drawer's
    # containing block was the header, and this height came back as 120.
    r.check("the drawer fills the viewport, not the header",
            d["rect"][2] == d["viewport"][0] and d["rect"][3] == d["viewport"][1],
            f"drawer is {d['rect'][2]}x{d['rect'][3]}, "
            f"viewport is {d['viewport'][0]}x{d['viewport'][1]}")
    r.check("all six links are on screen and clickable",
            d["reachable"] == d["links"] == 6,
            f"{d['reachable']} of {d['links']} reachable")
    r.check("the page behind it cannot scroll", d["body_overflow"] == "hidden",
            f"body overflow is {d['body_overflow']!r}")

    print("\nmobile: closing it again")
    b.press_escape()
    d = b.probe()
    r.check("Escape closes the drawer", d["open"] == "false")
    r.check("and it reports itself collapsed", d["expanded"] == "false")
    r.check("no link is reachable once closed", d["reachable"] == 0)
    r.check("scrolling is restored", d["body_overflow"] == "",
            f"body overflow is {d['body_overflow']!r}")
    r.check("focus returns to the hamburger", b.js(
        "return document.activeElement === "
        "document.querySelector('[data-nav-toggle]')"))


def main() -> None:
    missing = [n for n in ("php", "geckodriver", "firefox") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        print("This test needs Firefox and geckodriver as well as the PHP CLI.")
        return

    web_port, drv_port = free_port(), free_port()
    php = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{web_port}", "-t", str(ROOT),
         str(ROOT / "tools" / "dev-router.php")],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
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
        run(browser, f"http://127.0.0.1:{web_port}", results)
    finally:
        if browser:
            browser.quit()
        for proc in (drv, php):
            stop(proc)

    total = results.passed + len(results.failed)
    print(f"\n{results.passed}/{total} checks passed")
    if results.failed:
        print("\nfailed:")
        for name in results.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
