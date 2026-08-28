#!/usr/bin/env python3
"""
Read every stylesheet the way the browser's parser does, and refuse two silent
faults it cannot report.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_css.py

WHY THIS EXISTS
Both faults below were found the expensive way -- one by a browser crawl that
took several minutes a run, one by hand after four wrong guesses -- and both
are a second's work to find here. Neither shows up in a diff, because both look
exactly like the correct thing beside them.

  1. A COMMENT THAT CLOSES EARLY. CSS comments do not nest. Leave a stray `*/`
     in the middle of prose and everything after it is tokens; the parser then
     consumes them as a selector and keeps going until it finds a `{`, which is
     the NEXT REAL RULE's brace. So the rule after a broken comment is eaten
     whole, silently, and the stylesheet is otherwise fine.

     That happened in admin.css while fixing something else. The rule that
     vanished was the one being added, so every measurement said the fix had
     not worked and the next four attempts were spent adjusting a rule the
     browser had never seen.

  2. A COLOUR TOKEN IN THE `outline` SHORTHAND. `outline: var(--focus-ring)`
     parses, computes, and draws nothing: the shorthand resets outline-style to
     `none`, and a colour on its own leaves it there. In a dump it reads as a
     ring -- `rgb(106, 108, 113) 3px` -- because the colour and the width are
     both present. Only the style is missing, and the style is the part that
     decides whether anything appears.

     Five rules in admin.css had it. The rail, every text input, the accordions
     and every editor button therefore had no focus ring, each having overridden
     the correct rule in base.css. Nobody had noticed, because seeing it means
     pressing Tab on a page you normally click through.

WHAT IT DOES NOT DO
Parse CSS properly. There is no parser here and there should not be: this is a
short list of specific, known, silent faults, and the value is that it costs
nothing to run. `check_contrast.py` reads the colours; the browser tools read
the rendered page. This reads the file.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# The colour tokens. A token whose name says colour and whose value is one is
# not safe as a whole `outline`, however it reads.
COLOUR_TOKEN = re.compile(r"^--[a-z0-9-]*"
                          r"(ring|colou?r|text|bg|background|border|accent|fill)"
                          r"[a-z0-9-]*$")

SHORTHANDS_NEEDING_STYLE = ("outline", "border", "border-block", "border-inline",
                            "border-top", "border-right", "border-bottom",
                            "border-left")


def sheets() -> list[Path]:
    """Every stylesheet in the repository, wherever this half keeps them."""
    found: list[Path] = []
    for base in ("assets/css", "public/assets/css"):
        d = ROOT / base
        if d.is_dir():
            found += sorted(d.rglob("*.css"))
    return found


def colour_tokens(text: str) -> set[str]:
    """Custom properties whose value is a colour, by their value not their name."""
    names = set()
    for name, value in re.findall(r"(--[a-z0-9-]+)\s*:\s*([^;]+);", text):
        v = value.strip().lower()
        if re.fullmatch(r"#[0-9a-f]{3,8}", v) or v.startswith(("rgb(", "rgba(",
                                                               "hsl(", "hsla(",
                                                               "oklch(", "color(")):
            names.add(name)
    return names


def check_comments(path: Path, text: str, problems: list[str]) -> None:
    stripped = re.sub(r"/\*.*?\*/", "", text, flags=re.S)

    if "*/" in stripped:
        line = text[:text.index("*/", text.rindex("/*") if "/*" in text else 0)].count("\n")
        # Find the first surviving */ honestly, by walking the stripped text back
        # to a line number in the original.
        idx = stripped.index("*/")
        before = stripped[:idx]
        approx = text.count("\n", 0, text.find(before[-60:]) if before[-60:] else 0) + 1
        problems.append(
            f"{path.relative_to(ROOT)}: a `*/` survives comment stripping, near "
            f"line {approx}. A comment closed early and the prose after it is "
            f"being parsed as CSS -- which eats the NEXT rule whole.")
        return

    if "/*" in stripped:
        problems.append(
            f"{path.relative_to(ROOT)}: an unterminated `/*` — everything after "
            f"it is inside a comment, including rules meant to apply.")
        return

    opens, closes = stripped.count("{"), stripped.count("}")
    if opens != closes:
        problems.append(
            f"{path.relative_to(ROOT)}: {opens} `{{` against {closes} `}}` "
            f"outside comments — a rule is unclosed or over-closed.")


def check_shorthands(path: Path, text: str, tokens: set[str], problems: list[str]) -> None:
    body = re.sub(r"/\*.*?\*/", "", text, flags=re.S)

    for n, line in enumerate(body.splitlines(), 1):
        m = re.match(r"\s*([a-z-]+)\s*:\s*var\((--[a-z0-9-]+)\)\s*;\s*$", line)
        if not m:
            continue
        prop, token = m.group(1), m.group(2)
        if prop not in SHORTHANDS_NEEDING_STYLE:
            continue
        if token not in tokens and not COLOUR_TOKEN.match(token):
            continue
        problems.append(
            f"{path.relative_to(ROOT)}:{n}: `{prop}: var({token});` — {token} is "
            f"a COLOUR, and `{prop}` is a shorthand. This resets "
            f"{prop}-style to `none`, so nothing is drawn, while the computed "
            f"value still shows a colour and a width. Write "
            f"`{prop}: 2px solid var({token});` or set {prop}-color.")


def check_declared(files: list[Path], problems: list[str]) -> None:
    """Every var(--token) a stylesheet reads is declared by one of them.

    A custom property that was never declared does not fail, warn, or fall back
    to anything sensible: the declaration using it is thrown away, and the
    element keeps whatever it inherited. So `background-color: var(--bg-subtle)`
    against a token that does not exist is a background that silently is not
    there -- and in a palette with --bg-base, --bg-surface and --bg-elevated,
    guessing the fourth name is easy to do and impossible to see.

    A var() with a fallback -- var(--x, 1rem) -- is left alone: naming a
    fallback is saying the token may be absent, which is what --sphere-size and
    --slider-columns do while JavaScript has not run yet.
    """
    declared: set[str] = set()
    for path in files:
        declared |= set(re.findall(r"^\s*(--[a-z0-9-]+)\s*:", path.read_text(encoding="utf-8"),
                                   re.M))

    for path in files:
        body = re.sub(r"/\*.*?\*/", "", path.read_text(encoding="utf-8"), flags=re.S)
        for n, line in enumerate(body.splitlines(), 1):
            for token in re.findall(r"var\(\s*(--[a-z0-9-]+)\s*\)", line):
                if token not in declared:
                    problems.append(
                        f"{path.relative_to(ROOT)}:{n}: `var({token})` — no "
                        f"stylesheet declares {token}. The whole declaration is "
                        f"dropped and the element keeps what it inherited, "
                        f"silently. Did you mean one of: "
                        f"{', '.join(sorted(t for t in declared if t.split('-')[2:3] == token.split('-')[2:3])[:4]) or 'a token that exists'}?")


def main() -> int:
    files = sheets()
    if not files:
        print("No stylesheets found — nothing to check.")
        return 0

    tokens: set[str] = set()
    for path in files:
        tokens |= colour_tokens(path.read_text(encoding="utf-8"))

    problems: list[str] = []
    for path in files:
        text = path.read_text(encoding="utf-8")
        check_comments(path, text, problems)
        check_shorthands(path, text, tokens, problems)

    check_declared(files, problems)

    for path in files:
        print(f"  ok    {path.relative_to(ROOT)}")

    if problems:
        print(f"\ncheck_css: {len(problems)} problem(s)\n")
        for line in problems:
            print(f"  FAIL  {line}")
        print("\nEvery one of these is silent in a browser and invisible in a diff.")
        return 1

    print(f"\ncheck_css: {len(files)} stylesheets, "
          f"{len(tokens)} colour tokens, every var() declared, "
          f"comments and braces balanced")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
