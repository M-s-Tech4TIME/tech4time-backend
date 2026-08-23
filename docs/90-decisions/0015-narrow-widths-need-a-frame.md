# 0015 — Narrow widths are tested in a frame, not in the window

**Status:** accepted · **Applies to:** frontend

## Decision

`tools/check_responsive.py` loads each page into an `<iframe>` of the width being tested, inside a
window that stays wide. It never resizes the browser window to the width under test.

Every run prints the viewport it actually measured, and fails if that number is not the width it
asked for.

## Context

**Firefox will not make a window narrower than about 500px**, which leaves a viewport of about
488. Ask WebDriver for 320 and the call succeeds, returns no error, and you measure 488.

That is not a limitation to work around quietly. A check written the obvious way — resize to 320,
measure, report — passes while testing a width it never reached, and leaves behind a record saying
the narrowest phones are covered. The first version of this check did exactly that: it reported
"clean" at 320, 360 and 390, and all three had measured 488.

Both bugs it was written to find live below 488px:

- the specialities slider's control row is eight 44px tap targets, centred; at 320px it is wider
  than the screen and hangs off both edges, and the right-hand overhang scrolls the whole page
- the About page's call to action was 351px wide with `white-space: nowrap`, and `.btn` clips its
  own overflow for the shine sweep, so the end of the label was cut off in silence

An iframe establishes its own viewport. Media queries, `100vw` and `clientWidth` all resolve against
the frame, so 320 means 320. It is same-origin, so the document inside can be measured directly.

## Consequences

- The check cannot test anything that depends on the real window: no `window.matchMedia` against the
  device, no scrollbar behaviour of the top-level document, no `position: fixed` relative to the
  browser chrome. `position: fixed` elements are skipped for that reason.
- The measured viewport is a few pixels under the frame width, because the frame has a scrollbar.
  The check allows up to 40px of slack and fails outside it — which is what catches a clamp.
- A page that must be tested at a true device width, rather than a true CSS width, still needs a
  real device. This proves layout, not rendering.
- **Any future browser check that cares about width below 488px must use a frame too.** Resizing the
  window will look like it worked.

## What the widths mean

Two of them are success criteria rather than devices, which is why they are not round numbers of
anyone's phone:

| Width | Why |
|---|---|
| 320 | SC 1.4.10 Reflow is *defined* at 320 CSS px, so the no-sideways-scrolling assertion is that criterion tested rather than argued |
| 640 | a 1280px desktop at 200% zoom, which is SC 1.4.4 Resize Text |
| 360, 414 | the common Android and iPhone widths |
| 768, 1024, 1440 | the breakpoints in `layout.css`, so a failure lands near a rule somebody wrote |
