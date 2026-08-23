#!/usr/bin/env python3
"""
Prove no page scrolls sideways, at the widths people actually hold.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_responsive.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing.

WHY THIS EXISTS
A page that scrolls sideways on a phone is the most visible layout failure
there is, and nothing in this repo could see it. audit_pages.py reads markup,
check_contrast.py reads colour, test_nav.py asks whether the navigation works
— none of them asks how wide the page turned out to be.

WHY AN IFRAME, AND NOT THE WINDOW
This is the whole reason the check is written the way it is.

**Firefox will not make a window narrower than about 500px**, which leaves a
viewport of about 488. Ask WebDriver for 320 and you measure 488, with no
error and no warning: the call succeeds, the
run goes green, and it reports that a width it never tested is fine. That is
worse than having no check, because it produces a record saying the narrowest
phones were covered.

So the page is loaded into an <iframe> of the width being tested, inside a
window that stays wide. An iframe establishes its own viewport: media queries,
100vw and clientWidth all resolve against the frame, so 320 means 320. The
frame is same-origin, so its document can be measured directly.

The check reports the viewport it actually measured, every time. If that
number ever stops matching the width asked for, the run is not to be believed.

WHAT IT ASSERTS
  - the document does not scroll horizontally: scrollWidth <= clientWidth
  - no link, button or form control is wider than the viewport
  - no tap target is under 24x24 CSS px — WCAG 2.2 SC 2.5.8, Level AA — with
    the criterion's own exceptions applied, so a label counts towards the
    control it names and an isolated small target with room around it passes

Two of the widths are criteria in their own right. 320px is SC 1.4.10 Reflow,
which is defined at exactly that width, so the no-sideways-scrolling assertion
is that criterion tested rather than merely argued. 640px is what a 1280px
desktop becomes at 200% zoom, which is SC 1.4.4 Resize Text.

The second is not implied by the first. .btn clips its own overflow for the
shine sweep and .cta-band clips again, so a button too wide for the screen is
cut off in silence — the page does not scroll and nothing looks broken except
the half a word that is missing. Both failures shipped; both are fixed; this
is what keeps them fixed.

FINDING THE CULPRIT
When the document overflows, the offending element is reported. Elements
inside something that clips them are skipped: a slider's off-screen slides
have a right edge far past the viewport and cannot extend the page, so
listing them buries the one element that can.
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

# 320 is the narrowest screen still in use and the one that found both bugs.
# It is also WCAG 2.2 SC 1.4.10 Reflow, which is defined as exactly this width
# — so the no-sideways-scrolling assertion below is that criterion, tested.
# 360 and 414 are the common Android and iPhone widths. 640 is what a 1280px
# desktop becomes at 200% zoom, which is SC 1.4.4 Resize Text. The rest are the
# breakpoints in layout.css, so a failure lands near a rule somebody wrote.
WIDTHS = [320, 360, 414, 640, 768, 1024, 1440]

# Every page, by the URL a visitor uses rather than the file behind it, so the
# two .php pages are exercised through PHP like everything else.
PAGES = [
    "/",
    "/404.html",
    "/pages/about/",
    "/pages/branding-and-advertisement/",
    "/pages/careers/",
    "/pages/company-profile/",
    "/pages/contact/",
    "/pages/privacy-policy/",
    "/pages/resource-certifications/",
    "/pages/services/",
    "/pages/services/cloud-infrastructure/",
    "/pages/services/cybersecurity/",
    "/pages/services/hr-solutions/",
    "/pages/services/it-consultancy-training/",
    "/pages/services/it-equipment-supply/",
    "/pages/services/software-development/",
]

# Runs in the outer window. Puts the page in a frame of the requested width,
# waits for it, then measures inside it. Returns {"loading": true} until the
# frame is ready, so the caller polls rather than sleeping a guessed amount.
PROBE = r"""
var width = %WIDTH%, url = %URL%;

var frame = document.getElementById('probe');
if (!frame) {
  frame = document.createElement('iframe');
  frame.id = 'probe';
  frame.style.border = '0';
  frame.style.height = '900px';
  document.body.appendChild(frame);
}
frame.style.width = width + 'px';

var want = url + '#' + width;
if (frame.getAttribute('data-showing') !== want) {
  frame.setAttribute('data-showing', want);
  frame.src = url;
  return {loading: true};
}

var doc = frame.contentDocument;
if (!doc || doc.readyState !== 'complete' || !doc.body) return {loading: true};

var view = frame.contentWindow;
var root = doc.documentElement;
var vw = root.clientWidth;
var sw = root.scrollWidth;

function describe(el) {
  var parts = [];
  for (var e = el; e && e.tagName && parts.length < 4; e = e.parentElement) {
    var cls = (e.className && e.className.baseVal !== undefined
                 ? e.className.baseVal : e.className || '').toString().trim();
    parts.unshift(e.tagName.toLowerCase() + (cls ? '.' + cls.split(/\s+/)[0] : ''));
  }
  return parts.join(' > ');
}

/* An element cannot extend the page if something between it and the root
   clips it and sits inside the viewport itself. Without this the report is
   filled with a carousel's off-screen slides. */
function isClipped(el) {
  for (var p = el.parentElement; p; p = p.parentElement) {
    var cs = view.getComputedStyle(p);
    if (cs.overflowX !== 'visible') {
      if (p.getBoundingClientRect().right <= vw + 1) return true;
    }
  }
  return false;
}

var culprits = [];
if (sw > vw + 1) {
  var all = doc.querySelectorAll('body *');
  for (var i = 0; i < all.length; i++) {
    var el = all[i], cs = view.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    if (cs.position === 'fixed') continue;
    var r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) continue;
    if (r.right <= vw + 1 || r.right > sw + 2) continue;
    if (isClipped(el)) continue;
    culprits.push(describe(el) + '  (right edge ' + Math.round(r.right) + 'px)');
  }
}

/* Clipped or not, a control wider than the screen has part of itself off it. */
var toowide = [];
var controls = doc.querySelectorAll('a[href], button, input, select, textarea');
for (var i = 0; i < controls.length; i++) {
  var el = controls[i], cs = view.getComputedStyle(el);
  if (cs.display === 'none' || cs.visibility === 'hidden') continue;
  var r = el.getBoundingClientRect();
  if (r.width > vw + 1) {
    var label = (el.textContent || el.value || '').trim().replace(/\s+/g, ' ');
    toowide.push(Math.round(r.width) + 'px  ' + describe(el)
                 + (label ? '  "' + label.slice(0, 40) + '"' : ''));
  }
}

/* --- tap targets: WCAG 2.2 SC 2.5.8, Level AA, 24x24 CSS px -------------
   Measured rather than read off the stylesheet, because a control can declare
   2.75rem and still be squeezed by the flex or grid it sits in. That is the
   only failure worth catching here, and a stylesheet cannot show it.

   Every exception in the criterion is applied, because without them the check
   reports things that are not wrong and gets switched off:

     - a control with an associated <label> is as large as the two together,
       since clicking the label operates the control
     - an inline link inside a run of text is exempt; its height is the line's
     - a small target with clear space around it is exempt however small
     - aria-hidden or tabindex="-1" is not a target at all — the contact
       form's honeypot is exactly that, and 26px wide on purpose
*/
var targets = [];
var candidates = doc.querySelectorAll(
  'a[href], button, input, select, textarea, summary, [role="button"]');
for (var i = 0; i < candidates.length; i++) {
  var el = candidates[i], cs = view.getComputedStyle(el);
  if (cs.display === 'none' || cs.visibility === 'hidden') continue;
  if (el.disabled) continue;
  if (el.getAttribute('tabindex') === '-1') continue;
  if (el.closest('[aria-hidden="true"]')) continue;

  var r = el.getBoundingClientRect();
  if (r.width < 1 || r.height < 1) continue;

  var box = {left: r.left, top: r.top, right: r.right, bottom: r.bottom};
  if (el.id) {
    var lab = doc.querySelector('label[for="' + CSS.escape(el.id) + '"]');
    if (lab) {
      var lr = lab.getBoundingClientRect();
      if (lr.width > 0 && lr.height > 0) {
        box.left = Math.min(box.left, lr.left);
        box.top = Math.min(box.top, lr.top);
        box.right = Math.max(box.right, lr.right);
        box.bottom = Math.max(box.bottom, lr.bottom);
      }
    }
  }
  box.w = box.right - box.left;
  box.h = box.bottom - box.top;
  box.cx = box.left + box.w / 2;
  box.cy = box.top + box.h / 2;
  targets.push({box: box, inline: cs.display === 'inline', el: el});
}

var small = [];
for (var i = 0; i < targets.length; i++) {
  var t = targets[i], box = t.box;
  if (Math.min(box.w, box.h) >= 24) continue;
  if (t.inline) continue;

  /* The spacing exception: undersized is allowed when nothing else is close
     enough to mis-tap. Centre to centre, against the same 24px. */
  var crowded = false;
  for (var j = 0; j < targets.length && !crowded; j++) {
    if (j === i) continue;
    var o = targets[j].box;
    var dx = box.cx - o.cx, dy = box.cy - o.cy;
    if (Math.sqrt(dx * dx + dy * dy) < 24) crowded = true;
  }
  if (!crowded) continue;

  var name = (t.el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30);
  small.push(Math.round(box.w) + 'x' + Math.round(box.h) + '  ' + describe(t.el)
             + (name ? '  "' + name + '"' : ''));
}

return {loading: false, vw: vw, over: sw - vw,
        culprits: culprits.slice(0, 3), toowide: toowide.slice(0, 3),
        small: small.slice(0, 4)};
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
            "moz:firefoxOptions": {"args": ["-headless"]}}}})
        self.s = f"{base}/session/{r['value']['sessionId']}"

    def size(self, w, h):
        rq("POST", self.s + "/window/rect", {"width": w, "height": h, "x": 0, "y": 0})
        time.sleep(0.3)

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})
        time.sleep(0.5)

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def measure(self, width, url, tries=40):
        script = PROBE.replace("%WIDTH%", str(width)).replace("%URL%", json.dumps(url))
        for _ in range(tries):
            out = self.js(script)
            if not out.get("loading"):
                return out
            time.sleep(0.25)
        return None

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def run(b: Browser, origin: str, r: Results) -> None:
    # The frame needs a page around it, and the window has to stay wide enough
    # that the widest frame is not itself clamped.
    b.size(max(WIDTHS) + 120, 1000)
    b.go(origin + "/404.html")

    for width in WIDTHS:
        print(f"\n{width}px")
        measured = None

        for page in PAGES:
            out = b.measure(width, origin + page)
            if out is None:
                r.check(f"{page} loads at {width}px", False, "the frame never finished loading")
                continue

            measured = out["vw"]
            r.check(
                f"{page} does not scroll sideways at {width}px",
                out["over"] <= 1,
                f"the page is {out['over']}px wider than its {out['vw']}px viewport\n          "
                + "\n          ".join(out["culprits"] or ["(no unclipped element found)"]),
            )
            r.check(
                f"{page} has no control wider than the screen at {width}px",
                not out["toowide"],
                "\n          ".join(out["toowide"]),
            )
            r.check(
                f"{page} has no tap target under 24px at {width}px",
                not out["small"],
                "\n          ".join(out["small"]),
            )

        # Said out loud every time, because the day this stops matching the
        # width asked for is the day every pass above becomes meaningless.
        # Firefox clamps a *window* at about 500px; a frame is why it does not
        # clamp here, and this line is the evidence that it did not.
        if measured is not None:
            slack = width - measured
            print(f"  measured viewport {measured}px"
                  f"  ({slack}px of scrollbar)  — {len(PAGES)} pages")
            if not 0 <= slack <= 40:
                r.check(f"the {width}px frame really is {width}px wide", False,
                        f"asked for {width}, measured {measured} — the frame is being "
                        f"clamped, so nothing checked at this width can be believed")


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
    print(f"{len(PAGES)} pages x {len(WIDTHS)} widths, each in a frame of its own")
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
