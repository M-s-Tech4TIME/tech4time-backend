#!/usr/bin/env python3
"""
Audit every page as the browser actually paints it, in both themes.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_dark_mode.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing, so it does not block a machine that only has PHP.

WHY THIS EXISTS ALONGSIDE check_contrast.py
check_contrast.py reads theme.css and proves the *tokens* meet WCAG AA. It
cannot prove a page uses them correctly. A card that sets --text-muted on a
--bg-elevated surface passes at the token level and can still fail once a page
puts that card on a different background, or hard-codes a colour that does not
flip with the theme.

So this one asks the rendered DOM instead. For every element that actually
paints text it walks up the ancestor chain to find the background that is
really behind it, composites any alpha, and measures the ratio. Then it loads
the same page in the other theme and compares: anything whose colours are
byte-identical in both modes is hard-coded, which is sometimes deliberate
(the homepage terminal, the white plates behind client logos) and sometimes a
bug. Deliberate cases are listed in ALLOWED_INVARIANTS with the reason.
"""

import json
import os
import re
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
    "/pages/services/software-development/",
    "/pages/services/cloud-infrastructure/",
    "/pages/services/hr-solutions/",
    "/pages/services/it-equipment-supply/",
    "/pages/services/it-consultancy-training/",
    "/pages/company-profile/",
    "/pages/careers/",
    "/pages/branding-and-advertisement/",
    "/pages/resource-certifications/",
    "/pages/privacy-policy/",
    "/pages/contact/",
    "/404.html",
]

# Each pass is (label, width, height, scope, open_drawer). Below 64em the
# navigation is the dock, and its panel of sections only exists once opened —
# so it needs a pass of its own or its colours are never measured at all. The
# pass is scoped to the panel because that is the only thing the opening
# changes.
PASSES = [
    ("desktop", 1440, 1000, "body", False),
    ("mobile", 390, 900, "body", False),
    ("mobile nav open", 390, 900, "#dock-panel", True),
]

# Colours that are meant to be identical in both themes, and why. Matched on
# the reported "tag.class" signature.
ALLOWED_INVARIANTS = {
    "terminal": "the homepage terminal is a terminal — dark in both themes by design",
    "asset__preview": "the brand asset previews show the logo on its two intended grounds",
    # Everything below paints --artwork-plate, which must not flip. See the
    # token's note in theme.css.
    "client-card": "other companies' logos, on the artwork plate",
    "tech-sphere__face": "product marks, on the artwork plate",
    "logo-card": "the general logo tile, on the artwork plate",
    "destination-card__media": "black line-art illustration, on the artwork plate",
    "about-split__image": "black line-art illustration, on the artwork plate",
}

# --on-accent is deliberately one value in both themes: it is the ink that sits
# on the silver fill, and the silver fill is light in both. Anything painted in
# it is invariant on purpose.
ON_ACCENT = "rgb(17, 17, 19)"

# ---------------------------------------------------------------------------
# The audit itself, run inside the page.
# ---------------------------------------------------------------------------
AUDIT_JS = r"""
var out = {text: [], gradient: [], unknown: [], images: []};
var scope = document.querySelector(SCOPE) || document.body;

/* Colour parsing, via the browser's own parser.

   getComputedStyle does NOT always hand back rgb(): the site header's
   background is color-mix(in srgb, var(--bg-base) 85%, transparent), and
   Firefox reports that function verbatim. Reading the numbers out of the
   string with a regex turns "85%" into an alpha of 85 and every measurement
   downstream is nonsense — which is exactly the bug the first run of this
   script produced. A canvas fillStyle round-trip resolves anything the CSS
   parser accepts (color-mix, oklch, slash syntax, named colours) down to a
   plain rgba() string. */
var probe = document.createElement("canvas").getContext("2d");

function rgb(value) {
  value = String(value || "").trim();
  if (!value || value === "none") return null;
  /* Reset first: an unparseable value leaves fillStyle at its previous
     setting rather than throwing, so without this a bad colour would silently
     report whatever was measured before it. */
  probe.fillStyle = "rgba(0, 0, 0, 0)";
  probe.fillStyle = value;
  var out = String(probe.fillStyle);

  /* fillStyle normalises a fully opaque colour to "#rrggbb" — which for white
     is "#ffffff", a string containing no decimal digits at all. Testing for
     the hex form BEFORE reaching for digits is the whole point of this order:
     the other way round, every white surface parses as null, backdrop() walks
     straight past the plate it was asked about, and the measurement is taken
     against something further up the page. */
  if (out.charAt(0) === "#") {
    return [parseInt(out.substr(1, 2), 16),
            parseInt(out.substr(3, 2), 16),
            parseInt(out.substr(5, 2), 16), 1];
  }
  var m = out.match(/[\d.]+/g);
  if (!m) return null;
  var v = m.map(Number);
  if (v.length === 3) v.push(1);
  return v;
}

function show(c) {
  return c[3] === 1
    ? "rgb(" + Math.round(c[0]) + ", " + Math.round(c[1]) + ", " + Math.round(c[2]) + ")"
    : "rgba(" + Math.round(c[0]) + ", " + Math.round(c[1]) + ", " +
      Math.round(c[2]) + ", " + c[3] + ")";
}

/* Composite a partially transparent colour over what is behind it. */
function over(fg, bg) {
  var a = fg[3];
  return [fg[0] * a + bg[0] * (1 - a),
          fg[1] * a + bg[1] * (1 - a),
          fg[2] * a + bg[2] * (1 - a), 1];
}

function lum(c) {
  var a = [c[0], c[1], c[2]].map(function (v) {
    v /= 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
}

function ratio(f, b) {
  var l1 = lum(f), l2 = lum(b);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

/* An element painting its background into its own glyphs is not painting it
   behind them — the gradient IS the text. Such an element must be stepped over
   when looking for what is actually behind, and its own text cannot be
   measured as a single number at all. */
function clipsToText(style) {
  var clip = style.webkitBackgroundClip || style.backgroundClip;
  return String(clip) === "text";
}

/* The background actually behind an element: the first opaque ancestor fill,
   with any translucent layers above it composited back down in order. */
function backdrop(el) {
  var stack = [], node = el, style, c;
  while (node && node.nodeType === 1) {
    style = getComputedStyle(node);
    if (style.backgroundImage && style.backgroundImage !== "none"
        && !clipsToText(style)) {
      return {unknown: true, why: style.backgroundImage.slice(0, 40), at: sig(node)};
    }
    if (!clipsToText(style)) {
      c = rgb(style.backgroundColor);
      if (c && c[3] > 0) {
        stack.push(c);
        if (c[3] === 1) break;
      }
    }
    node = node.parentElement;
  }
  /* Nothing opaque all the way up means the canvas itself, which the UA paints
     from color-scheme; body always sets a token background here, so this is a
     fallback that should not be reached. */
  var base = rgb(getComputedStyle(document.body).backgroundColor) || [255, 255, 255, 1];
  if (base[3] < 1) base = [255, 255, 255, 1];
  for (var i = stack.length - 1; i >= 0; i--) base = over(stack[i], base);
  return {color: base};
}

function sig(el) {
  var cls = (el.className && el.className.baseVal !== undefined)
    ? el.className.baseVal : el.className;
  cls = String(cls || "").trim().split(/\s+/).filter(Boolean).slice(0, 2).join(".");
  return el.tagName.toLowerCase() + (cls ? "." + cls : "");
}

function paints(el) {
  var r = el.getBoundingClientRect();
  /* Screen-reader-only text is clipped to a 1px box and never shown to
     anyone with eyes, so its contrast is not a real question — the .visually-
     hidden labels on the client logos were the only thing this whole audit
     reported until they were excluded. No glyph renders in two pixels. */
  if (r.width <= 2 || r.height <= 2) return false;
  var clip = getComputedStyle(el).clipPath;
  if (clip && clip.indexOf("inset(50%") === 0) return false;
  /* A closed drawer is pushed off the side rather than hidden, so it still has
     a box. Only horizontal escape counts — everything below the fold is
     ordinary content. */
  if (r.right < -4 || r.left > document.documentElement.clientWidth + 4) return false;
  var s = getComputedStyle(el);
  return s.visibility !== "hidden" && s.display !== "none" && Number(s.opacity) > 0.05;
}

/* Only elements holding their own text — otherwise a wrapper is reported for
   the same string as the element that really renders it. */
function ownText(el) {
  for (var i = 0; i < el.childNodes.length; i++) {
    var n = el.childNodes[i];
    if (n.nodeType === 3 && n.textContent.trim().length > 1) return n.textContent.trim();
  }
  return null;
}

var all = scope.querySelectorAll("*");
var snapshot = {};

for (var i = 0; i < all.length; i++) {
  var el = all[i];
  if (!paints(el)) continue;

  var style = getComputedStyle(el);
  var fill = rgb(style.backgroundColor);
  var ink = rgb(style.color);
  /* Kept as two fields, not one string. Joining them hides the case this
     check exists for: a fill that never changes sitting under text whose
     colour does, which reads as "changed" and is never reported. */
  snapshot[sig(el) + "|" + i] = {
    ink: ink ? show(ink) : null,
    fill: fill ? show(fill) : null
  };

  /* Artwork cannot be recoloured by the theme, so what matters is the ground
     it was given. Paired with the ink measurements taken from the files
     themselves, this is what catches a dark logo on a dark plate. */
  if (el.tagName === "IMG") {
    var behind = backdrop(el);
    out.images.push({
      src: el.currentSrc || el.src,
      bg: behind.unknown ? null : show(behind.color),
      w: Math.round(el.getBoundingClientRect().width)
    });
  }

  var text = ownText(el);
  if (!text) continue;

  /* Gradient-filled text: the colour is transparent and the ink is a ramp, so
     there is no single ratio to compute. Listed for the eye instead. */
  if (clipsToText(style)) {
    out.gradient.push({sig: sig(el), text: text.slice(0, 60),
                       fill: style.backgroundImage.slice(0, 60)});
    continue;
  }

  var back = backdrop(el);
  if (back.unknown) {
    out.unknown.push({sig: sig(el), why: back.why, at: back.at,
                      text: text.slice(0, 60)});
    continue;
  }

  if (!ink) continue;
  var fg = ink[3] < 1 ? over(ink, back.color) : ink;

  var size = parseFloat(style.fontSize);
  var weight = parseInt(style.fontWeight, 10) || 400;
  var large = size >= 24 || (size >= 18.66 && weight >= 700);
  var need = large ? 3.0 : 4.5;
  var got = ratio(fg, back.color);

  if (got < need) {
    out.text.push({
      sig: sig(el), text: text.slice(0, 60), got: Math.round(got * 100) / 100,
      need: need, fg: show(fg), bg: show(back.color),
      size: Math.round(size * 10) / 10, weight: weight
    });
  }
}

out.snapshot = snapshot;
return out;
"""


class Results:
    def __init__(self):
        self.contrast = []
        self.invariant = {}
        self.unknown = {}
        self.gradient = {}
        self.images = []
        self.artwork = []
        self.artwork_notes = []
        self.pages = 0

    def ok(self) -> bool:
        return not self.contrast and not self.artwork


def srgb_luminance(r: int, g: int, b: int) -> float:
    out = []
    for v in (r, g, b):
        v /= 255
        out.append(v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4)
    return 0.2126 * out[0] + 0.7152 * out[1] + 0.0722 * out[2]


def ink_of(path: Path):
    """What the visible pixels of an image are like: how much of it is see-
    through, and how dark the part that does show up is.

    Only the opaque pixels count. A logo that is 88% transparent is judged on
    the 12% that paints, because that 12% is the whole logo — averaging in the
    transparent area would report every transparent logo as light and miss
    exactly the ones at risk."""
    try:
        from PIL import Image
    except ImportError:
        return None
    try:
        with Image.open(path) as im:
            im = im.convert("RGBA")
            im.thumbnail((96, 96))
            pixels = list(im.getdata())
    except Exception:
        return None

    opaque = [p for p in pixels if p[3] > 128]
    if not opaque:
        return None
    lum = [srgb_luminance(p[0], p[1], p[2]) for p in opaque]
    lum.sort()
    return {
        "transparent": 1 - len(opaque) / len(pixels),
        # The median of the ink, so a white halo inside the artwork does not
        # cancel out the dark strokes that carry the shape.
        "median": lum[len(lum) // 2],
    }


def check_artwork(res: Results) -> None:
    """Join what each image looks like to what it was placed on, per theme.

    WHAT COUNTS AS A DEFECT HERE
    Not simply "low contrast". WCAG 1.4.11 exempts logotypes from any minimum
    contrast, and rightly: React's cyan and Flutter's blue measure poorly on
    white, are the vendors' own brand colours, look exactly the same on the
    vendors' own sites, and are not ours to recolour. Reporting those as
    failures would bury the one thing this check exists to find.

    The defect is a mark that READS IN ONE THEME AND NOT THE OTHER. That is a
    theming mistake — the plate under it flips when it should not — and it is
    always fixable on our side, by giving the artwork a ground of its own.
    The other reportable case is a mark that is invisible in both themes,
    which is a placement problem rather than a theming one.

    Anything low in both themes but not invisible is listed as a note, not a
    failure: it is the vendor's own contrast, and it is the same everywhere."""
    cache: dict[str, dict | None] = {}
    by_image: dict[tuple, dict] = {}

    for hit in res.images:
        rel = hit["src"].split("/", 3)[-1] if "://" in hit["src"] else hit["src"]
        rel = rel.lstrip("/").split("?")[0]
        if rel.endswith(".svg"):
            continue  # currentColor and inline fills; not a bitmap question
        if rel not in cache:
            cache[rel] = ink_of(ROOT / rel)
        stats = cache[rel]
        if not stats or stats["transparent"] < 0.15:
            continue

        m = re.findall(r"[\d.]+", hit["bg"])
        if len(m) < 3:
            continue
        back = srgb_luminance(*(int(float(v)) for v in m[:3]))
        ratio = ((max(stats["median"], back) + 0.05)
                 / (min(stats["median"], back) + 0.05))

        entry = by_image.setdefault(
            (rel, hit["page"]),
            {"src": rel, "page": hit["page"], "transparent": stats["transparent"]})
        # Worst placement of this image on this page, per theme.
        if ratio < entry.get(hit["theme"], (999, ""))[0]:
            entry[hit["theme"]] = (ratio, hit["bg"])

    for entry in by_image.values():
        if "light" not in entry or "dark" not in entry:
            continue
        light, dark = entry["light"], entry["dark"]
        worse, better = min(light[0], dark[0]), max(light[0], dark[0])

        if worse >= 3.0:
            continue

        if better >= 3.0:
            reason = "reads in one theme, not the other"
        elif worse < 1.5:
            reason = "effectively invisible in both themes"
        else:
            res.artwork_notes.append(dict(entry, light=light, dark=dark))
            continue

        res.artwork.append(dict(entry, light=light, dark=dark, reason=reason))


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
    """Shut a helper process down, as far as the environment allows.

    Deleting the WebDriver session is what actually matters, and the callers
    all do it in a finally block — that is what makes Firefox exit and release
    its memory. This closes the driver behind it.

    A caveat worth knowing rather than discovering: under a confined runner
    (snap-installed geckodriver, or an agent sandbox) every signal here can be
    refused with EPERM, including SIGKILL to the script's own direct child. On
    such a machine geckodriver lingers after the run — idle, a few MB each,
    but one per run. `pkill geckodriver` from an ordinary shell clears them."""
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
                # Audit the page with the scroll reveal switched off.
                #
                # Not because motion affects colour — it does not — but because
                # paints() below skips anything at opacity 0.05 or less, and an
                # element the reveal has not reached yet is at zero. It would
                # be dropped from the audit silently, and the run would still
                # report every page passing while quietly checking less of them.
                # With reduced motion requested, theme-init.js never arms the
                # reveal and nothing is ever transparent. The self-test at
                # startup confirms this actually took effect.
                "prefs": {"ui.prefersReducedMotion": 1},
            }}}})
        self.s = f"{base}/session/{r['value']['sessionId']}"

    def size(self, w, h):
        rq("POST", self.s + "/window/rect", {"width": w, "height": h, "x": 0, "y": 0})

    def go(self, url):
        rq("POST", self.s + "/url", {"url": url})

    def js(self, script):
        return rq("POST", self.s + "/execute/sync",
                  {"script": script, "args": []})["value"]

    def js_async(self, script):
        return rq("POST", self.s + "/execute/async",
                  {"script": script, "args": []})["value"]

    def settle(self):
        """Scroll the document end to end so lazy images load, then return."""
        pending = self.js_async(SCROLL_THROUGH)
        if pending:
            time.sleep(1.0)

    def load_themed(self, url, theme):
        """Set the stored preference, then load — theme-init.js reads it in
        <head>, so the page paints in that theme from the first frame."""
        self.go(url)
        self.js(f"localStorage.setItem('tech4time-theme', '{theme}');")
        self.go(url)
        time.sleep(0.5)
        actual = self.js("return document.documentElement.getAttribute('data-theme')")
        if actual != theme:
            raise SystemExit(
                f"theme did not apply on {url}: asked for {theme}, got {actual!r}.\n"
                "Check the localStorage key in assets/js/theme-init.js."
            )

    def quit(self):
        try:
            rq("DELETE", self.s)
        except Exception:
            pass


def allowed(signature: str) -> str | None:
    for token, why in ALLOWED_INVARIANTS.items():
        if token in signature:
            return why
    return None


# Run once before anything is measured. The colour resolver is the foundation
# every number here rests on, and when it is wrong it does not report an error
# — it reports a clean pass. That happened: white parsed as null, so backdrop()
# walked past every white plate and measured against the page behind it.
SELFTEST_JS = r"""
var probe = document.createElement("canvas").getContext("2d");
function rgb(value) {
  probe.fillStyle = "rgba(0, 0, 0, 0)";
  probe.fillStyle = String(value);
  var out = String(probe.fillStyle);
  if (out.charAt(0) === "#") {
    return [parseInt(out.substr(1, 2), 16), parseInt(out.substr(3, 2), 16),
            parseInt(out.substr(5, 2), 16), 1];
  }
  var m = out.match(/[\d.]+/g);
  if (!m) return null;
  var v = m.map(Number);
  if (v.length === 3) v.push(1);
  return v;
}
var cases = [
  ["#ffffff", [255, 255, 255, 1]],
  ["#fafafa", [250, 250, 250, 1]],
  ["white", [255, 255, 255, 1]],
  ["rgb(11, 11, 12)", [11, 11, 12, 1]],
  ["rgba(17, 17, 19, 0.5)", [17, 17, 19, 0.5]],
  ["color-mix(in srgb, rgb(250, 250, 250) 85%, transparent)", [250, 250, 250, 0.85]]
];
var bad = [];
for (var i = 0; i < cases.length; i++) {
  var got = rgb(cases[i][0]);
  if (JSON.stringify(got) !== JSON.stringify(cases[i][1])) {
    bad.push(cases[i][0] + " -> " + JSON.stringify(got) +
             " (expected " + JSON.stringify(cases[i][1]) + ")");
  }
}
return bad;
"""

OPEN_DRAWER = """
var t = document.querySelector('[data-nav-toggle]');
if (t && t.getAttribute('aria-expanded') !== 'true') { t.click(); }
return true;
"""

# Measured only after the transition has run. Clicking and measuring in one
# synchronous script reports the panel as it was before the click: the style
# change is applied, but the transition starts on the next frame, so computed
# visibility is still "hidden" — and a hidden element is not hit-testable, so
# every link comes back unreachable and the guard below fires on working code.
MEASURE_DRAWER = """
var n = document.querySelector('[data-nav-drawer]');
var r = n.getBoundingClientRect();
var items = n.querySelectorAll('a[href]');
var reachable = 0;
for (var i = 0; i < items.length; i++) {
  var q = items[i].getBoundingClientRect();
  if (q.width < 1 || q.height < 1) continue;
  var at = document.elementFromPoint(q.x + q.width / 2, q.y + q.height / 2);
  if (at && (at === items[i] || items[i].contains(at))) reachable++;
}
return {
  open: n.getAttribute('data-open'),
  reachable: reachable,
  items: items.length,
  rect: Math.round(r.width) + "x" + Math.round(r.height),
  viewport: window.innerWidth + "x" + window.innerHeight
};
"""

# Walk the whole document so loading="lazy" images actually fetch. Until one
# loads it has no intrinsic size, and with width:auto in the CSS it measures
# 0x0 — so it is skipped as "not painting" and never checked. That silently
# hid every below-the-fold logo from the artwork check.
#
# behavior: 'instant' because base.css sets scroll-behavior: smooth on <html>,
# and that applies to programmatic scrolls: a plain scrollTo starts an
# animation instead of moving, so the walk falls behind its own loop and never
# reaches the bottom of a long page. The reduced-motion preference this check
# runs under happens to force the same thing, but relying on that would make
# this quietly depend on a setting made for an unrelated reason.
SCROLL_THROUGH = """
var done = arguments[arguments.length - 1];
var y = 0, step = window.innerHeight;
(function next() {
  if (y < document.body.scrollHeight) {
    window.scrollTo({top: y, behavior: 'instant'});
    y += step;
    setTimeout(next, 60);
    return;
  }
  window.scrollTo({top: 0, behavior: 'instant'});
  setTimeout(function () {
    var imgs = Array.prototype.slice.call(document.images);
    done(imgs.filter(function (i) { return !i.complete; }).length);
  }, 400);
})();
"""


def audit_page(b: Browser, origin: str, path: str, label: str,
               scope: str, drawer: bool, res: Results):
    url = origin + path
    snaps = {}

    for theme in ("light", "dark"):
        b.load_themed(url, theme)
        b.settle()
        if drawer:
            b.js(OPEN_DRAWER)
            # Only then measure: the transitions have to land first.
            time.sleep(1.2)
            state = b.js(MEASURE_DRAWER)
            if state["open"] != "true":
                raise SystemExit(f"the nav drawer would not open on {path}")
            # An audit that measures colours inside a panel nobody can reach
            # reports a clean pass, which is what happened while the old drawer
            # was clamped to the header by backdrop-filter: the elements still
            # had boxes, and boxes still have contrast. Reachability is the
            # question that catches it. Full coverage is asserted by
            # tools/test_nav.py; this is only here so a broken panel stops the
            # audit rather than being quietly measured.
            if state["reachable"] != state["items"] or not state["items"]:
                raise SystemExit(
                    f"the nav panel opened at {state['rect']} (viewport "
                    f"{state['viewport']}) on {path}, but only "
                    f"{state['reachable']} of {state['items']} links can be hit.\n"
                    "Nothing measured inside it would mean anything. Run "
                    "tools/test_nav.py, which diagnoses this case.")
            time.sleep(0.4)

        data = b.js(AUDIT_JS.replace("SCOPE", json.dumps(scope)))
        snaps[theme] = data.pop("snapshot", {})

        for hit in data["text"]:
            hit.update(page=path, theme=theme, where=label)
            res.contrast.append(hit)

        for hit in data["unknown"]:
            res.unknown.setdefault((path, hit["sig"], hit["at"]), hit)

        for hit in data["gradient"]:
            res.gradient.setdefault((path, hit["sig"]), hit)

        for hit in data["images"]:
            if hit["bg"] and hit["w"] > 8:
                hit.update(page=path, theme=theme)
                res.images.append(hit)

    # Anything painting an identical colour in both themes is not theme-aware.
    # Each property is judged on its own — see the note in the audit script.
    for key, light in snaps["light"].items():
        dark = snaps["dark"].get(key)
        if dark is None:
            continue
        signature = key.split("|")[0]
        for prop in ("ink", "fill"):
            value = light[prop]
            if value is None or dark[prop] != value:
                continue
            # Transparent is the absence of a colour, not a fixed one.
            if value == "rgba(0, 0, 0, 0)":
                continue
            # Ink on the silver fill is invariant by design; so is the plate.
            if prop == "ink" and value == ON_ACCENT:
                continue
            entry = res.invariant.setdefault(
                (signature, prop, value),
                {"sig": signature, "prop": prop, "value": value, "pages": set()})
            entry["pages"].add(path)


def report(res: Results) -> None:
    line = "=" * 76

    print(f"\n{line}\nRENDERED CONTRAST — every text element, both themes\n{line}")
    if not res.contrast:
        print("  No element falls below its WCAG AA threshold.")
    else:
        # One CSS mistake shows up on every page that uses the component, so
        # collapse to the distinct defects and say where each was seen.
        groups = {}
        for hit in res.contrast:
            key = (hit["sig"], hit["fg"], hit["bg"], hit["theme"])
            g = groups.setdefault(key, dict(hit, pages=set(), samples=[]))
            g["pages"].add(hit["page"])
            if len(g["samples"]) < 2 and hit["text"] not in g["samples"]:
                g["samples"].append(hit["text"])
        for g in sorted(groups.values(), key=lambda h: h["got"]):
            pages = sorted(g["pages"])
            shown = ", ".join(pages[:3]) + (
                f" +{len(pages) - 3} more" if len(pages) > 3 else "")
            print(f"  [FAIL] {g['got']:>5.2f}:1  (needs {g['need']})  "
                  f"{g['theme']} · {g['where']}")
            print(f"         {g['sig']}   {g['fg']} on {g['bg']}"
                  f"   {g['size']}px/{g['weight']}")
            print(f"         {' | '.join(chr(34) + s + chr(34) for s in g['samples'])}")
            print(f"         on {shown}")

    print(f"\n{line}\nARTWORK ON ITS BACKGROUND — images cannot be recoloured\n{line}")
    if not res.artwork:
        print("  No image reads in one theme and disappears in the other.")
    else:
        for hit in sorted(res.artwork, key=lambda h: min(h["light"][0], h["dark"][0])):
            print(f"  [FAIL] {hit['reason']}")
            print(f"         {hit['src']}  ({hit['transparent']:.0%} transparent)")
            print(f"         light {hit['light'][0]:>5.2f}:1 on {hit['light'][1]}"
                  f"   dark {hit['dark'][0]:>5.2f}:1 on {hit['dark'][1]}")
            print(f"         {hit['page']}")

    if res.artwork_notes:
        print(f"\n  Noted, not failed — low in BOTH themes, so it is the mark's own")
        print(f"  contrast rather than anything the theme does. WCAG 1.4.11 exempts")
        print(f"  logotypes, and these are other companies' marks:\n")
        for hit in sorted(res.artwork_notes, key=lambda h: h["light"][0]):
            print(f"      {hit['src']:<44} light {hit['light'][0]:.2f}:1"
                  f"  dark {hit['dark'][0]:.2f}:1")

    print(f"\n{line}\nNOT THEME-AWARE — identical colours in light and dark\n{line}")
    real = [e for e in res.invariant.values() if not allowed(e["sig"])]
    if not real:
        print("  Every painted colour changes with the theme, or is allow-listed.")
    else:
        for entry in sorted(real, key=lambda e: -len(e["pages"])):
            pages = sorted(entry["pages"])
            shown = ", ".join(pages[:3]) + (f" +{len(pages) - 3} more" if len(pages) > 3 else "")
            print(f"  {entry['sig']:<34} {entry['prop']:<5} {entry['value']}")
            print(f"      on {shown}")

    if res.unknown or res.gradient:
        print(f"\n{line}\nNOT MEASURED — no single ratio exists\n{line}")
        print("  These need an eye rather than a number. The silver ramp itself is")
        print("  proven against --on-accent by tools/check_contrast.py.\n")
        seen = set()
        for (page, sig, at), hit in sorted(res.unknown.items()):
            if (sig, at) in seen:
                continue
            seen.add((sig, at))
            print(f"  text on a background-image")
            print(f"      {sig}  over {at}   \"{hit['text']}\"")
        seen = set()
        for (page, sig), hit in sorted(res.gradient.items()):
            if sig in seen:
                continue
            seen.add(sig)
            print(f"  gradient-filled text")
            print(f"      {sig}   \"{hit['text']}\"")

    print(f"\n{line}")
    if res.ok():
        print(f"All {res.pages} page loads pass in both themes: "
              f"text contrast and artwork legibility.")
    else:
        print(f"{len(res.contrast)} text and {len(res.artwork)} artwork failures "
              f"across {res.pages} page loads.")
    print(line)


def main() -> None:
    missing = [n for n in ("php", "geckodriver", "firefox") if not shutil.which(n)]
    if missing:
        print(f"Skipping: {', '.join(missing)} not installed.")
        return

    only = sys.argv[1:] or None
    pages = [p for p in PAGES if not only or any(o in p for o in only)]
    if not pages:
        raise SystemExit(f"No page matches {only}")

    web_port, drv_port = free_port(), free_port()
    router = ROOT / "tools" / "dev-router.php"

    php = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{web_port}", "-t", str(ROOT), str(router)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    drv = subprocess.Popen(
        ["geckodriver", "--port", str(drv_port)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)

    if not (wait_for(web_port) and wait_for(drv_port)):
        raise SystemExit("php or geckodriver did not start")

    origin = f"http://127.0.0.1:{web_port}"
    res = Results()
    browser = None

    try:
        browser = Browser(drv_port)

        browser.go(origin + "/")
        broken = browser.js(SELFTEST_JS)
        if broken:
            raise SystemExit("the colour resolver is wrong, so every number "
                             "below would be too:\n  " + "\n  ".join(broken))
        print("colour resolver self-test: ok")

        # The other way this run can report a clean pass while measuring less
        # than it claims. If the reduced-motion preference did not take, the
        # scroll reveal is live and every element it has not reached is at
        # opacity 0 — which paints() reads as "not shown" and skips.
        armed = browser.js(
            "return document.documentElement.classList.contains('js-reveal');")
        if armed:
            raise SystemExit(
                "the scroll reveal is armed, so elements it has not revealed "
                "yet would be skipped as invisible and the pass below would "
                "cover less than it says.\n"
                "Check the ui.prefersReducedMotion pref in Browser.__init__.")
        print("scroll reveal is off, so nothing is skipped as invisible: ok")

        for label, w, h, scope, drawer in PASSES:
            browser.size(w, h)
            print(f"\n--- {label} ({w}px) ---")
            for path in pages:
                print(f"  {path}")
                audit_page(browser, origin, path, label, scope, drawer, res)
                res.pages += 2
    finally:
        if browser:
            browser.quit()
        for proc in (drv, php):
            stop(proc)

    check_artwork(res)
    report(res)
    sys.exit(1 if not res.ok() else 0)


if __name__ == "__main__":
    main()
