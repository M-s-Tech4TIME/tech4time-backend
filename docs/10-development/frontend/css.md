# CSS

**Applies to:** frontend

Plain CSS3. No preprocessor, no PostCSS, no utility framework, no build step — the files you edit
are the files the browser loads.

---

## The cascade order, which is fixed

```
base.css        reset, self-hosted fonts, non-colour tokens, the fluid type scale
theme.css       every colour token, and the light/dark switch
layout.css      page scaffolding — containers, grids, sections
components.css  buttons, cards, forms, navigation, the shared furniture
animations.css  keyframes and reveal states
pages/<name>.css   optional, one page only
admin.css       the editor UI — loaded only under /admin/
```

Every page links them in that order. Later files depend on earlier ones, so the order is not
negotiable: `theme.css` defines the tokens `components.css` consumes.

---

## Colour lives in exactly one file

`theme.css` holds every colour as a custom property on `:root`. **Never write a hex value anywhere
else.**

```css
:root {
  --bg-base: #fafafa;
  --bg-surface: #f1f1f2;
  --bg-elevated: #ffffff;
  --text-primary: #111113;
  --text-secondary: #4a4a4e;
  --text-muted: #6a6a6e;
  --border-subtle: #e1e1e3;
  /* … */
}
```

The palette is pure monochrome with a metallic silver accent taken from the logo's clock face.

### How the theme switches

Three layers, in precedence order:

```css
:root { … }                              /* light — the default */

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) { … }  /* the OS preference */
}

:root[data-theme="dark"] { … }           /* an explicit choice wins, both ways */
```

1. `data-theme="light"` or `"dark"` on `<html>` is an explicit choice and always wins.
2. Otherwise `prefers-color-scheme` applies — **so the OS preference is honoured with JavaScript
   off**, which is the reason the media query exists rather than leaving it all to the toggle.
3. `assets/js/theme-init.js` stamps the attribute before the first paint, so there is no flash.

> **Adding a colour:** define it in all three blocks or none. A token defined only in the light
> block silently keeps its light value in dark mode, which is exactly the bug
> `check_dark_mode.py` exists to catch.

### Contrast is enforced

```bash
python3 tools/check_contrast.py
```

Every text/background pair must meet WCAG AA in both modes, and component boundaries the 3:1 bar.
The header of `theme.css` records which values were adjusted to pass and what they were before —
keep that going when you change one.

---

## Sizing

Mobile-first, `min-width` queries. The ladder is documented at the top of `base.css`:

| | |
|---|---|
| 480px | large phones |
| 768px | tablets, portrait |
| 1024px | tablets landscape / small laptops |
| 1280px | laptops |
| 1440px | desktop |
| 1920px | large and ultra-wide |

**Layout is fluid between these**, via `clamp()` and auto-fit grids, rather than snapping at each
step. Reach for a breakpoint only when something genuinely has to rearrange — not to resize it.

```css
/* preferred */
font-size: clamp(1.5rem, 1rem + 2vw, 2.5rem);
grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));

/* only when the arrangement itself must change */
@media (min-width: 48em) { … }
```

---

## Naming

BEM: `block__element--modifier`.

```css
.card { }
.card__title { }
.card__title--large { }
```

Component styles go in `components.css`. If it is used on one page only, `pages/<name>.css`.

---

## The rules that are not preferences

**No inline styles.** The CSP is `style-src 'self'` — a `style="…"` attribute or a `<style>` block
will be refused by the browser. This is deliberate: an inline style is what an injected payload
looks like, and forbidding the whole category means the browser rejects it without having to tell
the two apart.

**No CDN, no `@import` from another origin.** Fonts are self-hosted in `assets/fonts/`.

**No hex outside `theme.css`.**

---

## Cache busting

Filenames are not content-hashed — there is no build step to hash them, and `.htaccess` caches CSS
for a year. A changed `base.css` will not reach a returning visitor on its own.

When you change one, either append a version to the `<link>` (`base.css?v=2`) or lower the
`max-age` for that type. [routine-deploys.md](../../20-deployment/routine-deploys.md)

---

## Checks

```bash
python3 tools/check_contrast.py    # WCAG AA, both modes
python3 tools/check_dark_mode.py   # every page as painted, both themes
python3 tools/check_hover.py       # every control visibly responds
```
