#!/usr/bin/env python3
"""
Mark up the scroll-reveal targets on every page.

Build tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/apply_reveals.py            # dry run: report what it would do
    python3 tools/apply_reveals.py --write    # apply
    python3 tools/apply_reveals.py --strip    # remove every marker again

WHY A TOOL AND NOT FIFTEEN HAND EDITS
The reveal targets are a structural rule ("each section's header, then its
body"), not fifteen independent decisions. Written by hand the rule survives
only as long as my patience does, and the pages drift. Here the rule is stated
once, and --strip makes the whole pass reversible, which is what lets it be
tuned rather than argued about.

THE RULE
For every <section> in <main>, descend past a lone .container wrapper, then mark
that wrapper's element children. A child that is a grid of 2..MAX_STAGGER cards
is skipped in favour of its children, so the cards arrive in sequence rather
than as one block.

WHAT IS DELIBERATELY NOT MARKED, and why each one matters:

  Heroes (.hero, .page-hero)
      They hold the LCP element. [data-reveal] starts at opacity 0, and an
      element that is transparent at first paint does not count as painted —
      hiding a hero would push Largest Contentful Paint out by the length of the
      animation and damage the exact metric this project cares about. Heroes are
      above the fold and need no reveal to be seen.

  Tab panels (.tabs__panel)
      Hidden panels have no layout box, so IntersectionObserver never reports
      them as intersecting. A card revealed this way inside a closed panel would
      still be at opacity 0 when the visitor opened that tab: content, present
      in the DOM and invisible on screen. This is the failure mode worth being
      most careful about, so nothing inside a panel is marked at all.

  The terminal (.terminal)
      It is inside the hero, and its lines already arrive one by one on their
      own delays. A second fade over the top of that would fight it.

  The privacy policy body (.legal__body)
      Someone reading a privacy policy is looking for a clause, often having
      followed a link straight to it. Animating the text they came for is at
      best noise and at worst an obstacle. Its call-to-action band still
      reveals; the document itself is simply there.

  The header, footer and dock
      Shared markup. Editing it here would break byte-identity with
      tools/templates/ and fail tools/check_shared_markup.py.

  Anything already [hidden]
      Same reasoning as tab panels.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import htmltree  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent

# Subtrees the walk refuses to enter, by class.
SKIP_CLASSES = {
    "hero", "page-hero",        # LCP
    "error",                    # the 404 body: its <h1> is that page's LCP
    "tabs__panel",              # hidden: the observer never fires
    "terminal",                 # runs its own animation
    "legal__body",              # a legal document, not a pitch — see below
    "visually-hidden",          # never painted; revealing it is a no-op
    "site-header", "site-footer", "dock",
}

# A container child is expanded into its own children when it holds a run of
# sibling cards, so they arrive in sequence rather than as one block. Above this
# many, the stagger becomes a queue the visitor waits in.
MAX_STAGGER = 12


def is_card_run(node: htmltree.Node, kids: list[htmltree.Node]) -> bool:
    """
    True when the children are a repeating set rather than mixed content.

    Structural rather than by class name: a run is the same element repeated,
    carrying the same class. That catches every grid, list and timeline on the
    site without a table of container names to keep in step with the CSS — and
    it correctly declines to break apart a heading block or a body of prose,
    whose children are a mix of tags and so are read as one thing.
    """
    if not 2 <= len(kids) <= MAX_STAGGER:
        return False
    if len({k.tag for k in kids}) != 1:
        return False
    shared = set.intersection(*(k.classes for k in kids))
    # Unclassed list items (<li> wrapping a link) are still a run.
    return bool(shared) or not any(k.classes for k in kids)


def skipped(node: htmltree.Node) -> bool:
    return (
        node.has(*SKIP_CLASSES)
        or "hidden" in node.attrs
        or node.tag in ("script", "template")
    )


def content_root(section: htmltree.Node) -> htmltree.Node:
    """
    Descend past a wrapper that exists only to constrain width.

    A .container holding the whole section is scaffolding, not content: marking
    it would reveal the entire section as one block and lose the sequence.
    """
    node = section
    while True:
        kids = [c for c in node.children if c.tag not in ("script",)]
        if len(kids) == 1 and kids[0].has("container"):
            node = kids[0]
            continue
        return node


def targets_for(page: htmltree.Node) -> list[tuple[htmltree.Node, bool]]:
    """[(node, staggered)] in document order."""
    main = next(page.find(tag="main"), None)
    if main is None:
        return []

    out: list[tuple[htmltree.Node, bool]] = []
    for section in main.find(tag="section"):
        if skipped(section) or any(skipped(a) for a in section.ancestors()):
            continue

        children = [c for c in content_root(section).children if not skipped(c)]
        # A lone child has nothing to be staggered against, so it reveals plain.
        stagger_block = len(children) > 1

        for child in children:
            kids = [c for c in child.children if not skipped(c)]
            if is_card_run(child, kids):
                out.extend((k, True) for k in kids)
            else:
                out.append((child, stagger_block))
    return out


def pages() -> list[Path]:
    """
    Every page, including the one that is PHP.

    Careers is index.php because its listings come from content/careers.json.
    Missing it was a silent gap: the page simply had no reveals while every
    check reported a pass, because a page with nothing marked has nothing that
    can fail. Its PHP conditionals wrap whole <section> elements, so the tag
    tree is balanced whichever branch runs, and both branches get marked.
    """
    found = [ROOT / "index.html", ROOT / "404.html"]
    for name in ("index.html", "index.php"):
        found += sorted((ROOT / "pages").rglob(name))
    return [p for p in found if p.exists()]


def strip(path: Path) -> int:
    text = path.read_text()
    before = text
    for attr in (' data-reveal-delay=""', " data-reveal-delay",
                 ' data-reveal=""', " data-reveal"):
        text = text.replace(attr, "")
    if text != before:
        path.write_text(text)
    return before.count("data-reveal")


def apply(path: Path, write: bool) -> tuple[int, int, list[str]]:
    source = path.read_text()
    tree = htmltree.parse(source)
    targets = targets_for(tree)

    edits, notes = [], []
    for node, staggered in targets:
        if "data-reveal" in node.attrs:
            continue
        attr = " data-reveal data-reveal-delay" if staggered else " data-reveal"
        edits.append((node.start, attr))
        notes.append(
            f"{'stagger' if staggered else 'plain  '}  "
            f"{node.tag}.{'.'.join(sorted(node.classes)) or '(no class)'}"
        )

    if write and edits:
        path.write_text(htmltree.insert_attribute(source, edits))

    staggered_count = sum(1 for n in notes if n.startswith("stagger"))
    return len(edits), staggered_count, notes


def main() -> None:
    write = "--write" in sys.argv
    verbose = "-v" in sys.argv or "--verbose" in sys.argv

    if "--strip" in sys.argv:
        total = sum(strip(p) for p in pages())
        print(f"removed {total} data-reveal markers")
        return

    grand = 0
    for path in pages():
        count, staggered, notes = apply(path, write)
        grand += count
        rel = path.relative_to(ROOT)
        print(f"{str(rel):46s} {count:3d} targets ({staggered} staggered)")
        if verbose:
            for note in notes:
                print(f"    {note}")

    verb = "marked" if write else "would mark"
    print(f"\n{verb} {grand} elements across {len(pages())} pages")
    if not write:
        print("dry run — pass --write to apply")


if __name__ == "__main__":
    main()
