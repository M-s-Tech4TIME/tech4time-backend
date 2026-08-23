#!/usr/bin/env python3
"""
Prove the editor, the data and the page still describe the same thing.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_content_model.py

WHY THIS EXISTS
An editable page is three things that have to agree:

    the model      lib/contact.php   — what a field is called and what it holds
    the form       admin/sections/   — where somebody types it
    the renderer   pages/…/index.php — where it comes out

Nothing forces them to. Add a band to the contact page and forget the editor,
and the band is unmanageable; drop a band from the page and leave the field in
the editor, and somebody types into a box that changes nothing they can see.
Neither failure raises an error. Both are found here.

This is a structural check, not a taste one: it asserts that every field in the
model is written by the form and read by the page, and that neither of the
other two reaches for a field the model does not define. It cannot tell you
whether the band looks right — that is what the screenshots are for.

It also asserts that it is being asked about every editor there is. Comparing
source text only works while the fields appear in the source as themselves; a
form or a page that loops over its fields hides them from a regex, and careers
does exactly that on both sides. Those are proved by round trip instead, and
named in COVERED_ELSEWHERE — so an editor checked by neither route fails here
rather than being quietly absent, which is what it was until 2026-08-23.
"""

import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# One entry per editable page. Adding a section to the admin means adding it
# here, which is deliberate: the check has to be told what to check.
SUBJECTS = [
    {
        "name": "contact",
        "model": ROOT / "lib" / "contact.php",
        "form": ROOT / "admin" / "sections" / "contact.php",
        "page": ROOT / "pages" / "contact" / "index.php",
        # Fields nothing renders, and nothing should: they are the store's
        # own bookkeeping, and the admin prints them as such.
        "page_indirect": {"updated", "footer_synced"},
        # Fields the form does not write, and should not.
        "form_exempt": {"updated", "footer_synced", "offices.items.id"},
    },
]

# Editors this file cannot check this way, and what proves them instead.
#
# An admin section with a page to view is an editor over a content model, so
# every one of them has to be accounted for — here or in SUBJECTS. That is the
# point of the pairing: "the check covers one of the two editable pages" was
# true for a long time and nothing said so.
COVERED_ELSEWHERE = {
    "careers": (
        "tools/test_careers_admin.py",
        "Both sides of the careers page are loops. The editor posts its seven "
        "body fields as name=\"<?= h($field) ?>\" and the page renders them by "
        "walking CAREERS_SECTIONS, so the regexes below read the loop variable "
        "and find 'h' and 'field' rather than 'about' and 'offers'. Adding it "
        "to SUBJECTS would mean exempting exactly the seven fields most likely "
        "to drift, and then reporting that all is well. It is proved by round "
        "trip instead: a marker through every field the model declares, editor "
        "to visitor, over HTTP.",
    ),
}


def editors() -> list[str]:
    """The admin sections that edit a page of the website.

    ADMIN_PAGE_SECTIONS already means exactly this, so it is asked rather than
    re-derived: a second definition of "an editor" is a second thing to keep
    true, and this one would be wrong the moment a section gained a page to
    view without gaining a content model.
    """
    if not shutil.which("php"):
        return []

    out = subprocess.run(
        ["php", "-r", "require 'lib/admin.php'; echo json_encode(ADMIN_PAGE_SECTIONS);"],
        cwd=ROOT, capture_output=True, text=True,
    )
    if out.returncode != 0 or not out.stdout.strip():
        return []

    import json
    return json.loads(out.stdout)


# ---------------------------------------------------------------- the model


def model_fields(php: str) -> set[str]:
    """Every field contact_defaults() and contact_office_defaults() promise.

    Read out of the two functions that define the shape, rather than out of
    content/contact.json — the file is one instance of the shape, and an
    optional field that happens to be absent from it is still a field.
    """
    fields: set[str] = set()

    body = block(php, "function contact_defaults(): array")
    section = None
    for line in body.splitlines():
        top = re.match(r"\s{8}'(\w+)' => \[", line)
        if top:
            section = top.group(1)
            continue
        if re.match(r"\s{8}\],", line):
            section = None
            continue
        pair = re.match(r"\s+'(\w+)'\s*=>", line)
        if pair:
            fields.add(f"{section}.{pair.group(1)}" if section else pair.group(1))

    office = block(php, "function contact_office_defaults(array $office): array")
    for name in re.findall(r"'(\w+)'\s*=>", office):
        if name in ("street", "locality", "region", "postal_code", "country"):
            fields.add(f"offices.items.schema.{name}")
        else:
            fields.add(f"offices.items.{name}")

    reach = block(php, "function contact_reach_defaults(array $item): array")
    for name in re.findall(r"'(\w+)'\s*=>", reach):
        fields.add(f"reach.items.{name}")

    return fields


def block(php: str, signature: str) -> str:
    """The body of one PHP function, by brace counting from its signature."""
    at = php.index(signature)
    start = php.index("{", at)
    depth = 0
    for i in range(start, len(php)):
        if php[i] == "{":
            depth += 1
        elif php[i] == "}":
            depth -= 1
            if depth == 0:
                return php[start:i]
    raise SystemExit(f"Unbalanced braces after {signature!r}")


# --------------------------------------------------------------- the users


def leaf(field: str) -> str:
    return field.rsplit(".", 1)[-1]


def form_writes(php: str) -> set[str]:
    """The input names the editor posts, as leaf names.

    Matched on name="…" so that a field rendered but never given a name — a
    box that looks editable and saves nothing — does not count as written.
    """
    names = set()
    for value in re.findall(r'name="([^"]+)"', php):
        for part in re.findall(r"[\w]+", value):
            names.add(part)
    # PHP-side reads, for anything assembled rather than posted one-to-one.
    names |= set(re.findall(r"\$row\['(\w+)'\]", php))
    names |= set(re.findall(r"\$_POST\['(\w+)'\]", php))
    return names


def page_reads(php: str) -> set[str]:
    """The field names the renderer takes out of the data.

    Run over the page and over the helpers in lib/, because a field the page
    hands to a helper whole — an office to contact_flag_picture() — is read
    there rather than in the page.

    Any variable subscripted by a string is counted, not a fixed list of
    variable names. That is deliberately generous: contact_addresses() copies
    the record into $s before reading it, and the next helper will use some
    other name. A list of blessed variables is the same kind of thing that
    goes stale as the drift this file exists to catch — so the check errs
    towards accepting a read, and stays strict about the absence of one.
    """
    found: set[str] = set()
    for chain in re.findall(r"\$\w+((?:\['\w+'\])+)", php):
        found |= set(re.findall(r"'(\w+)'", chain))
    return found


# -------------------------------------------------------------------- main


def fingerprints_agree() -> str:
    """The contact fingerprint is computed twice — once in PHP for the editor,
    once in Python for the sync tool — and the two must produce the same digest
    from the same file. They are what decides whether the editor tells someone
    the site footer is stale, so a disagreement is a warning that never clears
    or never appears.

    This has already gone wrong once: the reach rows gained a list of values
    and the Python side went on reading the single value they used to have.
    Returns a problem, or "" when they agree.
    """
    if not shutil.which("php"):
        return ""      # nothing to compare against; serve.py already says so

    php = subprocess.run(
        ["php", "-r", "require 'lib/contact.php'; echo contact_fingerprint(contact_load());"],
        cwd=ROOT, capture_output=True, text=True,
    )
    if php.returncode != 0:
        return f"could not run lib/contact.php: {php.stderr.strip()[:200]}"

    sync = ROOT / "tools" / "sync_site_contact.py"
    if not sync.is_file():
        return ""

    import importlib.util
    spec = importlib.util.spec_from_file_location("sync_site_contact", sync)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    mine = module.fingerprint(module.load())

    if mine == php.stdout.strip():
        return ""

    return (
        "contact_fingerprint() in lib/contact.php and fingerprint() in "
        "tools/sync_site_contact.py disagree about the same contact.json "
        f"({php.stdout.strip()[:12]}… vs {mine[:12]}…) — the editor's "
        "footer-drift warning is therefore wrong in one direction or the other"
    )


def main() -> None:
    problems: list[str] = []

    drift = fingerprints_agree()
    if drift:
        problems.append(drift)
    else:
        print("fingerprint  —  PHP and Python agree")


    accounted = {s["name"] for s in SUBJECTS} | set(COVERED_ELSEWHERE)
    for name in editors():
        if name not in accounted:
            problems.append(
                f"'{name}' is an editor in lib/admin.php that nothing here "
                f"checks — add it to SUBJECTS, or to COVERED_ELSEWHERE naming "
                f"the test that proves its fields reach the page instead"
            )
    for name, (where, _) in sorted(COVERED_ELSEWHERE.items()):
        # A pointer at a test is only worth what the test is worth, and a
        # pointer at a test that no longer exists reads exactly like coverage.
        if not (ROOT / where).is_file():
            # The reason travels with the problem, because the first instinct
            # on reading this will be to add a SUBJECTS entry instead, and
            # that is the thing that does not work.
            problems.append(
                f"'{name}' is said to be covered by {where}, which does not "
                f"exist — the field check for that editor is gone. It is not "
                f"checked here because: {COVERED_ELSEWHERE[name][1]}"
            )
        else:
            print(f"{name}  —  checked by {where}, not here")

    for subject in SUBJECTS:
        for key in ("model", "form", "page"):
            if not subject[key].is_file():
                raise SystemExit(f"Missing {subject[key].relative_to(ROOT)}")

        model_php = subject["model"].read_text()

        model = model_fields(model_php)
        form = form_writes(subject["form"].read_text())

        # The renderer is the page plus the helpers it renders through:
        # contact_flag_picture() reads the flag, contact_reach_href() reads the
        # kind and the value. A field used only inside one of those is still a
        # field the visitor sees, so both files count as the page side.
        page = page_reads(subject["page"].read_text()) | page_reads(model_php)

        print(f"{subject['name']}  —  {len(model)} fields in the model")

        missing_form = sorted(
            f for f in model
            if f not in subject["form_exempt"] and leaf(f) not in form
        )
        missing_page = sorted(
            f for f in model
            if f not in subject["page_indirect"] and leaf(f) not in page
        )

        for field in missing_form:
            problems.append(
                f"{subject['name']}: '{field}' is in the model but nothing in "
                f"{subject['form'].relative_to(ROOT)} writes it — it cannot be edited"
            )
        for field in missing_page:
            problems.append(
                f"{subject['name']}: '{field}' is in the model but "
                f"{subject['page'].relative_to(ROOT)} never reads it — editing it "
                f"changes nothing a visitor sees"
            )

        # And the other direction: the form must not promise a field the model
        # does not keep, because contact_from_post() would silently drop it.
        model_leaves = {leaf(f) for f in model}
        posted = set(re.findall(r'name="(?:reach|offices|form|meta|hero)\[[^"]*?(\w+)\]"',
                                subject["form"].read_text()))
        stray = sorted(n for n in posted if n not in model_leaves)
        for name in stray:
            problems.append(
                f"{subject['name']}: the form posts '{name}', which the model "
                f"does not define — it is discarded on save"
            )

        if not (missing_form or missing_page or stray):
            print("           every field is edited, stored and rendered")

    if problems:
        print(f"\n{len(problems)} problem(s):\n")
        for p in problems:
            print(f"  - {p}")
        print(
            "\nThe page's shape and the editor's shape have parted. Bring the "
            "model, the form and the renderer back into line — see the note at "
            "the top of admin/sections/contact.php."
        )
        sys.exit(1)

    print("\nThe editors and the pages they edit describe the same thing.")


if __name__ == "__main__":
    main()
