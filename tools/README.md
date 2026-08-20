# Build and audit tools

**Nothing in this directory is deployed.** These scripts prepare assets and
verify the site; the deployable output is the static HTML, CSS, JS and images at
the repo root. `.htaccess` blocks `/tools/` over HTTP as a second line of
defence in case the whole tree is ever uploaded.

The site itself has **no build step** — it runs by opening or uploading the
files as they are. These tools generate committed artefacts (the icon sprite,
favicons, resized images) and check invariants. You never need to run them to
deploy; you run them when a source asset or a shared block changes.

Requirements: Python 3 with Pillow, plus `curl`. No npm, no bundler.

## Asset generation

Run in this order after changing source artwork.

| Script | Produces | Notes |
|---|---|---|
| `build_logos.py` | `assets/images/logo/logo-{light,dark}-{180,360,540}.{png,webp}` | Also writes the trimmed `*-full.png` build sources into `tools/masters/logo/`. |
| `build_favicons.py` | `assets/images/favicon/*` | Crops the clock face from the logo and composites it on the dark base tone. Depends on `build_logos.py`. |
| `build_og_image.py` | `assets/images/og/tech4time-og.png` | 1200×630 share card. Downloads the Inter TTF to the scratchpad; Pillow cannot read the site's woff2. Depends on `build_logos.py`. |
| `build_images.py` | `assets/images/{tech,clients,photos,sections}/*` | Copies the 56 images the NextJS pages reference, renames them, and emits WebP plus a fallback. |
| `fetch_fonts.py` | `assets/fonts/inter-{latin,latin-ext}.woff2` | Prints the `unicode-range` values to keep `base.css` in sync. |
| `build_icon_sprite.py` | `assets/icons/sprite.svg` | Master set of the 116 icons the pages use, from Font Awesome Free metadata. Resolves FA5 → FA6 renames via the official alias index. |
| `inject_icons.py` | Inline `<symbol>` blocks in each page | Run after adding or removing an icon reference. `--check` verifies without writing. |

`tools/masters/` holds the original logo artwork and the trimmed full-resolution
build sources. It is deliberately outside `assets/` so 2MB of source files is
never uploaded to the web server.

## Verification

Run these before committing, and as the Phase 5 audit gate. All exit non-zero on
failure.

| Script | Checks |
|---|---|
| `check_contrast.py` | Every functional colour pair meets WCAG AA in both modes. Run after any change to `theme.css`. |
| `audit_pages.py` | Per page: `lang`, viewport, canonical, unique title/description, one `<h1>`, no skipped heading levels, `alt` on every image, `width`/`height` on every image, valid JSON-LD, `rel="noopener noreferrer"` on external links, resolvable internal links, and that every `<use href="#icon">` has an inlined symbol. |
| `check_shared_markup.py` | The header and footer on every page still match `tools/templates/`, and the script tags match. This is what keeps thirteen hand-pasted copies from drifting. |

Quick full pass:

```bash
python3 tools/check_contrast.py \
  && python3 tools/inject_icons.py --check \
  && python3 tools/check_shared_markup.py \
  && python3 tools/audit_pages.py
```

## Templates

`tools/templates/` holds the canonical shared markup. See the README in that
directory.
