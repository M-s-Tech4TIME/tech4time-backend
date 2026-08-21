# Tech4TIME — Static Website

The Tech4TIME company website: semantic HTML5, plain CSS3 and vanilla
JavaScript, with **no framework, bundler or build step**. It deploys to cPanel
shared hosting by uploading the files as they are.

Structure, layout, sections and copy are ported from the internal NextJS site.
The colour system is new: pure monochrome with a metallic silver accent derived
from the logo's clock face, with full light and dark modes.

---

## Quick start

Any static server works, because there is nothing to compile:

```bash
python3 -m http.server 8000
# then open http://localhost:8000
```

Use a server rather than opening the files directly — every asset path is
root-relative (`/assets/…`), which `file://` cannot resolve.

---

## Structure

```
.
├── index.html                       homepage (stays at the site root)
├── 404.html                         custom error page
├── pages/
│   ├── about/index.html
│   ├── services/index.html          services hub
│   │   ├── cybersecurity/index.html
│   │   ├── software-development/index.html
│   │   ├── cloud-infrastructure/index.html
│   │   └── hr-solutions/index.html
│   ├── company-profile/index.html
│   ├── careers/index.html
│   ├── contact/index.html
│   ├── resource-certifications/index.html
│   ├── branding-and-advertisement/index.html
│   └── privacy-policy/index.html
├── assets/
│   ├── css/      base, theme, layout, components, animations, pages/
│   ├── js/       theme-init, theme-toggle, nav, animations, forms, main
│   ├── fonts/    self-hosted Inter (latin, latin-ext)
│   ├── icons/    master SVG sprite
│   └── images/   logo, favicon, og, tech, clients, photos, sections
├── tools/                           build and audit scripts — NOT deployed
├── .htaccess                        security headers, caching, clean URLs
├── robots.txt
├── sitemap.xml
└── site.webmanifest
```

Every page except the homepage lives at `/pages/[name]/index.html`, so it is
served at `/pages/[name]/` with no `.html` in the address bar.

---

## How it works

### Colour modes

Tokens live in `assets/css/theme.css` and switch on a `data-theme` attribute on
`<html>`.

`assets/js/theme-init.js` is the **only** synchronous script in `<head>`. It
carries the two decisions that have to be made before the first frame is drawn:
which theme to paint, and whether the scroll reveal is armed. Everything else is
deferred and loaded at the end of `<body>`.

Order of precedence: an explicit choice in `localStorage` wins; otherwise the
`prefers-color-scheme` block in `theme.css` applies — so the OS preference is
still honoured with JavaScript disabled.

### Motion

Sections fade up as they scroll in. The mechanism is deliberately timid about
its own failure: it hides nothing unless it has already established it can
reveal it again, and if `animations.js` never arrives, a watchdog registered in
`theme-init.js` lifts the hidden state at the load event. With scripting off, or
reduced motion requested, nothing is ever hidden — the reveal is decoration, and
decoration is not allowed to be the reason something cannot be read.

Markers are applied by `tools/apply_reveals.py` from one structural rule, not by
hand. `tools/test_motion.py` is the proof: every page, scrolled end to end, with
every marked element required to finish opaque.

The rest of the motion follows the same rule — it may decorate, it may not be
the only way to reach something:

| Where | What | Without JavaScript |
|---|---|---|
| Home hero | The terminal session is typed a character at a time by `terminal.js`, output arriving in blocks | The whole session fades in line by line, in CSS |
| About, Company Profile | Specialities and journey photographs are slideshows (`slider.js`) | Every slide on screen at once, in the grid the section had |
| Company Profile | The experience figures count up to their value | The figures, which are the real ones in the markup |
| Company Profile | Client logos arrive a row at a time, alternating left and right, each card following the one before it | All of them, in place |
| Company Profile | The technology sphere can be taken hold of and turned in any direction | A grid of logos with alt text |
| Every page with a title band | Circuitry animating along all four edges | The same circuit, still |

Auto-advancing slideshows stop on hover, on focus, when the tab is in the
background, and on demand — WCAG 2.2.2 requires the last of those, so there is a
pause control. None of them start at all under `prefers-reduced-motion`.

### CSS

Loaded in cascade order: `base` → `theme` → `layout` → `components` →
`animations` → optional `pages/[name].css`.

Mobile-first, BEM class names, no inline styles. Sizing is fluid via `clamp()`
and auto-fit grids, so layouts scale between breakpoints instead of snapping.
The documented ladder — 480, 768, 1024, 1280, 1440, 1920px — is at the top of
`base.css`.

### JavaScript

Each module registers itself on `window.Tech4Time`; `main.js` runs last and
calls each `init()`, catching errors so one broken feature cannot take the page
down. Everything is progressive enhancement — forms post natively, content is
visible, and navigation works without script.

### Icons

Icons come from a self-hosted sprite, not a webfont or CDN.

The symbols each page uses are **inlined** at the top of its `<body>`, not
linked from `assets/icons/sprite.svg`. Chromium and WebKit do not resolve
`<use href="external.svg#id">` across documents, so a shared external sprite
would render nothing outside Firefox. `assets/icons/sprite.svg` is the master
set those per-page subsets are cut from.

After adding or removing an icon reference:

```bash
python3 tools/inject_icons.py
```

### Shared header and footer

The project rules forbid runtime `fetch()` partials, so the header and footer
are written into every page. `tools/templates/` holds the canonical copies and
`tools/check_shared_markup.py` verifies no page has drifted from them. Change
the template first, propagate, then run the check.

---

## Before committing

```bash
python3 tools/check_contrast.py       # WCAG AA, both modes
python3 tools/inject_icons.py --check # icon blocks current
python3 tools/check_shared_markup.py  # no header/footer drift
python3 tools/audit_pages.py          # SEO, a11y, structure, links
```

See `tools/README.md` for what each one covers.

---

## Deployment

Upload everything **except** `tools/`, `.git/` and the Markdown files to the
cPanel `public_html` directory. Preserve the directory structure — every asset
path is root-relative.

`.htaccess` must be uploaded too: it carries the real security headers.
`X-Frame-Options` and `X-Content-Type-Options` are ignored by browsers when set
via `<meta>`, so the `.htaccess` copy is the one that counts. The `<meta>`
equivalents in each page are defence in depth for hosts that strip headers.

### After the first deploy

1. Confirm HTTPS works, then uncomment the HTTPS redirect and the HSTS header in
   `.htaccess`.
2. Submit `sitemap.xml` in Google Search Console and request indexing.
3. Verify `/pages/about/` resolves without `.html`, and that a bad URL renders
   `404.html`.

### Cache busting

Asset filenames are not content-hashed, because there is no build step to
generate hashes. `.htaccess` caches CSS, JS and fonts for a year, so a changed
`base.css` will not reach returning visitors on its own. When you edit one,
either append a version query to the `<link>`/`<script>` tags
(`base.css?v=2`) or lower the `max-age` for that file type.

---

## Contact form

The site stays static; the forms post to small PHP handlers
(`contact-handler.php`, `careers-handler.php`) that run on cPanel with no
dependencies. They validate server-side, use a honeypot, and mail to
`info@tech4time.bd`. Client-side validation in `assets/js/forms.js` is a
convenience only — never the security boundary.

`mail()` cannot be tested locally; verify the forms on the live host before
launch.
