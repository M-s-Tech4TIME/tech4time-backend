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

WHAT IS BEING TESTED NOW
The drawer and hamburger are gone. Below 64em the header nav is hidden and
the navigation is the dock: a floating bar at the bottom of the viewport with
four real links and a call to action, plus a panel of six sections that opens
above it. Above 64em the dock is hidden and the header nav is the navigation.
The rule under all of it is that exactly one navigation is usable at any
width — having two was what produced the first bug.
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
function reach(nodes) {
  var n = 0;
  for (var i = 0; i < nodes.length; i++) {
    var r = nodes[i].getBoundingClientRect();
    if (r.width < 1 || r.height < 1) continue;
    var at = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
    if (at && (at === nodes[i] || nodes[i].contains(at))) n++;
  }
  return n;
}

var toggle = document.querySelector('[data-nav-toggle]');
var panel = document.querySelector('[data-nav-drawer]');
var dock = document.querySelector('[data-dock]');
var headerLinks = document.querySelectorAll('.site-nav .nav-link');
var barLinks = document.querySelectorAll('.dock__bar a[href]');
var panelLinks = panel.querySelectorAll('.dock__item');
var pr = panel.getBoundingClientRect();

return {
  viewport: [window.innerWidth, window.innerHeight],
  dock_display: getComputedStyle(dock).display,
  header_nav_display: getComputedStyle(document.querySelector('.site-nav')).display,
  toggle_display: getComputedStyle(toggle).display,
  expanded: toggle.getAttribute('aria-expanded'),
  open: panel.getAttribute('data-open'),
  panel_visibility: getComputedStyle(panel).visibility,
  panel_rect: [Math.round(pr.x), Math.round(pr.y),
               Math.round(pr.width), Math.round(pr.height)],
  header_links: headerLinks.length,
  header_reachable: reach(headerLinks),
  bar_links: barLinks.length,
  bar_reachable: reach(barLinks),
  panel_links: panelLinks.length,
  panel_reachable: reach(panelLinks),
  cta_href: (function () {
    var c = document.querySelector('.dock__key--contact');
    return c ? c.getAttribute('href') : null;
  })(),
  menu_icon: (function () {
    var open = document.querySelector('.dock__icon--open');
    var close = document.querySelector('.dock__icon--close');
    var shown = getComputedStyle(open).display !== 'none' ? open : close;
    var u = shown.querySelector('use');
    return u ? u.getAttribute('href') : null;
  })(),
  // The circuit must keep running. Count the animations the browser has
  // actually started, and collect their durations: the whole point of the
  // design is that they share no common factor, so the picture never settles
  // back into an arrangement anyone has seen.
  circuit: (function () {
    var charges = document.querySelectorAll('.dock__charge');
    var nodes = document.querySelectorAll('.dock__node');
    var running = 0, durations = [];
    Array.prototype.forEach.call(charges, function (c) {
      c.getAnimations().forEach(function (a) {
        if (a.playState === 'running') running++;
        durations.push(Math.round(a.effect.getTiming().duration / 1000));
      });
    });
    var nodeRunning = 0;
    Array.prototype.forEach.call(nodes, function (n) {
      n.getAnimations().forEach(function (a) {
        if (a.playState === 'running') nodeRunning++;
      });
    });
    return {
      wires: document.querySelectorAll('.dock__wires use').length,
      charges: charges.length,
      charges_running: running,
      nodes: nodes.length,
      nodes_running: nodeRunning,
      durations: durations,
      infinite: Array.prototype.every.call(charges, function (c) {
        return c.getAnimations().every(function (a) {
          return a.effect.getTiming().iterations === Infinity;
        });
      })
    };
  })(),
  hamburgers: document.querySelectorAll('.nav-toggle').length,
  photos: document.querySelectorAll('.dock img').length,
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
    print(f"\ndesktop ({DESKTOP}px): the header nav, and no dock")
    b.size(DESKTOP, 900)
    b.go(origin + PAGE)
    d = b.probe()
    r.check("the viewport really is above the 64em breakpoint",
            d["viewport"][0] >= 1024, f"got {d['viewport'][0]}px")
    r.check("the header nav is shown", d["header_nav_display"] != "none",
            f"display is {d['header_nav_display']!r}")
    r.check("all six header links can be clicked",
            d["header_reachable"] == d["header_links"] == 6,
            f"{d['header_reachable']} of {d['header_links']} reachable")
    r.check("the dock is hidden", d["dock_display"] == "none",
            f"display is {d['dock_display']!r}")
    r.check("nothing in the dock is reachable",
            d["bar_reachable"] == 0 and d["panel_reachable"] == 0,
            f"bar {d['bar_reachable']}, panel {d['panel_reachable']}")
    # The hamburger is gone entirely rather than hidden. A control that exists
    # only to duplicate a nav already on screen is the bug, not its display.
    r.check("no hamburger exists anywhere in the page", d["hamburgers"] == 0,
            f"found {d['hamburgers']}")

    print(f"\nmobile ({MOBILE}px): the dock, and no header nav")
    b.size(MOBILE, 800)
    b.go(origin + PAGE)
    d = b.probe()
    r.check("the dock is shown", d["dock_display"] != "none")
    r.check("the header nav is hidden", d["header_nav_display"] == "none",
            f"display is {d['header_nav_display']!r}")
    r.check("no header link is reachable", d["header_reachable"] == 0,
            f"{d['header_reachable']} reachable")
    # These are plain <a>, so they are the part of the navigation that still
    # works with JavaScript disabled.
    # Four links: Home, Services, Careers, and the call to action. The menu
    # button is a <button> and is counted separately.
    r.check("all four bar destinations are clickable",
            d["bar_reachable"] == d["bar_links"] == 4,
            f"{d['bar_reachable']} of {d['bar_links']} reachable")
    r.check("the menu button is clickable too", d["toggle_display"] != "none",
            f"display is {d['toggle_display']!r}")
    r.check("the emphasised key goes to the contact page",
            d["cta_href"] == "/pages/contact/", f"href is {d['cta_href']!r}")
    r.check("the menu button shows the dotted grid while closed",
            d["menu_icon"] == "#grid-dots", f"showing {d['menu_icon']!r}")

    print(f"\nmobile ({MOBILE}px): the panel, closed")
    r.check("the menu button reports itself collapsed", d["expanded"] == "false")
    r.check("the panel is hidden", d["panel_visibility"] == "hidden",
            f"visibility is {d['panel_visibility']!r}")
    r.check("no panel link is reachable while it is closed",
            d["panel_reachable"] == 0,
            f"{d['panel_reachable']} reachable with the panel shut")

    print(f"\nmobile ({MOBILE}px): the panel, opened")
    b.toggle()
    d = b.probe()
    r.check("the menu button reports itself expanded", d["expanded"] == "true")
    r.check("the panel says it is open", d["open"] == "true")
    r.check("the panel is visible", d["panel_visibility"] == "visible")
    # The regression guard from the drawer this replaced: with backdrop-filter
    # on .site-header its containing block was the header, and everything in
    # it fell outside the box while still reporting data-open="true".
    r.check("the panel is on screen, not off the side or clipped to nothing",
            d["panel_rect"][0] >= 0
            and d["panel_rect"][1] >= 0
            and d["panel_rect"][2] > 100
            and d["panel_rect"][3] > 100,
            f"panel rect is {d['panel_rect']} in a "
            f"{d['viewport'][0]}x{d['viewport'][1]} viewport")
    r.check("all six sections are on screen and clickable",
            d["panel_reachable"] == d["panel_links"] == 6,
            f"{d['panel_reachable']} of {d['panel_links']} reachable")
    r.check("the page behind it cannot scroll", d["body_overflow"] == "hidden",
            f"body overflow is {d['body_overflow']!r}")
    r.check("the menu button shows the close mark while open",
            d["menu_icon"] == "#times", f"showing {d['menu_icon']!r}")
    r.check("there is no photograph in the dock", d["photos"] == 0,
            f"found {d['photos']}")

    print("\nmobile: the circuit either side of the panel")
    c = d["circuit"]
    r.check("both columns are drawn", c["wires"] == 16, f"{c['wires']} wires")
    r.check("every trace carries a charge", c["charges"] == 16,
            f"{c['charges']} charges")
    r.check("every charge is running", c["charges_running"] == 16,
            f"{c['charges_running']} of 16 running")
    r.check("every node is running", c["nodes_running"] == c["nodes"] == 18,
            f"{c['nodes_running']} of {c['nodes']} running")
    # The requirement was an animation that never stops and never looks like a
    # loop. Nothing may be finite, and no two may share a duration: equal
    # durations would resynchronise and the repeat would become visible.
    r.check("nothing is set to stop", c["infinite"] is True)
    r.check("no two charges share a duration",
            len(set(c["durations"])) == len(c["durations"]) == 16,
            f"durations: {sorted(c['durations'])}")

    print("\nmobile: closing it again")
    b.press_escape()
    d = b.probe()
    r.check("Escape closes the panel", d["open"] == "false")
    r.check("and the menu button reports itself collapsed",
            d["expanded"] == "false")
    r.check("no panel link is reachable once closed", d["panel_reachable"] == 0)
    r.check("scrolling is restored", d["body_overflow"] == "",
            f"body overflow is {d['body_overflow']!r}")
    r.check("focus returns to the menu button", b.js(
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
