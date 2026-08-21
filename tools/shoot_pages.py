#!/usr/bin/env python3
"""
Photograph pages in headless Firefox, for looking at.

Development tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/shoot_pages.py                        # every page, both themes
    python3 tools/shoot_pages.py company-profile        # just the ones matching
    python3 tools/shoot_pages.py --theme dark --width 390 careers

Writes PNGs to tools/shots/ (gitignored).

WHY, GIVEN check_dark_mode.py EXISTS
That script answers questions with numbers, and there are questions it cannot
be asked. Whether a white plate behind a client logo reads as part of the
artwork or as a hole punched in a dark page is not a contrast ratio. Neither
is whether a section divider still separates anything once the theme flips.
This is for those.

It captures the full document, not the viewport, so a page is one image.
"""

import argparse
import base64
import json
import os
import shutil
import signal
import socket
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "tools" / "shots"
W3C = "element-6066-11e4-a52e-4f735466cecf"

PAGES = [
    ("home", "/"),
    ("about", "/pages/about/"),
    ("services", "/pages/services/"),
    ("cybersecurity", "/pages/services/cybersecurity/"),
    ("software-development", "/pages/services/software-development/"),
    ("cloud-infrastructure", "/pages/services/cloud-infrastructure/"),
    ("hr-solutions", "/pages/services/hr-solutions/"),
    ("it-equipment-supply", "/pages/services/it-equipment-supply/"),
    ("it-consultancy-training", "/pages/services/it-consultancy-training/"),
    ("company-profile", "/pages/company-profile/"),
    ("careers", "/pages/careers/"),
    ("branding", "/pages/branding-and-advertisement/"),
    ("certifications", "/pages/resource-certifications/"),
    ("privacy-policy", "/pages/privacy-policy/"),
    ("contact", "/pages/contact/"),
    ("404", "/404.html"),
]

SETTLE = """
var done = arguments[arguments.length - 1];
var y = 0, step = window.innerHeight;
(function next() {
  if (y < document.body.scrollHeight) {
    window.scrollTo(0, y); y += step; setTimeout(next, 60); return;
  }
  window.scrollTo(0, 0);
  setTimeout(function () { done(document.body.scrollHeight); }, 500);
})();
"""


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def rq(method, url, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method,
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
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
    """Shut a helper process down, as far as the environment allows. See the
    note on the identical helper in tools/check_dark_mode.py: deleting the
    WebDriver session is what releases Firefox, and on a confined runner the
    signals below can all be refused, leaving geckodriver idling after the
    run. `pkill geckodriver` from an ordinary shell clears those."""
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
    def __init__(self, drv_port, width, height):
        base = f"http://127.0.0.1:{drv_port}"
        r = rq("POST", base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "moz:firefoxOptions": {"args": ["-headless"]}}}})
        self.s = f"{base}/session/{r['value']['sessionId']}"
        rq("POST", self.s + "/window/rect",
           {"width": width, "height": height, "x": 0, "y": 0})

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})

    def js(self, script, async_=False):
        kind = "async" if async_ else "sync"
        return rq("POST", f"{self.s}/execute/{kind}",
                  {"script": script, "args": []})["value"]

    def shoot(self, path: Path):
        """The whole document. An element screenshot of <html> in Firefox
        captures beyond the viewport, which a plain /screenshot does not.

        ONE CAVEAT, worth knowing before you draw a conclusion from one of
        these: the sticky header comes out EMPTY in a full-document capture.
        Its contents are painted into the viewport, not into the document
        image, so the bar appears as a blank strip even when the logo and nav
        are on screen and working. To look at the header, screenshot the
        viewport (GET /session/{id}/screenshot) or the .site-header element
        instead. A blank bar here is the capture, not the page."""
        eid = rq("POST", self.s + "/element",
                 {"using": "css selector", "value": "html"})["value"][W3C]
        data = rq("GET", f"{self.s}/element/{eid}/screenshot")["value"]
        path.write_bytes(base64.b64decode(data))

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("match", nargs="*", help="only pages whose name contains this")
    ap.add_argument("--theme", choices=["light", "dark", "both"], default="both")
    ap.add_argument("--width", type=int, default=1440)
    ap.add_argument("--height", type=int, default=1000)
    args = ap.parse_args()

    missing = [n for n in ("php", "geckodriver", "firefox") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        return

    pages = [p for p in PAGES
             if not args.match or any(m in p[0] for m in args.match)]
    if not pages:
        raise SystemExit(f"No page matches {args.match}")
    themes = ["light", "dark"] if args.theme == "both" else [args.theme]

    OUT.mkdir(parents=True, exist_ok=True)
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

    origin = f"http://127.0.0.1:{web_port}"
    browser = None
    try:
        browser = Browser(drv_port, args.width, args.height)
        for name, path in pages:
            for theme in themes:
                browser.go(origin + path)
                browser.js(f"localStorage.setItem('tech4time-theme', '{theme}');")
                browser.go(origin + path)
                browser.js(SETTLE, async_=True)
                out = OUT / f"{name}-{theme}-{args.width}.png"
                browser.shoot(out)
                print(f"  {out.relative_to(ROOT)}")
    finally:
        if browser:
            browser.quit()
        for proc in (drv, php):
            stop(proc)


if __name__ == "__main__":
    main()
