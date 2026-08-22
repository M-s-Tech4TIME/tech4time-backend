#!/usr/bin/env python3
"""
Exercise the contact page editor against a local PHP server.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_contact_admin.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
admin/sections/contact.php writes content/contact.json, and
pages/contact/index.php renders whatever it finds there. Code that writes files
is worth a test: a bug in the save path does not announce itself, it shows up
as an office that quietly lost its phone number.

The point of most of what follows is not that the editor accepted a change —
it is that the change reached the page a visitor sees, and reached it in the
right shape. So nearly every case saves through the editor and then reads
/pages/contact/ back.

Every test runs against a COPY of the real data file, which is restored
afterwards whether the run passes or fails.

WHAT IT CANNOT COVER
The sign-in itself. This harness creates an admin account in a throwaway
private directory and signs in through the real login page — so what is tested
here is the editor's behaviour once past it. The sign-in is the subject of
tools/test_admin_auth.py.
"""

import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import admin_session  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "content" / "contact.json"

ADMIN = "/admin/?s=contact"
PAGE = "/pages/contact/"

ROUTER = """<?php
/* Test harness only. It fakes nothing: the admin has its own accounts now, and
   the harness signs in through /admin/login.php like a person would. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/admin')) {
    $file = __DIR__ . $path;
    require is_file($file) && str_ends_with($file, '.php')
        ? $file
        : __DIR__ . '/admin/index.php';
    return true;
}
if (rtrim($path, '/') === '/pages/contact') {
    require __DIR__ . '/pages/contact/index.php';
    return true;
}
return false;
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


class Client:
    """One browser: keeps the session cookie, and does not follow redirects so
    that a save can be seen to have redirected rather than re-rendered."""

    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(CookieJar()), NoRedirect()
        )

    def get(self, path):
        with self.opener.open(self.base + path, timeout=20) as r:
            return r.status, r.read().decode("utf-8", "replace")

    def post(self, path, fields):
        body = urllib.parse.urlencode(fields, doseq=True).encode()
        req = urllib.request.Request(self.base + path, data=body, method="POST")
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            with self.opener.open(req, timeout=20) as r:
                return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", "replace")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


def csrf_of(html: str) -> str:
    m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
    if not m:
        raise SystemExit("No CSRF token in the editor — it did not render.")
    return m.group(1)


def form_fields(html: str) -> dict:
    """Every named control in the editor, as the browser would submit it.

    Reading them out of the page rather than writing them by hand is what
    makes this a test of the editor: a field the form stops rendering
    disappears from the submission here too, and whatever depended on it
    fails.
    """
    fields = {}

    for tag in re.findall(r"<input\b[^>]*>", html):
        name = re.search(r'name="([^"]+)"', tag)
        if not name or 'type="submit"' in tag:
            continue
        value = re.search(r'value="([^"]*)"', tag)
        fields[name.group(1)] = unescape(value.group(1) if value else "")

    for tag, body in re.findall(r"<textarea\b([^>]*)>(.*?)</textarea>", html, re.S):
        name = re.search(r'name="([^"]+)"', tag)
        if name:
            fields[name.group(1)] = unescape(body)

    for tag, body in re.findall(r"<select\b([^>]*)>(.*?)</select>", html, re.S):
        name = re.search(r'name="([^"]+)"', tag)
        if not name:
            continue
        chosen = re.search(r'<option value="([^"]*)"[^>]*\bselected', body)
        first = re.search(r'<option value="([^"]*)"', body)
        fields[name.group(1)] = unescape(
            (chosen or first).group(1) if (chosen or first) else ""
        )

    return fields


def unescape(value: str) -> str:
    return (value.replace("&lt;", "<").replace("&gt;", ">")
                 .replace("&quot;", '"').replace("&#039;", "'")
                 .replace("&amp;", "&"))


def stop(proc):
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


# ------------------------------------------------------------------- tests


def run(client, r):
    # ---------------------------------------------------------------- loads
    print("\nthe editor")
    status, html = client.get(ADMIN)
    r.check("it opens", status == 200, f"status {status}")
    r.check("it names the file it edits", "content/contact.json" in html)
    # Overview, Careers, Contact, Account. The count is asserted rather than
    # the names so that adding a section without adding it to the rail — which
    # would leave it unreachable — shows up here.
    r.check("the rail lists every section",
            html.count('class="rail__item"') == 4,
            f'found {html.count(chr(34) + "rail__item")}')
    r.check("and marks the one showing",
            html.count('aria-current="page"') == 1)

    token = csrf_of(html)
    base = form_fields(html)
    r.check("the offices are all in the form",
            all(f'offices[items][{i}][name]' in base for i in range(3)))
    r.check("so are the reach rows",
            all(f'reach[items][{i}][label]' in base for i in range(4)))

    # The save button must be the first submit in the document, or pressing
    # Enter in any text field would fire "Add a row" instead.
    submits = re.findall(r'<button[^>]*type="submit"[^>]*value="([^"]*)"', html)
    r.check("pressing Enter would save, not add a row",
            submits and submits[0] == "save",
            f"first submit is {submits[0] if submits else 'none'!r}")

    # ------------------------------------------------------------- editing
    print("\nediting the page")
    fields = dict(base, csrf=token, do="save")
    fields["hero[title]"] = "Talk To Us"
    fields["offices[items][0][phones]"] = "+880 1111111111\n+880 2222222222"
    fields["offices[items][0][hours]"] = "Sat – Wed: 8:00 AM – 4:00 PM"

    status, headers, body = client.post(ADMIN, fields)
    r.check("saving redirects rather than re-rendering",
            status == 302, f"status {status}: {body[:160]}")

    status, page = client.get(PAGE)
    r.check("the new banner is on the page", "Talk To Us" in page)
    r.check("the h1 is the banner title",
            re.search(r'<h1[^>]*>\s*Talk To Us\s*</h1>', page) is not None)
    r.check("the old banner is gone", "Contact Us</h1>" not in page)
    r.check("the new numbers are on the page", "+880 1111111111" in offices(page))
    r.check("the number a visitor sees is the number they dial",
            'href="tel:+8801111111111">+880 1111111111</a>' in offices(page))
    # Scoped to the card that was edited: the Brussels office is reached on
    # the Dhaka numbers, so one of them is still on the page, correctly.
    first = re.search(r'<li[^>]*class="office">.*?</li>', offices(page), re.S)
    r.check("the number it replaced is gone from that card",
            first is not None and "1320571562" not in first.group(0),
            first.group(0)[:200] if first else "no office card found")
    r.check("the new opening hours show",
            "Sat – Wed: 8:00 AM – 4:00 PM" in offices(page))

    # ------------------------------------------------------------ the head
    print("\nsearch results and structured data")
    fields["meta[title]"] = "Reach Tech4TIME"
    fields["meta[description]"] = "Three offices, one working day."
    fields["meta[share_title]"] = "Say hello"
    client.post(ADMIN, fields)
    _, page = client.get(PAGE)
    r.check("the browser tab title follows",
            "<title>Reach Tech4TIME</title>" in page)
    r.check("so does the search description",
            'name="description" content="Three offices, one working day."' in page)
    r.check("and the title on a shared link",
            'property="og:title" content="Say hello"' in page)
    r.check("the phone number reaches the structured data",
            '"telephone": "+8801111111111"' in contact_schema(page))
    r.check("and the ContactPage graph is still valid JSON",
            valid_contact_schema(page))

    # -------------------------------------------------------- hidden office
    print("\nhiding an office")
    fields["offices[items][2][status]"] = "hidden"
    client.post(ADMIN, fields)
    _, page = client.get(PAGE)
    r.check("a hidden office leaves the page", "Avenue Louise" not in offices(page))
    r.check("the others stay", "Batu Caves" in offices(page))
    r.check("and it leaves the ContactPage structured data",
            '"addressCountry": "BE"' not in contact_schema(page))

    _, html = client.get(ADMIN)
    r.check("but it is still in the editor, marked hidden",
            "Avenue Louise" in html
            and re.search(r"admin-row__status--draft[^>]*>\s*Hidden", html) is not None)

    fields["offices[items][2][status]"] = "shown"
    client.post(ADMIN, fields)
    _, page = client.get(PAGE)
    r.check("showing it again brings it back", "Avenue Louise" in offices(page))

    # ------------------------------------------------------------- reorder
    print("\nreordering and removing")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="office-down:0")
    client.post(ADMIN, fields)
    _, html = client.get(ADMIN)
    r.check("a move is NOT saved until the page is",
            first_office(html) == "Bangladesh",
            f"first office is {first_office(html)!r}")

    status, _, body = client.post(ADMIN, dict(form_fields(html), csrf=token,
                                              do="office-down:0"))
    r.check("moving re-renders rather than redirecting", status == 200)
    r.check("and the move shows in the form", first_office(body) == "Malaysia",
            f"first office is {first_office(body)!r}")

    moved = dict(form_fields(body), csrf=token, do="save")
    client.post(ADMIN, moved)
    _, page = client.get(PAGE)
    r.check("saving the move reorders the cards",
            offices(page).index("Batu Caves") < offices(page).index("Manikdi"))

    _, html = client.get(ADMIN)
    status, _, body = client.post(ADMIN, dict(form_fields(html), csrf=token,
                                              do="reach-remove:0"))
    r.check("removing a row re-renders", status == 200)
    r.check("and the row is gone from the form",
            body.count('name="reach[items][0][label]"') == 1
            and "info@tech4time.bd" not in form_fields(body).get("reach[items][0][values]", ""))

    added = dict(form_fields(body), csrf=token, do="reach-add")
    _, _, body = client.post(ADMIN, added)
    fields = form_fields(body)
    last = max(int(m) for m in re.findall(r"reach\[items\]\[(\d+)\]", body))
    r.check("adding a row appends an empty one",
            fields.get(f"reach[items][{last}][label]") == "")

    fields[f"reach[items][{last}][label]"] = "WhatsApp"
    fields[f"reach[items][{last}][type]"] = "phone"
    fields[f"reach[items][{last}][values]"] = "+880 1999999999"
    fields[f"reach[items][{last}][icon]"] = "mobile-alt"
    client.post(ADMIN, dict(fields, csrf=token, do="save"))
    _, page = client.get(PAGE)
    r.check("the new row is on the page", "WhatsApp" in reach(page))
    r.check("as a dialling link", 'href="tel:+8801999999999"' in reach(page))
    r.check("with the icon it was given",
            re.search(r'reach__icon.*?href="#mobile-alt"', reach(page), re.S) is not None)
    r.check("and the symbol for that icon is in the page's sprite",
            '<symbol id="mobile-alt"' in page)

    print("\nseveral numbers under one heading")
    _, html = client.get(ADMIN)
    fields = dict(form_fields(html), csrf=token, do="save")
    phone_row = next(
        i for i in range(20)
        if fields.get(f"reach[items][{i}][type]") == "phone"
        and "1111111111" in fields.get(f"reach[items][{i}][values]", "")
        or fields.get(f"reach[items][{i}][label]") == "Phone"
    )
    fields[f"reach[items][{phone_row}][values]"] = (
        "+880 3333333333\n+880 4444444444\n+880 5555555555")
    status, _, _ = client.post(ADMIN, fields)
    r.check("three numbers in one row save", status == 302)

    _, page = client.get(PAGE)
    row = re.search(r'<li[^>]*class="reach__item">(?:(?!</li>).)*?'
                    r'3333333333(?:(?!</li>).)*?</li>', reach(page), re.S)
    r.check("all three are on the page",
            row is not None
            and all(n in row.group(0) for n in ("3333333333", "4444444444", "5555555555")),
            "not all three are in one reach row")
    r.check("each one dials its own number",
            row is not None and row.group(0).count('href="tel:+880') == 3)
    r.check("under a single heading",
            row is not None and row.group(0).count("reach__label") == 1)
    r.check("and they are stacked, not run together",
            row is not None and "reach__value--many" in row.group(0))

    # ---------------------------------------------------------- validation
    print("\nwhat it refuses")
    _, html = client.get(ADMIN)
    good = dict(form_fields(html), csrf=token, do="save")

    status, _, body = client.post(ADMIN, dict(good, **{"hero[title]": ""}))
    r.check("an empty banner title is refused", status == 200 and "Not saved" in body)

    bad_email = dict(good)
    bad_email["reach[items][0][type]"] = "email"
    bad_email["reach[items][0][values]"] = "not-an-address"
    status, _, body = client.post(ADMIN, bad_email)
    r.check("a value that is not an email address is refused",
            status == 200 and "is not one" in body)

    bad_url = dict(good)
    bad_url["reach[items][0][type]"] = "url"
    bad_url["reach[items][0][values]"] = "javascript:alert(1)"
    status, _, body = client.post(ADMIN, bad_url)
    r.check("a javascript: link is refused",
            status == 200 and "full web address" in body)

    bad_country = dict(good, **{"offices[items][0][schema][country]": "Bangladesh"})
    status, _, body = client.post(ADMIN, bad_country)
    r.check("a country that is not a two-letter code is refused",
            status == 200 and "two letters" in body)

    r.check("and what was typed is still in the form after a refusal",
            'value="Bangladesh"' in body)

    status, _, _ = client.post(ADMIN, dict(good, csrf="wrong"))
    r.check("a request without the token is refused", status == 400)

    _, page = client.get(PAGE)
    r.check("none of the refused values reached the page",
            "not-an-address" not in page and "javascript:alert" not in page)

    # Hiding an office and changing a number both change what the footer
    # should say, so the editor must already be flagging the drift by now.
    _, html = client.get(ADMIN)
    r.check("changing the details raises the footer warning",
            "site footer is showing older details" in html)

    # ------------------------------------------------------ sanitising HTML
    print("\nwhat it stores from the rich fields")
    for name, sent, expect_absent in [
        ("a script tag", "<p>Hi</p><script>alert(1)</script>", "alert(1)"),
        ("an event handler", '<p onclick="steal()">Hi</p>', "onclick"),
        ("an inline style", '<p style="color:red">Hi</p>', "style="),
        ("a javascript: link", '<p><a href="javascript:x()">Hi</a></p>', "javascript:"),
    ]:
        client.post(ADMIN, dict(good, **{"form[lead]": sent}))
        _, page = client.get(PAGE)
        lead = re.search(r'<div class="contact__lead">(.*?)</div>', page, re.S)
        r.check(f"{name} does not survive into the page",
                lead is not None and expect_absent not in lead.group(1),
                lead.group(1)[:120] if lead else "no lead on the page")

    client.post(ADMIN, dict(good, **{
        "form[lead]": '<p>Ask about <strong>anything</strong>.</p>'
                      '<ul><li>Security</li><li>Cloud</li></ul>'}))
    _, page = client.get(PAGE)
    r.check("but ordinary formatting does",
            "<strong>anything</strong>" in page and "<li>Security</li>" in page)

    # ---------------------------------------------------------- the footer
    print("\nthe footer that this editor cannot reach")
    _, html = client.get(ADMIN)
    r.check("the editor says so once the details have changed",
            "site footer is showing older details" in html)
    r.check("and names the tool that fixes it",
            "sync_site_contact.py" in html)

    # --------------------------------------------------------- empty state
    print("\nwhen everything is emptied")
    _, html = client.get(ADMIN)
    empty = dict(form_fields(html), csrf=token, do="save")
    for key in list(empty):
        if re.match(r"(reach|offices)\[items\]", key):
            del empty[key]
    status, _, _ = client.post(ADMIN, empty)
    r.check("removing every row is allowed", status == 302)

    _, page = client.get(PAGE)
    r.check("the page still renders", "<h1" in page and "</html>" in page)
    r.check("with no office cards", offices(page).strip() == "")
    r.check("and no reach rows", reach(page).strip() == "")
    r.check("and the form is still there to use",
            'action="/contact-handler.php"' in page)

    # ---------------------------------------------------- a missing data file
    print("\nwhen the data file is unreadable")
    DATA.rename(DATA.with_suffix(".json.moved"))
    try:
        status, page = client.get(PAGE)
        r.check("the page still answers", status == 200)
        r.check("and falls back to the details it shipped with",
                "Contact Us" in page)
    finally:
        DATA.with_suffix(".json.moved").rename(DATA)


def region(page: str, css_class: str) -> str:
    """One band of the rendered page.

    Assertions are made against a band rather than the whole document for a
    reason worth stating: the head's base Organization graph and the footer
    both repeat the addresses and numbers as literal markup, and they stay put
    until tools/sync_site_contact.py runs. Searching the whole page would find
    them there and conclude the office card had not changed — or that a hidden
    office was still being shown.
    """
    m = re.search(r'<ul class="' + re.escape(css_class) + r'"[^>]*>(.*?)</ul>',
                  page, re.S)
    return m.group(1) if m else ""


def offices(page: str) -> str:
    return region(page, "offices__grid")


def reach(page: str) -> str:
    return region(page, "reach")


def first_office(html: str) -> str:
    m = re.search(r'name="offices\[items\]\[0\]\[name\]"\s+value="([^"]*)"', html)
    return unescape(m.group(1)) if m else ""


def contact_schema(page: str) -> str:
    """The generated ContactPage block, which is the only structured data on
    this page that follows the editor. The base Organization graph above it is
    literal markup and moves only when sync_site_contact.py runs."""
    for body in re.findall(
        r'<script type="application/ld\+json">\s*(\{.*?\})\s*</script>', page, re.S
    ):
        if '"ContactPage"' in body:
            return body
    return ""


def valid_contact_schema(page: str) -> bool:
    import json
    body = contact_schema(page)
    if not body:
        return False
    try:
        doc = json.loads(body)
    except json.JSONDecodeError:
        return False
    return isinstance(doc.get("mainEntity"), dict)


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found:  sudo apt install php-cli")
    if not DATA.exists():
        raise SystemExit(f"Missing {DATA.relative_to(ROOT)}")

    backup = DATA.read_bytes()
    router = ROOT / "_test_contact_router.php"
    router.write_text(ROUTER)

    port = free_port()

    # The accounts, sessions and counters go somewhere disposable, so this run
    # cannot disturb whatever account is used locally.
    work = Path(tempfile.mkdtemp(prefix="t4t-contact-"))
    private = work / "private"

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT), str(router)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        start_new_session=True,
        env=dict(os.environ, T4T_PRIVATE=str(private)),
    )

    r = Results()
    try:
        base = f"http://127.0.0.1:{port}"
        for _ in range(80):
            try:
                urllib.request.urlopen(base + PAGE, timeout=1)
                break
            except Exception:
                time.sleep(0.15)

        secret = admin_session.make_account(private)
        client = Client(base)
        admin_session.sign_in(client.opener, base, secret)
        run(client, r)
    finally:
        shutil.rmtree(work, ignore_errors=True)
        stop(server)
        router.unlink(missing_ok=True)
        DATA.write_bytes(backup)
        for stray in (DATA.with_suffix(".json.bak"), DATA.with_suffix(".json.moved")):
            stray.unlink(missing_ok=True)
        print(f"\n{DATA.relative_to(ROOT)} restored")

    total = r.passed + len(r.failed)
    if r.failed:
        print(f"\n{len(r.failed)} of {total} checks FAILED:")
        for case in r.failed:
            print(f"  - {case}")
        sys.exit(1)

    print(f"\n{r.passed}/{total} checks passed")


if __name__ == "__main__":
    main()
