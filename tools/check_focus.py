#!/usr/bin/env python3
"""
Tab through every page and check the focus ring is visible and not covered.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_focus.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing.

WHY THIS EXISTS
check_hover.py proves thirteen KINDS of control react to a pointer. That is
the right shape for hover, because fifty identical cards behave identically.

Focus is not like that. Whether a focus ring can be seen depends on where the
element ended up, not on what kind of element it is — and this site has a
sticky header at the top of the viewport and, below 64em, a dock fixed to the
bottom of it. The browser scrolls a focused element into view and is perfectly
willing to put it underneath either. Two links of the same kind, one mid-page
and one in the footer, get different answers.

So this tabs. A real Tab key, one stop at a time, reading what the browser
made active — which is also the only honest way to ask the question a keyboard
user asks: I pressed Tab, can I see where I am.

WHAT IT ASSERTS, PER FOCUS STOP
  - the element is not entirely hidden behind something else
    (WCAG 2.2 SC 2.4.11 Focus Not Obscured (Minimum), Level AA)
  - it has a focus indicator: :focus-visible matches, and there is an outline
    or a shadow to see (SC 2.4.7 Focus Visible, Level AA)

WHY REDUCED MOTION IS REQUESTED
base.css sets `scroll-behavior: smooth`. Measuring straight after a Tab would
then read a position the page is still travelling through, and report elements
as covered by the dock merely because the scroll had not finished. Requesting
reduced motion turns scrolling instant — base.css already switches
`scroll-behavior: auto` under that preference — so what is measured is where
the element came to rest.

That makes the whole run depend on a browser preference actually having taken
effect, so it is checked at startup rather than assumed. If it silently failed
this file would report positions mid-scroll and the failures would look real.

  (Both readings were compared while writing this: with a 0.45s settle and
  with none, the same twelve findings appeared in the same places. The dock
  was covering them, not the animation.)
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

# Desktop has the sticky header; below 64em the dock replaces the header nav
# and is fixed to the bottom. Both can cover a focus ring, differently.
WIDTHS = [(1200, "desktop"), (520, "mobile")]

# Enough tabs to reach the footer of the longest page. The run reports how many
# stops each page actually had, so a page that grows past this is visible as a
# count that has hit the ceiling rather than as silence.
MAX_TABS = 70

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

MEASURE = r"""
var el = document.activeElement;
if (!el || el === document.body || el === document.documentElement)
  return {done: true};

function name(e) {
  var cls = (e.className && e.className.baseVal !== undefined
               ? e.className.baseVal : e.className || '').toString().trim();
  return e.tagName.toLowerCase() + (cls ? '.' + cls.split(/\s+/)[0] : '');
}

var cs = getComputedStyle(el);
var r = el.getBoundingClientRect();

/* Covered by what, if anything. The centre and four inset corners: if not one
   of them reaches the element, none of it is where a person is looking.
   SC 2.4.11 (Minimum) is about being ENTIRELY hidden, so one hit is a pass.

   Points outside the viewport are not evidence either way and are not
   counted — otherwise an element half off the bottom reads as covered. */
var pts = [[r.left + r.width / 2, r.top + r.height / 2],
           [r.left + 2, r.top + 2], [r.right - 2, r.top + 2],
           [r.left + 2, r.bottom - 2], [r.right - 2, r.bottom - 2]];
var sampled = 0, hits = 0, coveredBy = '';
for (var i = 0; i < pts.length; i++) {
  var x = pts[i][0], y = pts[i][1];
  if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) continue;
  sampled++;
  var at = document.elementFromPoint(x, y);
  if (at && (at === el || el.contains(at) || at.contains(el))) hits++;
  else if (at && !coveredBy) coveredBy = name(at);
}

/* An outline of zero width is not an indicator, and neither is one the
   element was already wearing. box-shadow counts because several controls
   here ring themselves that way instead. */
var ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0)
             || cs.boxShadow !== 'none';

return {
  done: false,
  name: name(el),
  text: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30),
  focusVisible: (function () {
    try { return el.matches(':focus-visible'); } catch (e) { return null; }
  })(),
  ring: ring,
  outline: cs.outlineStyle + ' ' + cs.outlineWidth,
  sampled: sampled, hits: hits, coveredBy: coveredBy,
  top: Math.round(r.top), height: Math.round(r.height)
};
"""

# Proves the preference arrived, and that base.css acted on it. Without both,
# every measurement below is taken while the page is still scrolling.
REDUCED_MOTION = """
return {
  media: matchMedia('(prefers-reduced-motion: reduce)').matches,
  scroll: getComputedStyle(document.documentElement).scrollBehavior
};
"""


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []

    def check(self, case, ok, detail=""):
        if ok:
            self.passed += 1
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
            "moz:firefoxOptions": {
                "args": ["-headless"],
                # See "WHY REDUCED MOTION IS REQUESTED" above. Confirmed at
                # startup by prove_reduced_motion(), not taken on trust.
                "prefs": {"ui.prefersReducedMotion": 1},
            }}}})
        self.s = f"{base}/session/{r['value']['sessionId']}"

    def size(self, w, h):
        rq("POST", self.s + "/window/rect", {"width": w, "height": h, "x": 0, "y": 0})
        time.sleep(0.3)

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(0.8)

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def tab(self):
        rq("POST", self.s + "/actions", {"actions": [{
            "type": "key", "id": "kb",
            "actions": [{"type": "keyDown", "value": ""},
                        {"type": "keyUp", "value": ""}]}]})

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def prove_reduced_motion(b: Browser, origin: str) -> None:
    b.go(origin + "/404.html")
    state = b.js(REDUCED_MOTION)
    if not state["media"] or state["scroll"] != "auto":
        raise SystemExit(
            "Reduced motion did not take effect: "
            f"prefers-reduced-motion={state['media']}, "
            f"scroll-behavior={state['scroll']!r} (wanted 'auto').\n"
            "Every measurement in this file would then be taken mid-scroll, and "
            "elements would be reported as covered by the dock because the page "
            "had not finished moving. Refusing to run.\n"
            "Check the ui.prefersReducedMotion pref in Browser.__init__ and the "
            "reduced-motion block in assets/css/base.css."
        )
    print("reduced motion is on, and base.css has made scrolling instant")


def run(b: Browser, origin: str, r: Results) -> None:
    prove_reduced_motion(b, origin)

    for width, label in WIDTHS:
        print(f"\n{label} ({width}px)")
        b.size(width, 900)

        for page in PAGES:
            b.go(origin + page)
            stops = 0

            for _ in range(MAX_TABS):
                b.tab()
                seen = b.js(MEASURE)
                if seen.get("done"):
                    break
                stops += 1

                where = (f"{seen['name']} {seen['text']!r} at y={seen['top']} "
                         f"(height {seen['height']})")

                if seen["sampled"]:
                    r.check(
                        f"{label} {page} stop {stops}: focus is not hidden",
                        seen["hits"] > 0,
                        f"{where}\n          entirely covered by "
                        f"{seen['coveredBy'] or 'something'} — SC 2.4.11",
                    )

                r.check(
                    f"{label} {page} stop {stops}: focus can be seen",
                    seen["focusVisible"] is not False and seen["ring"],
                    f"{where}\n          :focus-visible={seen['focusVisible']}, "
                    f"outline={seen['outline']!r} — SC 2.4.7",
                )

            # Printed rather than asserted: the number is the tab order's
            # length, and a page that has hit MAX_TABS is one this file has
            # stopped covering to the end.
            ceiling = "  (hit the ceiling — raise MAX_TABS)" if stops >= MAX_TABS else ""
            print(f"  {stops:>3} focus stops   {page}{ceiling}")


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

    print(f"firefox (headless) against 127.0.0.1:{web_port}")
    print(f"{len(PAGES)} pages x {len(WIDTHS)} widths, tabbed one stop at a time")
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
        print(f"\n{len(results.failed)} failed:")
        for name in results.failed[:20]:
            print(f"  - {name}")
        if len(results.failed) > 20:
            print(f"  … and {len(results.failed) - 20} more")
        sys.exit(1)


if __name__ == "__main__":
    main()
