#!/usr/bin/env python3
"""
Prove the documentation still describes the code.

Development tool. NOT deployed to the web server (see docs/40-reference/tools.md).

    python3 tools/check_docs.py
    python3 tools/check_docs.py -v     # list what passed as well

WHY THIS EXISTS
Documentation rots silently. A tool is deleted, a constant is retuned, a file
moves -- and the prose describing it goes on reading perfectly while being
wrong. That is worse than no documentation, because it is believed.

The project already refuses to let two things drift apart when it can check
them mechanically: check_shared_markup.py for the header copied into sixteen
pages, check_content_model.py for the model, the form and the renderer. This is
the same idea pointed at docs/.

WHAT IT CANNOT DO
Read prose. It cannot tell whether an explanation is still true, only whether
the things the explanation names still exist and still hold the values quoted.
That half is on the author. This catches the half that rots without anybody
touching the docs at all.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOCS = ROOT / "docs"

# --------------------------------------------------------------- what to check

TOOLS_DOC = DOCS / "40-reference" / "tools.md"
LIBS_DOC = DOCS / "10-development" / "server-side" / "libraries.md"
MAP_DOC = DOCS / "00-orientation" / "repository-map.md"
AUTH_DOC = DOCS / "10-development" / "server-side" / "authentication.md"
INDEX_DOC = DOCS / "README.md"

# Tools that are libraries for other tools rather than scripts anyone runs.
# They still have to be documented; this only notes why they have no "run me".
TOOL_SKIP: set[str] = set()

# Paths a doc may legitimately cite that do not exist in the repository:
# illustrations, files generated at runtime, and one file whose ABSENCE is the
# point. Anything not listed here must exist on disk.
PATH_EXEMPT = {
    # admin/.htaccess must never exist -- cPanel writes its own, and uploading
    # over it removes the password. It is cited only to say "never create this".
    "admin/.htaccess",
    # written on save, gitignored
    "content/careers.json.bak",
    "content/contact.json.bak",
    # a worked example in adding-a-page.md
    "pages/services/managed-detection/index.html",
}

# Prose that states a constant's value in words. If the constant changes, the
# words must change with it -- and so must this table, which is the point: the
# mapping is a deliberate record of "these words mean this number".
#
#   constant -> { value: [phrases that must appear] }
CLAIMS = {
    "RESET_TTL": {600: ["ten minutes"]},
    "AUTH_IDLE": {3600: ["one hour"]},
    "AUTH_ABSOLUTE": {43200: ["twelve hours"]},
    "AUTH_RECOVERY": {10: ["ten recovery codes"]},
    "THROTTLE_MAX_BLOCK": {3600: ["one hour"]},
}
# No fixed list of files. The prose that must be right is the prose in
# whichever document names the constant -- which is stronger than naming one
# file, self-maintaining when a constant is quoted somewhere new, and the only
# version of this that works in both repositories, since the sign-in's
# constants exist in only one of them.

# ------------------------------------------------------------------ machinery

problems: list[str] = []
passed: list[str] = []


def fail(check: str, detail: str) -> None:
    problems.append(f"{check}: {detail}")


def ok(check: str, detail: str) -> None:
    passed.append(f"{check}: {detail}")


def docs() -> list[Path]:
    return sorted(DOCS.rglob("*.md"))


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def all_docs_text() -> str:
    return "\n".join(text(p) for p in docs())


# ------------------------------------------------------------------- coverage


def check_tools() -> None:
    """Every script in tools/ is documented, and nothing documented is gone."""
    if not TOOLS_DOC.is_file():
        fail("tools", f"{TOOLS_DOC.relative_to(ROOT)} is missing")
        return

    body = text(TOOLS_DOC)
    on_disk = {
        p.name
        for p in ROOT.joinpath("tools").iterdir()
        if p.is_file() and p.suffix in {".py", ".php"} and p.name != "check_docs.py"
    }

    missing = sorted(n for n in on_disk if n not in body and n not in TOOL_SKIP)
    if missing:
        fail("tools", "undocumented in 40-reference/tools.md: " + ", ".join(missing))

    # The other direction: a tool named in the doc that no longer exists.
    named = set(re.findall(r"`?([a-z0-9_-]+\.(?:py|php))`?", body))
    # Names that belong to files elsewhere in the repository -- tools.md
    # mentions contact-handler.php, for instance -- are not ghosts.
    elsewhere = {p.name for p in ROOT.rglob("*.p[yh]*") if "/tools/" not in p.as_posix()}

    # Nor is a tool that belongs to the OTHER repository, PROVIDED some
    # document says so in full: `tech4time-website-backend/tools/admin-cli.php`. The
    # full path has to appear at least once, which is what stops "it's in the
    # other one" from becoming a way to keep a dead name in the prose forever.
    attributed = set(re.findall(
        r"tech4time-website-(?:frontend|backend)/tools/([a-z0-9_-]+\.(?:py|php))",
        all_docs_text(),
    ))

    ghosts = sorted(
        n for n in named
        if n not in on_disk and n != "check_docs.py"
        and n not in elsewhere and n not in attributed
    )
    if ghosts:
        fail("tools", "named in the doc but not in tools/: " + ", ".join(ghosts))

    if not missing and not ghosts:
        ok("tools", f"{len(on_disk)} scripts documented")


def check_libraries() -> None:
    """Every lib/*.php is documented."""
    if not LIBS_DOC.is_file():
        fail("libraries", f"{LIBS_DOC.relative_to(ROOT)} is missing")
        return

    body = text(LIBS_DOC)
    on_disk = {p.name for p in ROOT.joinpath("lib").glob("*.php")}

    missing = sorted(n for n in on_disk if n not in body)
    if missing:
        fail("libraries", "undocumented in server-side/libraries.md: " + ", ".join(missing))
    else:
        ok("libraries", f"{len(on_disk)} libraries documented")


def check_admin_sections() -> None:
    """Every ADMIN_SECTIONS entry, and every section file, is documented.

    Only where the admin is. Since the split the frontend has no lib/admin.php
    and no sections to document -- that repository owns the pages instead, and
    check_pages() below is its half. Skipped out loud rather than quietly: a
    check that prints nothing when it does nothing is a check that will one day
    do nothing without anyone noticing.
    """
    if not (ROOT / "lib" / "admin.php").is_file():
        if not ROOT.joinpath("pages").is_dir():
            fail("sections", "neither an admin nor any pages are here -- "
                             "this is not one of the two repositories")
        else:
            ok("sections", "no admin here; tech4time-website-backend documents them")
        return

    admin_php = text(ROOT / "lib" / "admin.php")
    block = re.search(r"const ADMIN_SECTIONS = \[(.*?)\n\];", admin_php, re.S)
    if not block:
        fail("sections", "could not find ADMIN_SECTIONS in lib/admin.php")
        return

    keys = re.findall(r"^\s{4}'(\w+)' =>", block.group(1), re.M)
    body = all_docs_text()

    missing = [k for k in keys if f"?s={k}" not in body and f"`{k}`" not in body]
    if missing:
        fail("sections", "ADMIN_SECTIONS entries not documented: " + ", ".join(missing))

    section_dir = next(
        (d for d in (ROOT / "sections", ROOT / "admin" / "sections") if d.is_dir()),
        None,
    )
    files = {p.name for p in section_dir.glob("*.php")} if section_dir else set()
    map_body = text(MAP_DOC) if MAP_DOC.is_file() else ""
    unmapped = sorted(n for n in files if n not in map_body)
    if unmapped:
        fail("sections", "section files missing from repository-map.md: " + ", ".join(unmapped))

    if not missing and not unmapped:
        ok("sections", f"{len(keys)} sections documented")


def check_pages() -> None:
    """Every page under pages/ appears in the repository map.

    The frontend's half of the pairing above. The backend has no pages/.
    """
    if not MAP_DOC.is_file():
        fail("pages", f"{MAP_DOC.relative_to(ROOT)} is missing")
        return

    if not ROOT.joinpath("pages").is_dir():
        ok("pages", "no pages here; tech4time-website-frontend documents them")
        return

    body = text(MAP_DOC)
    missing = []
    for page in sorted(ROOT.joinpath("pages").rglob("index.*")):
        rel = page.relative_to(ROOT).as_posix()
        if rel not in body:
            missing.append(rel)

    if missing:
        fail("pages", "missing from repository-map.md: " + ", ".join(missing))
    else:
        ok("pages", "every page is in the repository map")


# ---------------------------------------------------------------------- links


def check_internal_links() -> None:
    """Every relative link between docs resolves."""
    broken = []
    for doc in docs():
        for target in re.findall(r"\]\(([^)#][^)]*)\)", text(doc)):
            if target.startswith(("http://", "https://", "mailto:")):
                continue
            clean = target.split("#", 1)[0]
            if not clean:
                continue
            if not (doc.parent / clean).resolve().exists():
                broken.append(f"{doc.relative_to(ROOT)} -> {target}")

    if broken:
        fail("links", "broken:\n    " + "\n    ".join(broken))
    else:
        ok("links", "every internal link resolves")


def check_cited_paths() -> None:
    """Every repository path a doc names in backticks exists on disk.

    Deliberately narrow: only backticked paths that begin with a real top-level
    directory and carry a file extension. A placeholder like `lib/<name>.php`
    is excluded by the character class, and genuine exceptions are listed in
    PATH_EXEMPT rather than being guessed at.

    A file in the OTHER repository is written with the repository name in
    front -- `tech4time-website-backend/lib/auth.php` -- and is not a claim about this
    one. That is the whole convention: after the split a bare `lib/auth.php`
    means "here", always, so the two can never be confused in prose.
    """
    pattern = re.compile(
        r"`((?:assets|lib|admin|pages|tools|content|docs|api|public|sections)"
        r"/[A-Za-z0-9_./-]+\.[a-z0-9]+)`"
    )
    missing = []
    for doc in docs():
        for cited in set(pattern.findall(text(doc))):
            if cited in PATH_EXEMPT:
                continue
            if not (ROOT / cited).exists():
                missing.append(f"{doc.relative_to(ROOT)} cites {cited}")

    if missing:
        fail("paths", "cited but not on disk:\n    " + "\n    ".join(sorted(missing)))
    else:
        ok("paths", "every cited repository path exists")


def check_indexed() -> None:
    """Every doc is reachable -- linked from at least one other doc."""
    linked: set[Path] = set()
    for doc in docs():
        for target in re.findall(r"\]\(([^)#][^)]*)\)", text(doc)):
            if target.startswith(("http://", "https://", "mailto:")):
                continue
            clean = target.split("#", 1)[0]
            if not clean.endswith(".md"):
                continue
            resolved = (doc.parent / clean).resolve()
            if resolved.exists():
                linked.add(resolved)

    orphans = [
        d.relative_to(ROOT).as_posix()
        for d in docs()
        if d.resolve() not in linked and d != INDEX_DOC
    ]

    if orphans:
        fail("index", "no other doc links to these:\n    " + "\n    ".join(orphans))
    else:
        ok("index", f"all {len(docs())} docs are reachable")


# ------------------------------------------------------------------ constants


def php_constants() -> dict[str, int]:
    """Every integer `const NAME = 123;` across lib/."""
    found: dict[str, int] = {}
    for php in sorted(ROOT.joinpath("lib").glob("*.php")):
        for name, value in re.findall(r"^const (\w+)\s*=\s*(\d+);", text(php), re.M):
            found[name] = int(value)
    return found


def php_constant_names() -> set[str]:
    """Every `const NAME =` in lib/, whatever its type -- arrays included."""
    names: set[str] = set()
    for php in sorted(ROOT.joinpath("lib").glob("*.php")):
        names.update(re.findall(r"^const (\w+)\s*=", text(php), re.M))
    return names


def check_constant_table() -> None:
    """The values in authentication.md's constants table match the code.

    The sign-in is the backend's. Where there is no authentication.md there is
    no lib/auth.php either, and there is nothing here for the table to be about
    -- but only where BOTH are absent. One without the other is a doc
    describing code that is gone, or code nothing describes, and both of those
    are exactly what this file exists to catch.
    """
    if not AUTH_DOC.is_file():
        if (ROOT / "lib" / "auth.php").is_file():
            fail("constants", f"lib/auth.php is here but "
                              f"{AUTH_DOC.relative_to(ROOT)} is not")
        else:
            ok("constants", "no sign-in here; tech4time-website-backend documents it")
        return

    code = php_constants()
    defined = php_constant_names()
    rows = re.findall(
        r"^\| `(\w+)`[^|]*\| `(lib/\w+\.php)` \| ([^|]+?) \|", text(AUTH_DOC), re.M
    )
    if not rows:
        fail("constants", "no constants table found in authentication.md")
        return

    wrong = []
    for name, _source, stated in rows:
        if name not in defined:
            wrong.append(f"{name} is documented but not defined in lib/")
            continue
        stated = stated.strip()
        if name not in code:
            continue     # defined, but not an integer -- nothing to compare
        if re.fullmatch(r"\d+", stated) and int(stated) != code[name]:
            wrong.append(f"{name}: doc says {stated}, code says {code[name]}")

    if wrong:
        fail("constants", "table disagrees with the code:\n    " + "\n    ".join(wrong))
    else:
        ok("constants", f"{len(rows)} documented constants match the code")


def check_claims() -> None:
    """Prose that states a constant's value in words still states it correctly.

    Checked against every document that NAMES the constant. A constant this
    repository does not define and no document here mentions belongs to the
    other half and is skipped; one that is described here but defined nowhere
    is prose about code that is gone, and fails.
    """
    code = php_constants()
    naming = {name: [d for d in docs() if name in text(d)] for name in CLAIMS}

    wrong = []
    for name, mapping in CLAIMS.items():
        where = naming[name]

        if name not in code:
            if where:
                wrong.append(
                    f"{name} is described in "
                    + ", ".join(d.relative_to(ROOT).as_posix() for d in where)
                    + " but is not defined in lib/ here -- if it belongs to the "
                      "other half, name it as tech4time-website-backend/lib/…"
                )
            continue

        value = code[name]
        if value not in mapping:
            wrong.append(
                f"{name} is now {value}; the prose still describes another value. "
                f"Update the wording, then update CLAIMS in this file."
            )
            continue
        if not where:
            wrong.append(
                f"{name} is {value} and no document states it in words -- the "
                f"whole point of CLAIMS is that somewhere says what the number means"
            )
            continue

        body = "\n".join(text(d) for d in where).lower()
        for phrase in mapping[value]:
            if phrase.lower() not in body:
                wrong.append(
                    f'{name} is {value} but "{phrase}" does not appear in '
                    + ", ".join(d.relative_to(ROOT).as_posix() for d in where)
                )

    if wrong:
        fail("claims", "prose disagrees with the code:\n    " + "\n    ".join(wrong))
    else:
        ok("claims", f"{len(CLAIMS)} values stated in words still match")


# ------------------------------------------------------------------- headings


def check_applies_to() -> None:
    """Every doc declares who it applies to, ready for the repository split."""
    missing = [
        d.relative_to(ROOT).as_posix()
        for d in docs()
        if not re.search(r"\*\*Applies to:\*\* *(frontend|backend|both)", text(d))
        and d != INDEX_DOC
    ]

    if missing:
        fail("applies-to", "no `**Applies to:**` line:\n    " + "\n    ".join(missing))
    else:
        ok("applies-to", "every doc declares its scope")


# ----------------------------------------------------------------------- main


def main() -> int:
    verbose = "-v" in sys.argv or "--verbose" in sys.argv

    if not DOCS.is_dir():
        print("docs/ does not exist")
        return 1

    for check in (
        check_tools,
        check_libraries,
        check_admin_sections,
        check_pages,
        check_internal_links,
        check_cited_paths,
        check_indexed,
        check_constant_table,
        check_claims,
        check_applies_to,
    ):
        check()

    if verbose:
        for line in passed:
            print(f"  ok    {line}")

    if problems:
        print(f"\ncheck_docs: {len(problems)} problem(s)\n")
        for line in problems:
            print(f"  FAIL  {line}")
        print(
            "\nThe documentation and the code disagree. Update the doc that owns the\n"
            "thing you changed -- docs/README.md has the ownership table.\n"
        )
        return 1

    print(f"check_docs: {len(passed)} checks passed, {len(docs())} documents")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
