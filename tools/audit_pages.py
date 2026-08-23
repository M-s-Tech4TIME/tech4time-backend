#!/usr/bin/env python3
"""
Audit every page for SEO, accessibility and structural correctness.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/audit_pages.py

Checks, per page:
  - <html lang="en"> and a viewport meta
  - a <title> and a meta description, both present and unique across the site
  - a canonical link
  - exactly one <h1>, and no skipped heading levels
  - no id is used twice
  - exactly one <main>, plus a <header> and a <footer>; multiple <nav>s named
  - every form control has a label, and every link and button an accessible name
  - every <img> carries an alt attribute (alt="" is valid for decoration)
  - every <img> carries width and height, or CSS aspect-ratio, to avoid CLS
  - every JSON-LD block parses as valid JSON
  - every external link carries rel="noopener noreferrer"
  - internal links resolve to a file that exists
  - every <use href="#icon"> has a matching inlined <symbol>

Exits non-zero if anything fails, so it can gate the Phase 5 audit.
"""

import json
import re
import shutil
import subprocess
import sys
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SITE_ORIGIN = "https://tech4time.bd"

# Directories that hold deployable pages.
PAGE_GLOBS = ["*.html", "pages/**/*.html", "pages/**/*.php"]


class PageParser(HTMLParser):
    """Collects just the facts the audit needs, in one pass."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.lang = None
        self.title = None
        self.description = None
        self.canonical = None
        self.viewport = None
        self.headings = []          # (level, text)
        self.images = []            # dict of attrs
        self.links = []             # dict of attrs
        self.jsonld = []            # raw script bodies
        self.symbol_ids = set()
        # Every id in the document, not only sprite symbols. The dock's circuit
        # graphic points <use> at paths in its own <defs>; those resolve, and
        # reporting them as missing icons would be wrong.
        self.element_ids = set()
        self.use_refs = set()
        # A list, not a set: two elements sharing an id is the thing being
        # looked for, and a set is exactly the shape that hides it.
        self.all_ids = []
        self.landmarks = []
        self.controls = []
        self.labelled_ids = set()
        self.named = []          # (tag, attrs, text, wraps_an_image)
        self._naming = []
        self._in_title = False
        self._in_jsonld = False
        self._in_heading = None
        self._buffer = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)

        if a.get("id"):
            self.element_ids.add(a["id"])
            # <symbol> ids come from the shared sprite, which is inlined into
            # every page: they are not the page's own markup and repeat by
            # design, so they are not candidates for a duplicate.
            if tag != "symbol":
                self.all_ids.append(a["id"])

        if tag in ("main", "header", "footer", "nav", "aside"):
            self.landmarks.append((tag, a))
        if tag in ("input", "select", "textarea"):
            self.controls.append((tag, a))
        if tag == "label" and a.get("for"):
            self.labelled_ids.add(a["for"])
        if tag in ("a", "button"):
            self._naming.append([tag, a, [], False])
        if tag in ("img", "svg") and self._naming:
            self._naming[-1][3] = True

        if tag == "html":
            self.lang = a.get("lang")
        elif tag == "title":
            self._in_title = True
            self._buffer = []
        elif tag == "meta":
            name = (a.get("name") or "").lower()
            if name == "description":
                self.description = a.get("content")
            elif name == "viewport":
                self.viewport = a.get("content")
        elif tag == "link" and "canonical" in (a.get("rel") or ""):
            self.canonical = a.get("href")
        elif tag == "script" and a.get("type") == "application/ld+json":
            self._in_jsonld = True
            self._buffer = []
        elif tag in ("h1", "h2", "h3", "h4", "h5", "h6"):
            self._in_heading = int(tag[1])
            self._buffer = []
        elif tag == "img":
            self.images.append(a)
        elif tag == "a":
            self.links.append(a)
        elif tag == "symbol" and a.get("id"):
            self.symbol_ids.add(a["id"])
            self.element_ids.add(a["id"])
        elif tag == "use":
            href = a.get("href") or a.get("xlink:href") or ""
            if href.startswith("#"):
                self.use_refs.add(href[1:])

    def handle_endtag(self, tag):
        if tag in ("a", "button") and self._naming:
            for i in range(len(self._naming) - 1, -1, -1):
                if self._naming[i][0] == tag:
                    t, attrs, chunks, has_image = self._naming.pop(i)
                    self.named.append((t, attrs, "".join(chunks).strip(), has_image))
                    break

        text = "".join(self._buffer).strip()
        if tag == "title" and self._in_title:
            self.title = text
            self._in_title = False
        elif tag == "script" and self._in_jsonld:
            self.jsonld.append(text)
            self._in_jsonld = False
        elif tag in ("h1", "h2", "h3", "h4", "h5", "h6") and self._in_heading:
            self.headings.append((self._in_heading, text))
            self._in_heading = None
        self._buffer = []

    def handle_data(self, data):
        if self._in_title or self._in_jsonld or self._in_heading:
            self._buffer.append(data)
        for frame in self._naming:
            frame[2].append(data)


def pages() -> list[Path]:
    found = []
    for pattern in PAGE_GLOBS:
        found.extend(ROOT.glob(pattern))
    return sorted(set(found))


def resolve_internal(href: str) -> Path | None:
    """Map a root-relative URL to the file that would serve it."""
    path = href.split("#")[0].split("?")[0]
    if not path.startswith("/"):
        return None
    target = ROOT / path.lstrip("/")
    if path.endswith("/") or target.is_dir():
        # DirectoryIndex is "index.html index.php", so either serves the URL.
        # The careers page is the .php one because its content changes without
        # a redeploy.
        for name in ("index.html", "index.php"):
            if (target / name).is_file():
                return target / name
        return target / "index.html"
    return target


def render_php(path: Path) -> tuple[str, str | None]:
    """Run a .php page and return what it sends to a browser.

    Auditing the source of a PHP page would check markup no visitor ever
    receives — the conditional branches, the loops, the tags themselves. What
    matters is the output, so the audit runs the page and reads that instead.
    """
    php = shutil.which("php")
    if not php:
        return "", "php not installed, so this page was not audited (sudo apt install php-cli)"

    result = subprocess.run(
        [php, "-f", str(path)],
        capture_output=True, text=True, cwd=str(path.parent),
    )
    if result.returncode != 0:
        return "", f"php failed to render this page: {result.stderr.strip()[:200]}"

    return result.stdout, None


def audit_page(path: Path, seen_titles: dict, seen_descriptions: dict) -> list[str]:
    rel = path.relative_to(ROOT)
    problems = []

    if path.suffix == ".php":
        html, failure = render_php(path)
        if failure:
            return [f"{rel}: {failure}"]
    else:
        html = path.read_text()

    parser = PageParser()
    parser.feed(html)

    def fail(msg):
        problems.append(f"{rel}: {msg}")

    # --- head essentials -------------------------------------------------
    if parser.lang != "en":
        fail(f'<html lang> is {parser.lang!r}, expected "en"')
    if not parser.viewport:
        fail("missing viewport meta")
    if not parser.canonical:
        fail("missing canonical link")
    elif not parser.canonical.startswith(SITE_ORIGIN):
        fail(f"canonical is not absolute on {SITE_ORIGIN}: {parser.canonical}")

    if not parser.title:
        fail("missing <title>")
    else:
        if len(parser.title) > 65:
            fail(f"title is {len(parser.title)} chars (aim for <=65): {parser.title!r}")
        if parser.title in seen_titles:
            fail(f"duplicate title, also on {seen_titles[parser.title]}")
        else:
            seen_titles[parser.title] = rel

    if not parser.description:
        fail("missing meta description")
    else:
        n = len(parser.description)
        if not 50 <= n <= 165:
            fail(f"meta description is {n} chars (aim for 50-165)")
        if parser.description in seen_descriptions:
            fail(f"duplicate description, also on {seen_descriptions[parser.description]}")
        else:
            seen_descriptions[parser.description] = rel

    # --- headings --------------------------------------------------------
    h1s = [t for lvl, t in parser.headings if lvl == 1]
    if len(h1s) != 1:
        fail(f"expected exactly one <h1>, found {len(h1s)}")

    previous = 0
    for level, text in parser.headings:
        if previous and level > previous + 1:
            fail(f"heading jumps h{previous} -> h{level} at {text[:40]!r}")
        previous = level

    # --- identity --------------------------------------------------------
    # A repeated id makes getElementById, every label's `for`, and every
    # in-page anchor pick the first one and ignore the rest. Nothing reports
    # it; the second control simply stops being reachable by its own label.
    seen = set()
    for value in parser.all_ids:
        if value in seen:
            fail(f"duplicate id={value!r} — only the first is addressable")
        seen.add(value)

    # --- landmarks -------------------------------------------------------
    # What a screen reader offers as "jump to". Without <main> there is no
    # target for the skip link, and the page has no way past the header.
    kinds = [tag for tag, _ in parser.landmarks]
    if kinds.count("main") != 1:
        fail(f"expected exactly one <main>, found {kinds.count('main')}")
    for required in ("header", "footer"):
        if required not in kinds:
            fail(f"no <{required}> landmark")

    navs = [a for tag, a in parser.landmarks if tag == "nav"]
    if len(navs) > 1:
        unnamed = [a for a in navs
                   if not (a.get("aria-label") or a.get("aria-labelledby"))]
        if unnamed:
            fail(f"{len(navs)} <nav> landmarks and {len(unnamed)} unnamed — "
                 f"they are indistinguishable in a landmark list")

    # --- controls and names ----------------------------------------------
    for tag, a in parser.controls:
        if a.get("type") in ("hidden", "submit", "button", "reset", "image"):
            continue
        if a.get("id") in parser.labelled_ids:
            continue
        if a.get("aria-label") or a.get("aria-labelledby") or a.get("title"):
            continue
        fail(f"<{tag} name={a.get('name')!r}> has no label — nothing says what "
             f"it is for once the placeholder is typed over")

    for tag, a, text, has_image in parser.named:
        if tag == "a" and not a.get("href"):
            continue          # an anchor target, not a link
        if a.get("aria-hidden") == "true":
            continue
        if text or a.get("aria-label") or a.get("aria-labelledby") or a.get("title"):
            continue
        what = a.get("href") if tag == "a" else (a.get("class") or "(no class)")
        fail(f"<{tag}> has no accessible name: {what}"
             + ("  — it is an icon, so it needs aria-label" if has_image else ""))

    # --- images ----------------------------------------------------------
    for img in parser.images:
        src = img.get("src", "(no src)")
        if "alt" not in img:
            fail(f"<img> without alt: {src}")
        if not (img.get("width") and img.get("height")):
            fail(f"<img> without width/height (layout shift risk): {src}")

    # --- links -----------------------------------------------------------
    for link in parser.links:
        href = link.get("href")
        if not href:
            continue

        if href.startswith(("http://", "https://")):
            if not href.startswith(SITE_ORIGIN):
                rel_attr = link.get("rel", "")
                if "noopener" not in rel_attr or "noreferrer" not in rel_attr:
                    fail(f'external link missing rel="noopener noreferrer": {href}')
        elif href.startswith("/"):
            target = resolve_internal(href)
            # Pages not built yet are reported separately, not as failures.
            if target and not target.exists():
                problems.append(f"{rel}: PENDING internal link (page not built yet): {href}")

    # --- structured data -------------------------------------------------
    for block in parser.jsonld:
        try:
            json.loads(block)
        except json.JSONDecodeError as e:
            fail(f"invalid JSON-LD: {e}")

    # --- icons -----------------------------------------------------------
    # element_ids covers both the inlined <symbol>s and anything the page
    # defines itself, such as the dock circuit's <defs> paths.
    missing_icons = parser.use_refs - parser.element_ids
    if missing_icons:
        fail(
            "icon reference(s) with no inlined <symbol>: "
            + ", ".join(sorted(missing_icons))
            + "  — run tools/inject_icons.py"
        )

    return problems


def check_admin_is_hidden() -> list[str]:
    """
    The job post editor must be findable only by someone who already knows.

    Four things have to hold together, and each is easy to undo by accident:
    nothing links to it, the sitemap omits it, robots.txt stays silent about
    it, and the page marks itself noindex.

    The robots.txt one is the counter-intuitive one, so it is asserted rather
    than left to memory. Disallowing /admin would publish the path — that file
    is world-readable and is the first thing a scanner fetches — and it would
    also stop a crawler reading the noindex, so a URL found some other way
    could still appear as a bare result. Silence is stronger.
    """
    problems = []

    for path in pages():
        markup = path.read_text()
        for href in re.findall(r'href="([^"]*)"', markup):
            if re.match(r"^(/|https?://[^/]*tech4time\.bd)?/?admin(/|$)", href):
                problems.append(f"{path.relative_to(ROOT)}: links to the admin editor ({href})")

    sitemap = ROOT / "sitemap.xml"
    if sitemap.is_file() and "admin" in sitemap.read_text():
        problems.append("sitemap.xml: lists the admin editor")

    robots = ROOT / "robots.txt"
    if robots.is_file():
        for line in robots.read_text().splitlines():
            bare = line.strip()
            if bare.startswith("#") or ":" not in bare:
                continue
            if "admin" in bare.lower():
                problems.append(
                    "robots.txt: names /admin in a directive — that publishes the "
                    "path and stops the noindex being read. Leave it unlisted."
                )

    # The admin's <head> is written once, by admin_head() in lib/admin.php, and
    # the refusal page that admin_require_auth() sends writes its own. Both
    # have to carry the noindex, so both are checked — a page nobody linked to
    # is still a page a crawler can be told about.
    for path in (ROOT / "lib" / "admin.php", ROOT / "admin" / "index.php"):
        if not path.is_file():
            continue
        text = path.read_text()
        if 'name="robots"' in text:
            break
    else:
        problems.append(
            "the admin does not emit <meta name=\"robots\"> noindex "
            "(looked in lib/admin.php and admin/index.php)"
        )

    shell = ROOT / "lib" / "admin.php"
    if shell.is_file() and shell.read_text().count('name="robots"') < 2:
        problems.append(
            "lib/admin.php: only one <meta name=\"robots\"> — the editor shell "
            "and the not-protected refusal page each need their own"
        )

    htaccess = ROOT / ".htaccess"
    if htaccess.is_file() and "X-Robots-Tag" not in htaccess.read_text():
        problems.append(".htaccess: no X-Robots-Tag rule covering /admin")

    return problems


def main() -> None:
    files = pages()
    if not files:
        print("No pages built yet.")
        return

    seen_titles: dict = {}
    seen_descriptions: dict = {}
    failures, pending = [], []

    print(f"Auditing {len(files)} page(s)\n")

    for path in files:
        problems = audit_page(path, seen_titles, seen_descriptions)
        real = [p for p in problems if "PENDING" not in p]
        soft = [p for p in problems if "PENDING" in p]

        status = "OK" if not real else f"{len(real)} issue(s)"
        print(f"  {path.relative_to(ROOT)}  — {status}")

        failures.extend(real)
        pending.extend(soft)

    if pending:
        print(f"\n{len(pending)} link(s) to pages not built yet (expected during Phase 2):")
        for p in sorted(set(pending))[:20]:
            print(f"  {p}")

    admin_problems = check_admin_is_hidden()
    if admin_problems:
        failures.extend(admin_problems)
    else:
        print("\n  admin editor is unlinked, unlisted and noindexed  — OK")

    if failures:
        print(f"\n{len(failures)} issue(s):\n")
        for f in failures:
            print(f"  - {f}")
        sys.exit(1)

    print("\nAll pages pass.")


if __name__ == "__main__":
    main()
