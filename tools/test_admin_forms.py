#!/usr/bin/env python3
"""
Drive the admin in a real browser and prove a click never throws the page away.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_admin_forms.py

Needs the PHP CLI, Firefox and geckodriver. Exits 0 with a notice if the
browser pieces are missing, so it does not block a machine that only has PHP.

WHY A BROWSER
The whole subject is what a click does to the page, and none of it is visible
from the server: the request the admin sends is byte for byte the request the
browser would have sent, and the response is the same page. What changed is
that the answer is put back in place instead of replacing the document — so the
things worth asserting are the scroll position, where the focus went, which
element survived, and that the document was never navigated. A PHP test cannot
see any of those.

THE TWO HALVES OF THE SAME SUBJECT, IN ONE FILE
    the buttons   admin-forms.js — add a row, move one, remove one, save
    the links     admin-swap.js — the rail, "Edit", "Cancel", "Discard", Back

They share a harness because they share a mechanism: admin-swap.js does the
putting-back for both. Two files here would mean two sign-ins, two browsers and
two copies of 200 lines that exist to get as far as an editor.

WHAT IS BEING PROTECTED
Every control in these editors is a submit button, and every way between them
is a link. Before this, each was a full navigation, and a navigation lands at
the top: pressing "Move down" on the fiftieth technology logo returned you to
the page title, thousands of pixels away. The effect was bad enough that the
company editor was reported as not containing its own data — the only part
anybody ever saw was the first field group. Following a rail item was worse
still: it tore down and rebuilt the whole shell, so the rail was rendered wide
and then snapped back to the width it had been left at, visibly, every time.

AND THAT IT ALL STILL WORKS WITHOUT SCRIPT
The last group here disables JavaScript and does the same edits, and the same
moves between screens, again. That is the hard rule this project holds to, and
it is the reason this was built as a swap over the ordinary form post and the
ordinary link rather than as an endpoint of its own.
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
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as err:
        # geckodriver puts the fault in the BODY and only the code in the
        # status line, so an unread error reads as "HTTP Error 500" and says
        # nothing at all. "unexpected alert open" — a dialog left standing by
        # the step before — is the one that matters here, and it cost a
        # debugging round to find the first time. Folded into err.msg rather
        # than raised as something else, because alert() below catches
        # HTTPError to mean "there is no dialog".
        err.msg = f"{err.msg} — {err.read().decode(errors='replace')[:300]}"
        raise


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
        rq("POST", self.s + f"/element/{self._one(css)}/click", {})
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

    def rect(self, css):
        """Where an element is and how big, straight from the driver.

        Not through JavaScript: half of what is asserted below is asserted in
        a browser with JavaScript switched off, and that is exactly the half
        where a measurement taken by script would be worthless.
        """
        return rq("GET", self.s + f"/element/{self._one(css)}/rect")["value"]

    def text(self, css):
        return rq("GET", self.s + f"/element/{self._one(css)}/text")["value"]

    def _one(self, css):
        found = rq("POST", self.s + "/elements",
                   {"using": "css selector", "value": css})["value"]
        if not found:
            raise AssertionError(f"nothing matched {css}")
        return found[0]["element-6066-11e4-a52e-4f735466cecf"]

    def type(self, css, text):
        """Send keys the way a keyboard does — appended, and raising the
        `input` events the unsaved-changes guard listens for."""
        rq("POST", self.s + f"/element/{self._one(css)}/value", {"text": text})
        time.sleep(0.4)

    def alert(self):
        """The text of an open modal dialog, or None if there is not one."""
        try:
            return rq("GET", self.s + "/alert/text")["value"]
        except urllib.error.HTTPError:
            return None

    def answer(self, yes):
        rq("POST", self.s + ("/alert/accept" if yes else "/alert/dismiss"), {})
        time.sleep(1.6)

    def cookie(self, name, value):
        rq("POST", self.s + "/cookie", {"cookie": {
            "name": name, "value": value, "path": "/", "domain": "127.0.0.1"}})

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

# And on the rail element itself. A property set on a DOM node dies with the
# node, so this says something the one above cannot: not merely that the
# document survived, but that the rail was never rebuilt inside it. That is the
# difference between a page that flashes its menu and one that does not.
TAG_RAIL = "document.getElementById('admin-rail').__t4t = true; return true;"

# LINKS THAT WOULD STILL BE A FULL PAGE LOAD.
#
# Not a list of the links there are today — a list of the ones admin-swap.js
# would decline, which is the same question asked of a screen that does not
# exist yet. Three answers are fine and the fourth is the swap:
#
#   #anchor            the "On this page" column; the browser's own job
#   another origin     the public site, which is a different host entirely
#   target=_blank      a new tab, and a new tab is a new document by right
#   ?s= on this path   swapped
#
# Anything else is a link somebody added without noticing that it throws the
# shell away. This is the check that makes the rule hold for the next editor
# rather than only for the five that exist.
STRAGGLERS = """
var out = [];
var links = document.querySelectorAll('#admin-body a[href], .rail a[href]');

Array.prototype.forEach.call(links, function (a) {
  var written = a.getAttribute('href') || '';
  if (written.charAt(0) === '#') { return; }
  if (a.target && a.target !== '_self') { return; }

  var url = new URL(a.href, location.href);
  if (url.origin !== location.origin) { return; }
  if (url.pathname === location.pathname &&
      new URLSearchParams(url.search).has('s')) { return; }

  out.push(written);
});

return out;
"""

# What screen this is, and what came through the move with it.
SHELL = """
var rail = document.getElementById('admin-rail');
var current = document.querySelectorAll('.rail__item[aria-current="page"]');
var toggle = document.querySelector('.admin-bar__actions [data-theme-toggle]');
var title = document.querySelector('.admin-bar__title');
return {
  heading:  title ? title.textContent.trim() : '',
  title:    document.title,
  search:   location.search,
  hash:     location.hash,
  current:  current.length === 1
              ? current[0].getAttribute('href')
              : '(' + current.length + ' marked current)',
  rail:     rail ? rail.getAttribute('data-rail') : '(no rail)',
  sameRail: rail ? rail.__t4t === true : false,
  themeLabel: toggle ? toggle.getAttribute('aria-label') : '',
  menuOpen: !!document.querySelector('details[data-account][open]'),
  focus:    document.activeElement ? document.activeElement.id : '',
  status:   !!document.querySelector('.admin-bar [data-form-status]'),
  marked:   window.__stillHere === true
};
"""


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


def navigate(b: Browser, base: str, r: Results) -> None:
    """The links: the rail, the account menu, the outline, Back and Forward."""

    r.section("every link on every screen is one the swap will answer")
    for screen in ("/?s=overview", "/?s=careers", "/?s=careers&action=new",
                   "/?s=contact", "/?s=company", "/?s=account"):
        b.go(base + screen)
        stragglers = b.js(STRAGGLERS)
        r.check(f"{screen}: no link falls through to a full page load",
                stragglers == [],
                f"{stragglers} — each of these tears the document down and "
                f"rebuilds the rail with it. Either give it ?s= on this same "
                f"path, or make it plainly something else: an in-page anchor, "
                f"another origin, or target=\"_blank\"")

    # And the one screen the list above cannot name, because its URL carries
    # the id of a job post. It is also the screen with the most links on it.
    b.go(base + "/?s=careers")
    editing = b.js("var a = document.querySelector('a[href*=\"action=edit\"]');"
                   "return a ? a.getAttribute('href') : '';")
    r.check("there is a job post to open", editing != "",
            "no Edit link on the careers screen, so the check below tested "
            "nothing")
    if editing:
        b.go(base + "/" + editing)
        stragglers = b.js(STRAGGLERS)
        r.check("editing a job post: no link falls through either",
                stragglers == [], f"{stragglers}")

    r.section("every screen can say what it is doing")
    for screen in ("/?s=overview", "/?s=careers", "/?s=contact",
                   "/?s=company", "/?s=account"):
        b.go(base + screen)
        r.check(f"{screen}: the bar has a status line",
                b.js(SHELL)["status"],
                "nothing on this screen can report a slow fetch or a failed "
                "one, so a post that never arrived looks exactly like a post "
                "that did")

    r.section("following a link in the rail")
    b.go(base + "/?s=overview")
    b.js(MARK)
    b.js(TAG_RAIL)
    start = b.js(SHELL)

    b.click('.rail__item[href="?s=contact"]')
    moved = b.js(SHELL)

    r.check("the document was not replaced", moved["marked"] is True,
            "window.__stillHere was lost, so this was a full page load")
    r.check("the rail is the same element it was", moved["sameRail"] is True,
            "the rail was torn down and rebuilt — which is what made it flash "
            "open and shut on the way to the page you asked for")
    r.check("the bar says where you are now", moved["heading"] == "Contact",
            f"the heading reads {moved['heading']!r}")
    r.check("so does the browser tab",
            "Contact" in moved["title"] and moved["title"] != start["title"],
            f"{start['title']!r} -> {moved['title']!r}")
    r.check("and so does the address bar", moved["search"] == "?s=contact",
            f"location.search is {moved['search']!r}, so a reload would show "
            f"the screen before this one")
    r.check("exactly one rail row is marked current, and it is this one",
            moved["current"] == "?s=contact",
            f"aria-current is on {moved['current']!r}")
    r.check("focus lands on the editing column",
            moved["focus"] == "admin-main",
            f"focus is on {moved['focus']!r} — a screen reader would be left "
            f"in the rail, and the next Tab would not be this page's first "
            f"control")

    r.section("what the browser knows and the server does not")
    b.click("[data-rail-toggle]")
    narrow = b.js(SHELL)
    r.check("the toggle narrows the rail", narrow["rail"] == "narrow",
            f"data-rail is {narrow['rail']!r}")
    r.check("and writes the cookie the server reads back",
            "t4t_rail=narrow" in (b.js("return document.cookie;") or ""),
            "without the cookie the width is decided in the browser, after "
            "the page has been painted — which is the flash")

    b.click('.rail__item[href="?s=company"]')
    kept = b.js(SHELL)
    r.check("the rail keeps its width across a move", kept["rail"] == "narrow",
            f"data-rail is {kept['rail']!r} after moving screens")
    r.check("and did not have to be rebuilt to keep it",
            kept["sameRail"] is True)

    # The theme button lives in the bar, which IS replaced. Its label says
    # which mode a press would move to, and nothing the server sends can know
    # that — so if it is not carried across, it starts lying after one move.
    before = b.js(SHELL)["themeLabel"]
    b.js("Tech4Time.theme.toggle(); return true;")
    flipped = b.js(SHELL)["themeLabel"]
    b.click('.rail__item[href="?s=careers"]')
    after = b.js(SHELL)["themeLabel"]
    r.check("the theme button's label survives a move",
            flipped != before and after == flipped,
            f"{before!r} -> pressed -> {flipped!r} -> moved -> {after!r}")
    b.js("Tech4Time.theme.toggle(); return true;")
    b.click("[data-rail-toggle]")

    r.section("Back and Forward")
    b.js("window.history.back(); return true;")
    time.sleep(1.6)
    back = b.js(SHELL)
    r.check("Back returns to the screen before", back["search"] == "?s=company",
            f"location.search is {back['search']!r}")
    r.check("and does it without a full load", back["marked"] is True,
            "popstate was answered by the document reloading itself")
    r.check("the rail row follows Back too", back["current"] == "?s=company",
            f"aria-current is on {back['current']!r}")

    b.js("window.history.forward(); return true;")
    time.sleep(1.6)
    forward = b.js(SHELL)
    r.check("Forward goes back to where Back came from",
            forward["search"] == "?s=careers",
            f"location.search is {forward['search']!r}")

    r.section("links that must be left alone")
    b.go(base + "/?s=company")
    b.js(MARK)
    b.click(".outline__link")
    anchored = b.js(SHELL)
    r.check("an in-page anchor is still an in-page anchor",
            anchored["search"] == "?s=company" and anchored["hash"] != "",
            f"search {anchored['search']!r}, hash {anchored['hash']!r} — an "
            f"anchor resolves to a URL with no ?s= at all, so treating it as "
            f"a link between screens lands on the overview")
    r.check("and it did not reload to get there", anchored["marked"] is True)

    r.check("a link to the public site is not swapped in",
            b.js("var a = document.querySelector('.admin-bar__view');"
                 "return !!(a && a.target === '_blank' && "
                 "new URL(a.href).origin !== location.origin);"),
            "it opens in a new tab and points at the other host, so it must "
            "reach the browser untouched")

    r.section("leaving a screen with something unsaved")
    YEAR = 'input[name="milestones[items][0][year]"]'

    b.go(base + "/?s=careers")
    b.click('.rail__item[href="?s=contact"]')
    r.check("an untouched screen does not ask", b.alert() is None,
            "a question nobody needs is a question people learn to click "
            "through, and then it is not a safeguard any more")

    b.go(base + "/?s=company")
    b.type(YEAR, "9")
    b.click('.rail__item[href="?s=contact"]')
    asked = b.alert()
    r.check("a screen with something typed into it asks first",
            asked is not None and "not been saved" in asked,
            f"the dialog said {asked!r} — following a rail item throws the "
            f"form away, and the move is instant now")

    b.answer(False)
    stayed = b.js(SHELL)
    r.check("saying no stays where you were",
            stayed["heading"] == "Company Profile",
            f"the heading reads {stayed['heading']!r}")
    r.check("with what was typed still there",
            (b.js("return document.querySelector('" + YEAR + "').value;") or "")
            .endswith("9"),
            "the answer was taken and the page reloaded anyway")

    b.click('.rail__item[href="?s=contact"]')
    b.answer(True)
    r.check("saying yes goes", b.js(SHELL)["heading"] == "Contact")

    # A row added is in the form and nowhere else until Save is pressed, so it
    # is unsaved work even though nothing was typed.
    b.go(base + "/?s=company")
    b.click('button[name="do"][value="clients-add:0"]')
    b.click('.rail__item[href="?s=careers"]')
    r.check("a row added but not saved counts too", b.alert() is not None,
            "adding a row rewrites the form and not the file, so leaving "
            "loses it exactly as typing does")
    b.answer(True)

    # A field the model will accept, so that what is proved here is the guard
    # and not the validator. A save that FAILS leaves the form holding
    # something the file does not, so the guard is right to keep asking — and
    # a test that typed "9" onto a year proved that instead, twice, before the
    # message was read.
    b.go(base + "/?s=company")
    b.type('input[name="hero[subtitle]"]', " and one more word")
    b.click('.admin-bar__actions button[name="do"][value="save"]')

    notice = b.js("var n = document.querySelector('.admin__notice');"
                  "return n ? n.textContent.trim() : '';") or ""
    r.check("the save went through", notice.startswith("Saved"),
            f"the page says {notice[:90]!r} — the check below means nothing "
            f"unless this one holds")

    b.click('.rail__item[href="?s=careers"]')
    r.check("and a save stops it asking", b.alert() is None,
            "the file and the form agree now, so there is nothing to lose "
            "and nothing to ask about")

    r.section("the account menu")
    b.go(base + "/?s=company")
    b.click(".account__toggle")
    r.check("it opens", b.js(SHELL)["menuOpen"] is True)

    b.click('.account__menu .account__item[href="?s=account"]')
    left = b.js(SHELL)
    r.check("a link inside it moves screens", left["heading"] == "Account",
            f"the heading reads {left['heading']!r}")
    r.check("and the menu does not stay open behind you",
            left["menuOpen"] is False,
            "the rail is not replaced, so an open menu outlives the press "
            "that used it unless it is closed")


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

    r.section("moving between screens with JavaScript off")

    b.click('.rail__item[href="?s=contact"]')
    r.check("a rail item is still a link",
            b.text(".admin-bar__title") == "Contact",
            f"the heading reads {b.text('.admin-bar__title')!r} — with no "
            f"script this is an ordinary navigation, and it has to be")

    b.click('.rail__item[href="?s=overview"]')
    r.check("and so is the way back",
            b.text(".admin-bar__title") == "Overview")

    # THE FLASH, PROVED BY THE ONE BROWSER THAT CANNOT CAUSE IT.
    #
    # The rail's width used to be a localStorage value read by a deferred
    # script, so the browser painted a wide rail and then snapped it shut. With
    # JavaScript off that same rail could only ever be wide — there was nothing
    # to read the value. It is a cookie now, so the server decides the width
    # before it sends the rail, and this browser gets it right with no script
    # at all. If these two measurements are equal, the decision has moved back
    # into the browser and the flash is back with it.
    wide = b.rect(".rail")["width"]

    b.cookie("t4t_rail", "narrow")
    b.go(base + "/?s=overview")
    narrow = b.rect(".rail")["width"]

    r.check("the server draws the rail narrow, with no script to do it",
            narrow < wide / 2,
            f"{wide:.0f}px by default, {narrow:.0f}px with t4t_rail=narrow — "
            f"equal widths mean the server ignored the cookie and the browser "
            f"is deciding again, one frame after the paint")

    b.cookie("t4t_rail", "wide")


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
        navigate(scripted, base, r)
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
