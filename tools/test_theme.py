#!/usr/bin/env python3
"""
Prove the theme switch behaves, in a real browser with a real OS preference.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_theme.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing.

WHY
tools/check_dark_mode.py measures each theme once it is applied. Nothing
asserted that the right one gets applied, or that the visitor's choice
survives a reload — and every page's dark mode rests on that. The rules the
site claims, from the comment at the top of theme.css:

  1. theme-init.js runs synchronously in <head> and stamps data-theme before
     first paint, so there is no flash of the wrong theme.
  2. Without JavaScript, prefers-color-scheme still applies.
  3. An explicit choice always wins over the OS preference, in both
     directions — including choosing light on a machine set to dark.

Firefox's ui.systemUsesDarkTheme pref is what makes 2 and 3 testable: it
drives prefers-color-scheme for real, rather than faking the media query.
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
HEAD = ROOT / "tools" / "templates" / "head.html"
PAGE = "/pages/about/"


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
    def __init__(self, drv_port, system_dark: bool):
        base = f"http://127.0.0.1:{drv_port}"
        r = rq("POST", base + "/session", {"capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "moz:firefoxOptions": {
                "args": ["-headless"],
                # 1 = the OS prefers dark, 0 = light. This is what
                # prefers-color-scheme actually reads.
                "prefs": {"ui.systemUsesDarkTheme": 1 if system_dark else 0},
            }}}})
        self.s = f"{base}/session/{r['value']['sessionId']}"

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(0.6)

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def theme(self):
        return self.js("return document.documentElement.getAttribute('data-theme')")

    def painted(self):
        """Which theme the visitor actually sees, read off the page rather
        than off the attribute. With no stored choice there IS no attribute —
        theme-init.js sets one only for an explicit choice, so that the CSS
        keeps following the OS with JavaScript disabled — and the rendered
        background is the only honest answer."""
        bg = self.js("return getComputedStyle(document.body).backgroundColor")
        return {"rgb(250, 250, 250)": "light",
                "rgb(11, 11, 12)": "dark"}.get(bg, bg)

    def media_is_dark(self):
        return self.js(
            "return matchMedia('(prefers-color-scheme: dark)').matches")

    def click_toggle(self):
        self.js("document.querySelector('[data-theme-toggle]').click();")
        time.sleep(0.3)

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def check_head_is_blocking(r: Results) -> None:
    """The no-flash guarantee is structural, so it is asserted structurally:
    a deferred or async theme script paints the wrong theme first and corrects
    it afterwards, which is the exact flash it exists to prevent."""
    head = HEAD.read_text()
    tag = ""
    for line in head.splitlines():
        if "theme-init.js" in line:
            tag = line.strip()
            break

    r.check("theme-init.js is in the shared <head>", bool(tag))
    if not tag:
        return
    r.check("theme-init.js is not deferred", "defer" not in tag, tag)
    r.check("theme-init.js is not async", "async" not in tag, tag)
    r.check("theme-init.js is not a module (modules defer implicitly)",
            'type="module"' not in tag, tag)


def run(origin: str, drv_port: int, r: Results) -> None:
    print("\nthe <head> script, which is what prevents the flash")
    check_head_is_blocking(r)

    print("\nno stored choice: the OS preference decides, through CSS alone")
    b = Browser(drv_port, system_dark=True)
    try:
        b.go(origin + PAGE)
        b.js("localStorage.clear();")
        b.go(origin + PAGE)
        r.check("Firefox really is reporting a dark OS preference", b.media_is_dark())
        r.check("a dark machine renders dark", b.painted() == "dark",
                f"body background is {b.painted()!r}")
        # The absence of the attribute is the feature, not an oversight: it is
        # what leaves the media query in charge, so the page still follows the
        # OS with JavaScript turned off.
        r.check("and no data-theme is stamped, so the media query stays in charge",
                b.theme() is None, f"got {b.theme()!r}")
    finally:
        b.quit()

    b = Browser(drv_port, system_dark=False)
    try:
        b.go(origin + PAGE)
        b.js("localStorage.clear();")
        b.go(origin + PAGE)
        r.check("a light machine renders light", b.painted() == "light",
                f"body background is {b.painted()!r}")
        r.check("and no data-theme is stamped there either",
                b.theme() is None, f"got {b.theme()!r}")
    finally:
        b.quit()

    print("\nan explicit choice beats the OS, in both directions")
    b = Browser(drv_port, system_dark=True)
    try:
        b.go(origin + PAGE)
        b.js("localStorage.setItem('tech4time-theme', 'light');")
        b.go(origin + PAGE)
        r.check("light chosen on a dark machine stays light",
                b.theme() == "light", f"got {b.theme()!r}")
    finally:
        b.quit()

    b = Browser(drv_port, system_dark=False)
    try:
        b.go(origin + PAGE)
        b.js("localStorage.setItem('tech4time-theme', 'dark');")
        b.go(origin + PAGE)
        r.check("dark chosen on a light machine stays dark",
                b.theme() == "dark", f"got {b.theme()!r}")

        print("\nthe toggle writes the choice, and it survives a reload")
        b.click_toggle()
        r.check("the toggle flips the theme", b.theme() == "light",
                f"got {b.theme()!r}")
        r.check("and records it",
                b.js("return localStorage.getItem('tech4time-theme')") == "light")
        r.check("the toggle reports its state to assistive tech", b.js(
            "return document.querySelector('[data-theme-toggle]')"
            ".getAttribute('aria-pressed') !== null"))

        b.go(origin + "/pages/contact/")
        r.check("the choice carries to another page", b.theme() == "light",
                f"got {b.theme()!r}")
    finally:
        b.quit()


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
    try:
        run(f"http://127.0.0.1:{web_port}", drv_port, results)
    finally:
        for proc in (drv, php):
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                proc.wait(timeout=5)
            except Exception:
                pass

    total = results.passed + len(results.failed)
    print(f"\n{results.passed}/{total} checks passed")
    if results.failed:
        print("\nfailed:")
        for name in results.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
