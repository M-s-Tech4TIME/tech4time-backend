#!/usr/bin/env python3
"""
Check the Tech4TIME palette against WCAG 2.1 AA.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_contrast.py

Thresholds
  4.5:1  normal text (1.4.3)
  3.0:1  large text, and non-text UI component boundaries / focus indicators
         (1.4.11, 2.4.11)

Purely decorative surfaces -- hairline dividers between already-visible blocks,
gradient sweeps that never sit under text -- carry no contrast requirement and
are listed under DECORATIVE for information only.

Two values here differ from the palette in the project plan, because the plan's
originals fail AA and the plan also requires AA:

  --text-muted (light)  #8A8A8E -> #6A6A6E   (was 3.29:1 on bg-base)
  --text-muted (dark)   #7A7A7E -> #8A8A8E   (was 4.27:1 on bg-surface)

The plan's original #8A8A8E / #7A7A7E greys are retained, reassigned to
--border-strong, where the 3:1 component-boundary bar is the applicable one.

Link and focus colour in light mode is --accent-text #6A6C71 rather than the
gradient's end stop #6E7075, which lands at 4.39:1 on bg-surface. The end stop
keeps its plan value and is still what the gradient fills use.

Keep this in sync with assets/css/theme.css.
"""

import sys

AA_TEXT = 4.5
AA_LARGE = 3.0

LIGHT = {
    "bg-base": "#FAFAFA",
    "bg-surface": "#F1F1F2",
    "bg-elevated": "#FFFFFF",
    "text-primary": "#111113",
    "text-secondary": "#4A4A4E",
    "text-muted": "#6A6A6E",
    "border-subtle": "#E1E1E3",
    "border-strong": "#8A8A8E",
    "silver-accent-start": "#C7C9CC",
    "silver-accent-mid": "#9EA1A6",
    "silver-accent-end": "#6E7075",
    "accent-text": "#6A6C71",
    "focus-ring": "#6A6C71",
    "on-accent": "#111113",
}

DARK = {
    "bg-base": "#0B0B0C",
    "bg-surface": "#151517",
    "bg-elevated": "#1D1D20",
    "text-primary": "#F5F5F6",
    "text-secondary": "#B4B4B8",
    "text-muted": "#8A8A8E",
    "border-subtle": "#2A2A2D",
    "border-strong": "#6A6A6E",
    "silver-accent-start": "#E8E9EB",
    "silver-accent-mid": "#B8BABE",
    "silver-accent-end": "#7C7E83",
    "accent-text": "#B8BABE",
    "focus-ring": "#B8BABE",
    "on-accent": "#111113",
}

SURFACES = ["bg-base", "bg-surface", "bg-elevated"]

# (foreground, backgrounds, role, threshold)
PAIRS = [
    ("text-primary", SURFACES, "body text and headings", AA_TEXT),
    ("text-secondary", SURFACES, "subtext", AA_TEXT),
    ("text-muted", SURFACES, "captions, placeholders", AA_TEXT),
    ("accent-text", ["bg-base", "bg-surface"], "links, accent text, icon strokes", AA_TEXT),
    ("focus-ring", SURFACES, "keyboard focus indicator", AA_LARGE),
    ("border-strong", SURFACES, "form/control boundaries", AA_LARGE),
    # Primary buttons are filled with the silver gradient's start->mid range and
    # take dark ink. The mid stop is the worst case under that ink.
    ("on-accent", ["silver-accent-start", "silver-accent-mid"], "button label on silver fill", AA_TEXT),
]

# No contrast requirement; reported so regressions stay visible.
DECORATIVE = [
    ("border-subtle", SURFACES, "hairline dividers, card edges"),
    ("silver-accent-end", ["bg-base"], "gradient end stop (fills/sweeps only)"),
]


def srgb_to_linear(c: float) -> float:
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


def luminance(hex_colour: str) -> float:
    h = hex_colour.lstrip("#")
    r, g, b = (int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))
    return (
        0.2126 * srgb_to_linear(r)
        + 0.7152 * srgb_to_linear(g)
        + 0.0722 * srgb_to_linear(b)
    )


def ratio(fg: str, bg: str) -> float:
    a, b = luminance(fg), luminance(bg)
    lo, hi = sorted((a, b))
    return (hi + 0.05) / (lo + 0.05)


def check(name: str, t: dict) -> list[str]:
    print(f"\n{name}")
    print("-" * 76)
    failures = []

    for fg, bgs, role, threshold in PAIRS:
        kind = "AA" if threshold == AA_TEXT else "AA-large"
        worst = min(ratio(t[fg], t[bg]) for bg in bgs)
        for bg in bgs:
            r = ratio(t[fg], t[bg])
            ok = r >= threshold
            if not ok:
                failures.append(
                    f"{name}: {fg} on {bg} ({role}) = {r:.2f}:1, needs {threshold}"
                )
        status = "PASS" if worst >= threshold else "FAIL"
        print(f"  [{status}] worst {worst:5.2f}:1  (needs {threshold} {kind:8s})  "
              f"{fg}  — {role}")

    print("\n  decorative (no requirement):")
    for fg, bgs, role in DECORATIVE:
        worst = min(ratio(t[fg], t[bg]) for bg in bgs)
        print(f"         {worst:5.2f}:1   {fg}  — {role}")

    return failures


def main() -> None:
    failures = check("LIGHT MODE", LIGHT) + check("DARK MODE", DARK)

    print("\n" + "=" * 76)
    if failures:
        print(f"{len(failures)} pair(s) below AA:\n")
        for f in failures:
            print(f"  - {f}")
        sys.exit(1)
    print("All functional colour pairs meet WCAG AA in both modes.")


if __name__ == "__main__":
    main()
