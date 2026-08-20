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
| `build_images.py` | `assets/images/{tech,clients,photos,sections,pages}/*` | Copies referenced content images, renames them, and emits WebP plus a fallback. Page artwork comes from the **live site** (staged in `tools/masters/`); third-party product and client logos come from the NextJS `public/` folder, the only place they exist. `trim` crops the flat white margin on the live site's exports. |
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
| `test_contact_handler.py` | Exercises `contact-handler.php` end to end. Needs the PHP CLI (`sudo apt install php-cli`); skips nothing, fails loudly if it is absent. |

Quick full pass:

```bash
python3 tools/check_contrast.py \
  && python3 tools/inject_icons.py --check \
  && python3 tools/check_shared_markup.py \
  && python3 tools/audit_pages.py
```

## Testing the contact form

`test_contact_handler.py` starts `php -S`, points PHP's `sendmail_path` at a
script that captures the outgoing message to a file, and then reads back the
exact bytes `mail()` was asked to send. That is what lets it assert the
header-injection defences actually work rather than merely look right.

```bash
python3 tools/test_contact_handler.py
```

It covers the method check, the honeypot, every validation rule, CR/LF
injection into each field, the assembled message, non-ASCII round trips, and
the no-JavaScript HTML response.

**It does not test delivery**, and cannot: that needs a real mail server. On the
cPanel host, before launch:

1. **`info@tech4time.bd` must exist** as a mailbox, forwarder, or the domain's
   default address. The domain's MX points at the web server itself, so the
   message never leaves the box — but it still has to land somewhere.
2. **`no-reply@tech4time.bd` should exist**, even if it only discards. It is the
   `From:` address, and it is where bounces go.
3. Submit the real form once with JavaScript on, once with it off, and confirm
   both arrive and that hitting reply goes to the visitor rather than to
   `no-reply`.
4. If `mail()` proves unreliable on the host, the fix is SMTP authentication
   against the host's own mail server rather than more `mail()` retries.

The domain's DNS is already set up for this: SPF authorises the web server's IP
(`+a +mx +ip4:...`), and cPanel signs outbound mail with the `default` DKIM
selector. DMARC is `p=none`, so nothing is quarantined on a failure — worth
tightening to `p=quarantine` only after the form is confirmed working.

## Templates

`tools/templates/` holds the canonical shared markup. See the README in that
directory.
