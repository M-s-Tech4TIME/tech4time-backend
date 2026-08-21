#!/usr/bin/env python3
"""
Move a real pointer over every kind of interactive element and check that
something changes.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_hover.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing.

WHY NOT GREP
Searching the stylesheets for ":hover" answers a different question. A rule can
exist and change nothing — overridden later in the cascade, or setting a
property to the value the element already had. It can also be missing from the
file you searched and present in another. The only reliable question is the one
a visitor asks: I put the pointer on this, did it react.

So this hovers the element and diffs the computed style of it and its subtree.
The subtree matters because plenty of the hovers here are declared on a parent
and land on a child — `.timeline__item:hover .timeline__box` changes nothing
about the item itself.

ONE REPRESENTATIVE PER KIND
Fifty tool cards behave identically, so hovering all fifty proves nothing the
first one did not. Elements are grouped by tag plus class, and one of each is
probed. The report is a list of kinds, which is also the list you would act on.

WHAT COUNTS AS A REACTION
Any change to colour, background, border, shadow, transform, translate,
opacity, or the underline. Not size or position on their own: a hover that
reflows the page is a defect elsewhere.
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

PAGES = [
    "/",
    "/pages/about/",
    "/pages/services/",
    "/pages/services/cybersecurity/",
    "/pages/company-profile/",
    "/pages/careers/",
    "/pages/branding-and-advertisement/",
    "/pages/resource-certifications/",
    "/pages/privacy-policy/",
    "/pages/contact/",
    "/404.html",
]

# Things that are interactive but have nothing to say on hover, with the reason
# each is exempt. Anything not listed here is expected to react.
EXEMPT = {
    "a.skip-link":
        "only ever seen through the keyboard; it has a focus style instead",
    "input.field__control":
        "text fields react to focus, not to the pointer passing over",
    "textarea.field__control": "same as the text fields",
    "select.field__control": "same as the text fields",
    "input.honeypot__field":
        "the spam trap, hidden from people entirely",
    "input.field__checkbox":
        "a native checkbox; the browser draws its own hover, and nothing in "
        "the computed style moves when it does",
}

# Collect one representative of each kind and label it so the pointer can find
# it again by selector. Restricted to <main>: the header, footer and dock are
# shared markup with their own tests.
COLLECT = """
var SEEN = {};
var out = [];
var nodes = document.querySelectorAll(
  'main a[href], main button, main summary, main [role="button"], ' +
  'main input, main select, main textarea');

/* An element that is already selected, current or open often reaches its
   hover appearance by another route: the first tab in a strip is styled as the
   active one, and hovering it changes nothing because it is already there.
   Probing that one would report the whole kind as dead. */
function isActive(el) {
  return el.matches(
    '[aria-selected="true"], [aria-current], [aria-pressed="true"], ' +
    '[open], [open] > summary, .is-active, .is-selected');
}

Array.prototype.forEach.call(nodes, function (el) {
  var r = el.getBoundingClientRect();
  if (r.width < 4 || r.height < 4) return;              // clipped or hidden
  if (getComputedStyle(el).visibility === 'hidden') return;

  var sig = el.tagName.toLowerCase() +
    (el.className ? '.' + String(el.className).trim().split(/\\s+/)[0] : '');

  var seen = SEEN[sig];
  if (seen && (seen.resting || isActive(el))) return;

  /* A resting example replaces an active one already recorded. The marker has
     to move with it, or the selector still finds the element it replaced. */
  if (seen) { seen.el.removeAttribute('data-hover-probe'); }
  var index = seen ? seen.index : out.length;

  SEEN[sig] = {index: index, resting: !isActive(el), el: el};
  el.setAttribute('data-hover-probe', index);
  out[index] = sig;
});
return out;
"""

# A fingerprint of the element and its subtree. Compared before and after the
# pointer arrives; if it is unchanged, nothing reacted.
SNAPSHOT = """
var el = document.querySelector('[data-hover-probe="' + arguments[0] + '"]');
if (!el) return null;
var PROPS = ['color', 'backgroundColor', 'backgroundImage', 'borderColor',
             'boxShadow', 'transform', 'translate', 'opacity', 'filter',
             'textDecorationLine', 'outlineColor'];

/* Up as well as down. A good many hovers here are declared on a wrapper and
   land on it or on a sibling — `.job:hover` styles the whole <details> when
   the pointer is on its <summary>, and `.timeline__item:hover .timeline__box`
   changes nothing about the element under the pointer at all. Looking only at
   the element and its descendants reported both as dead. */
var nodes = [];
for (var up = el; up && up !== document.body; up = up.parentElement) {
  nodes.push(up);
  if (nodes.length >= 4) break;
}
nodes = nodes.concat(
  Array.prototype.slice.call(el.querySelectorAll('*')).slice(0, 24));

/* ::before and ::after count too. A good deal of this site's hover work is
   drawn by a pseudo-element rather than by the element — the slideshow dot is
   a 44px hit area with the visible mark in its ::after, and the shine sweep
   across a primary button is nothing but an ::after. Reading only the elements
   reported the dot as dead when hovering it plainly changes it.

   A pseudo with `content: none` is not rendered at all, so its computed style
   is not evidence of anything; it contributes a constant instead, and cannot
   manufacture a reaction that nobody can see. */
function sample(node) {
  var out = [];
  [null, "::before", "::after"].forEach(function (pseudo) {
    var s = getComputedStyle(node, pseudo);
    if (pseudo && s.content === "none") {
      out.push("(not rendered)");
      return;
    }
    out.push(PROPS.map(function (p) { return s[p]; }).join("|"));
  });
  return out.join("&");
}

return {
  /* Did the pointer actually arrive? An element can fail to react because
     nothing is declared, or because something is sitting on top of it — two
     different problems, and worth telling apart. */
  landed: el.matches(':hover'),
  style: nodes.map(sample).join('~')
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
        with urllib.request.urlopen(req, timeout=90) as r:
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
    """Best effort. This sandbox refuses signals even to a direct child, so a
    leaked geckodriver has to be cleared with pkill from a normal shell."""
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
            "moz:firefoxOptions": {
                "args": ["-headless"],
                # Run the audit as a visitor who has asked for less motion.
                # Two reasons, both about the measurement being sound:
                #
                # The scroll reveal is off, so scrolling an element into view
                # cannot start an animation that changes its computed style
                # while the before and after readings are being taken — which
                # would look exactly like a reaction to the pointer and pass a
                # kind that does nothing.
                #
                # And transitions land immediately rather than over 250ms, so
                # the readings do not depend on a sleep being long enough.
                # base.css only shortens motion, it does not remove the state
                # a hover arrives at, so what is being asked here is unchanged.
                #
                # The pointer capabilities have to be declared as well.
                # Headless Firefox has no pointing device and reports
                # (hover: none) with (pointer: none), which switches off every
                # rule inside `@media (hover: hover) and (pointer: fine)` for
                # the whole session — a hover effect written there would be
                # reported as missing when it works for everyone with a mouse.
                # 2 is fine, 4 is hover.
                "prefs": {
                    "ui.prefersReducedMotion": 1,
                    "ui.primaryPointerCapabilities": 6,
                    "ui.allPointerCapabilities": 6,
                },
            }}}})
        self.s = f"{base}/session/{r['value']['sessionId']}"
        # Desktop width: the dock is a fixed overlay below 64em and would sit
        # between the pointer and anything under it.
        rq("POST", self.s + "/window/rect",
           {"width": 1440, "height": 900, "x": 0, "y": 0})

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(0.8)

    def js(self, script, args=()):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": list(args)})["value"]

    def find(self, selector):
        r = rq("POST", self.s + "/elements",
               {"using": "css selector", "value": selector})["value"]
        return [e[W3C] for e in r]

    def bring_into_view(self, index):
        """Firefox refuses a pointer move to a target outside the viewport —
        unlike the click endpoint, the actions API does not scroll for you."""
        self.js(
            "document.querySelector('[data-hover-probe=\"' + arguments[0] + "
            "'\"]').scrollIntoView({block: 'center'});", [index])
        time.sleep(0.2)

    def hover(self, element_id):
        rq("POST", self.s + "/actions", {"actions": [{
            "type": "pointer", "id": "mouse",
            "parameters": {"pointerType": "mouse"},
            "actions": [{
                "type": "pointerMove", "duration": 0,
                "origin": {W3C: element_id}, "x": 0, "y": 0}]}]})
        time.sleep(0.2)

    def unhover(self):
        """Park the pointer in the top-left corner, clear of everything."""
        rq("POST", self.s + "/actions", {"actions": [{
            "type": "pointer", "id": "mouse",
            "parameters": {"pointerType": "mouse"},
            "actions": [{"type": "pointerMove", "duration": 0,
                         "origin": "viewport", "x": 2, "y": 2}]}]})
        time.sleep(0.2)

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def audit(b: Browser, origin: str, path: str, r: Results, seen: dict) -> None:
    b.go(origin + path)
    kinds = b.js(COLLECT)
    reacted, skipped = 0, 0

    for index, sig in enumerate(kinds):
        # Each kind is probed once for the whole site, not once per page.
        if sig in seen:
            continue
        seen[sig] = path

        if sig in EXEMPT:
            skipped += 1
            print(f"  --    {sig} is exempt: {EXEMPT[sig]}")
            continue

        ids = b.find(f'[data-hover-probe="{index}"]')
        if not ids:
            continue

        b.unhover()
        b.bring_into_view(index)
        before = b.js(SNAPSHOT, [index])
        b.hover(ids[0])
        after = b.js(SNAPSHOT, [index])

        if before is None or after is None:
            r.check(f"{sig} could be measured   ({path})", False,
                    "the probe marker went missing between readings")
            continue

        if not after["landed"]:
            # Not a styling gap: the pointer never got there. Something is
            # covering it, which is a usability bug in its own right.
            r.check(f"{sig} can be reached by the pointer   ({path})", False,
                    "the pointer was moved to the centre of this element and "
                    "landed on something else — it is covered")
            continue

        ok = before["style"] != after["style"]
        if ok:
            reacted += 1
        r.check(f"{sig} reacts to the pointer   ({path})", ok,
                "the pointer is on it and nothing changed, in the element, its "
                "wrappers or its contents: no colour, border, shadow, "
                "transform or underline")

    print(f"  {path:44s} {reacted} kinds react, {skipped} exempt")


def main() -> None:
    missing = [n for n in ("php", "geckodriver", "firefox") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        print("This check needs Firefox and geckodriver as well as the PHP CLI.")
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

    origin = f"http://127.0.0.1:{web_port}"
    print(f"firefox (headless) against 127.0.0.1:{web_port}\n")
    results = Results()
    seen: dict[str, str] = {}
    browser = None
    try:
        browser = Browser(drv_port)
        for path in PAGES:
            audit(browser, origin, path, results, seen)
    finally:
        if browser:
            browser.quit()
        for proc in (drv, php):
            stop(proc)

    total = results.passed + len(results.failed)
    print(f"\n{results.passed}/{total} interactive kinds react to the pointer")
    if results.failed:
        print("\nno reaction:")
        for name in results.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
