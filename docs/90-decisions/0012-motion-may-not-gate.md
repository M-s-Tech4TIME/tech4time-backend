# 0012 — Motion may decorate, never gate

**Status:** accepted · **Applies to:** frontend

## Decision

Every animated feature has a defined state for JavaScript being off, `prefers-reduced-motion` being
set, and the script failing to load. That state is never "the content is missing".

## Context

The scroll reveal hides elements so it can reveal them. If `animations.js` never arrives — a network
failure, a blocked request, a syntax error — the content stays hidden forever, and the page looks
finished while being empty.

That is not a hypothetical: it is the default behaviour of every reveal implementation that hides in
CSS and shows in JavaScript.

## Consequences

**The rule that follows:** never hide something until you have established you can show it again.
The hiding is applied by script, not by a stylesheet that loads regardless.

**A watchdog is required.** `theme-init.js` is synchronous and therefore always runs; it lifts the
hidden state at the load event whatever happened to `animations.js`.

**Nothing is hidden at all** under `prefers-reduced-motion` or with scripting off.

**Every feature needs a fallback, and they exist:** the terminal fades in line by line in CSS; the
slideshows render every slide at once in the grid the section already had; the counting figures are
the real figures in the markup; the technology sphere is a grid of logos with alt text.

**Auto-advancing content is pausable** — WCAG 2.2.2 requires it, so there is a pause control, and
slideshows also stop on hover, on focus and when the tab is in the background.

**Enforced by `tools/test_motion.py`**: every page, scrolled end to end, with every marked element
required to finish opaque.
