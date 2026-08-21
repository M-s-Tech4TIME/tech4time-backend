#!/usr/bin/env python3
"""
Verify the shared header/footer/scripts markup has not drifted between pages.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_shared_markup.py

The project forbids runtime fetch()-based partials, so the header, footer and
script tags are pasted into all thirteen pages. That is the right call for
reliability, but it means the same markup exists in thirteen places and is free
to drift. This script is the safeguard: it asserts every page still matches the
canonical copies in tools/templates/.

The single permitted per-page difference is the aria-current="page" marker on
the active nav link, which is normalised away before comparison.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TEMPLATES = ROOT / "tools" / "templates"

# name -> (template file, regex capturing that block in a page)
BLOCKS = {
    "header": (
        "header.html",
        re.compile(r'<a class="skip-link".*?</header>', re.S),
    ),
    # The small-screen navigation. Delimited by comments rather than by its
    # tags: it contains a <nav> and ends in nested </div>s, so there is no
    # closing tag unique enough to match on safely.
    "dock": (
        "dock.html",
        re.compile(r"<!--dock:start-->.*?<!--dock:end-->", re.S),
    ),
    "footer": (
        "footer.html",
        re.compile(r'<footer class="site-footer">.*?</footer>', re.S),
    ),
    # Only on pages with a title band, which the home page and the 404 do not
    # have. Absence is not drift; a copy that differs is.
    "hero-circuit": (
        "hero-circuit.html",
        re.compile(r"<!--hero-circuit:start-->.*?<!--hero-circuit:end-->", re.S),
    ),
}

OPTIONAL_BLOCKS = {"hero-circuit"}

# Feature modules a page may legitimately omit: forms.js when it carries no
# form, dashboard.js when it has no tabbed panels, tech-sphere.js when it has no
# logo sphere. Their absence is not drift.
OPTIONAL_SCRIPTS = {
    "/assets/js/forms.js",
    "/assets/js/dashboard.js",
    "/assets/js/tech-sphere.js",
    # Two pages have a slideshow, one has the terminal.
    "/assets/js/slider.js",
    "/assets/js/terminal.js",
}

ARIA_CURRENT = re.compile(r'\s*aria-current="page"')
WHITESPACE = re.compile(r"\s+")


def normalise(markup: str) -> str:
    """Collapse whitespace and drop the per-page active-nav marker."""
    return WHITESPACE.sub(" ", ARIA_CURRENT.sub("", markup)).strip()


def pages() -> list[Path]:
    found = (
        list(ROOT.glob("*.html"))
        + list(ROOT.glob("pages/**/*.html"))
        # The careers page is PHP because its content changes without a
        # redeploy. Its header and footer are still literal markup pasted in
        # like everywhere else, so they drift the same way and are checked the
        # same way.
        + list(ROOT.glob("pages/**/*.php"))
    )
    return sorted(found)


def first_difference(a: str, b: str) -> str:
    for i, (x, y) in enumerate(zip(a, b)):
        if x != y:
            start = max(0, i - 60)
            return (
                f"\n      expected: …{b[start:i + 60]}…"
                f"\n      found:    …{a[start:i + 60]}…"
            )
    longer = "page" if len(a) > len(b) else "template"
    extra = (a if len(a) > len(b) else b)[min(len(a), len(b)):][:120]
    return f"\n      {longer} has extra content: …{extra}…"


def main() -> None:
    files = pages()
    if not files:
        print("No pages built yet — nothing to compare.")
        return

    canonical = {}
    for name, (filename, _) in BLOCKS.items():
        path = TEMPLATES / filename
        if not path.exists():
            raise SystemExit(f"Missing template: {path}")
        canonical[name] = normalise(path.read_text())

    problems = []
    print(f"Checking shared markup across {len(files)} page(s)\n")

    for path in files:
        rel = path.relative_to(ROOT)
        html = path.read_text()
        issues = []

        for name, (_, pattern) in BLOCKS.items():
            match = pattern.search(html)
            if not match:
                if name not in OPTIONAL_BLOCKS:
                    issues.append(f"no {name} block found")
                continue
            found = normalise(match.group(0))
            if found != canonical[name]:
                issues.append(f"{name} differs from template" + first_difference(found, canonical[name]))

        # Scripts: check the set and order of the non-optional ones.
        srcs = re.findall(r'<script src="(/assets/js/[^"]+)"[^>]*></script>', html)
        required = [s for s in srcs if s not in OPTIONAL_SCRIPTS]
        expected = [
            "/assets/js/theme-init.js",
            "/assets/js/theme-toggle.js",
            "/assets/js/nav.js",
            "/assets/js/animations.js",
            "/assets/js/main.js",
        ]
        if required != expected:
            issues.append(f"script tags differ:\n      expected: {expected}\n      found:    {required}")

        print(f"  {rel}  — {'OK' if not issues else str(len(issues)) + ' issue(s)'}")
        for issue in issues:
            problems.append(f"{rel}: {issue}")

    if problems:
        print(f"\n{len(problems)} drift issue(s):\n")
        for p in problems:
            print(f"  - {p}")
        print("\nFix the page to match tools/templates/, or update the template "
              "and propagate to every page.")
        sys.exit(1)

    print("\nShared markup is identical across all pages.")


if __name__ == "__main__":
    main()
