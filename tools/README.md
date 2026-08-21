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

## Previewing the site

```bash
python3 tools/serve.py          # http://localhost:8000
```

Three pages need PHP now — the careers page, the editor, and the contact
handler — so `python3 -m http.server` shows their source instead of their
output. This runs the PHP built-in server with `tools/dev-router.php`, which
resolves directory requests the way Apache's `DirectoryIndex` does and supplies
the authenticated user that cPanel's Directory Privacy supplies on the host.

**The editor's password is faked locally.** There is no Apache here to ask for
one, and the router hands PHP a username directly so `/admin/` can be worked
on. It binds to localhost only. Two things behave differently from the host:
`.htaccess` is not read, so `/content/` and `/tools/` are reachable locally
though blocked in production; and `mail()` has nothing to hand mail to, so the
contact form validates correctly and then reports it could not send.

Edits made in the local editor write `content/careers.json` for real. Undo with
`git checkout content/careers.json`.

## Verification

Run these before committing, and as the Phase 5 audit gate. All exit non-zero on
failure.

| Script | Checks |
|---|---|
| `check_contrast.py` | Every functional colour pair meets WCAG AA in both modes. Run after any change to `theme.css`. |
| `audit_pages.py` | Per page: `lang`, viewport, canonical, unique title/description, one `<h1>`, no skipped heading levels, `alt` on every image, `width`/`height` on every image, valid JSON-LD, `rel="noopener noreferrer"` on external links, resolvable internal links, and that every `<use href="#icon">` has an inlined symbol. |
| `check_shared_markup.py` | The header and footer on every page still match `tools/templates/`, and the script tags match. This is what keeps thirteen hand-pasted copies from drifting. |
| `test_contact_handler.py` | Exercises `contact-handler.php` end to end. Needs the PHP CLI (`sudo apt install php-cli`); skips nothing, fails loudly if it is absent. |
| `test_careers_admin.py` | Exercises the job post editor end to end — create, validate, publish, reorder, delete, CSRF, and the empty state. Runs against a copy of `content/careers.json` and restores it afterwards. Also needs the PHP CLI. |

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

**It does not test delivery**, and cannot: that needs a real mail server.

### Host state — confirmed

- DNS is already right. MX is `0 tech4time.bd`, so mail to the domain is handled
  by **the web server itself** and never leaves the box. SPF authorises that
  server (`v=spf1 +a +mx +ip4:103.138.189.25 include:spf.mysecurecloudhost.com
  ~all`), and cPanel signs outbound mail with the `default` DKIM selector.
- **`info@tech4time.bd` and `no-reply@tech4time.bd` both exist** as accounts.
- DMARC is `v=DMARC1; p=none;` — monitoring only. Worth tightening to
  `p=quarantine` once the form is confirmed working, not before: at `p=none` a
  failure is visible rather than silently binned.

### Still to do on the host

1. Upload `tools/mail-probe.php` by hand, load it once, read the report, and
   **delete it**. It tests `mail()` on its own, so a mail problem shows up as
   one failed probe rather than as a contact form that quietly swallows
   enquiries. Instructions are in the file's header; it refuses to run until
   you change `PROBE_TOKEN`, and its recipient is hard-coded so it cannot be
   pointed anywhere else.
2. Submit the real form twice — once with JavaScript on, once with it off — and
   confirm both arrive and that hitting reply reaches the visitor rather than
   `no-reply`.
3. If `mail()` proves unreliable, the fix is SMTP authentication against the
   host's own mail server, not more `mail()` retries.

## Job posts

The careers page is the one page here that is not a flat file. Job posts change
on their own schedule, and re-uploading the site to add one is not a workflow
anyone sustains — so `pages/careers/index.php` renders posts from
`content/careers.json`, and `/admin/` edits that file.

Rendered on the **server**, not fetched in the browser: a careers page whose
listings arrive by JavaScript is one search engines index unreliably, and an
unindexed job post is an unfilled role. Each open post also emits a
`JobPosting` block, which is what puts it into Google Jobs rather than only
into ordinary results.

```
pages/careers/index.php     the public page
content/careers.json        the data — posts, and the speculative CV form link
lib/careers.php             shared load/save/validate/render helpers
admin/index.php             the editor
```

`lib/` and `content/` are both blocked over HTTP by `.htaccess`.

### Before the editor works on the host

1. **Protect it.** cPanel → *Directory Privacy* → `admin` → tick "Password
   protect this directory" and add a user. `admin/index.php` refuses to load if
   it cannot see that protection, so it cannot be left open by accident — but
   the refusal is a backstop, not the lock.
2. **Make the data file writable** by PHP. On cPanel, PHP runs as your own
   user, so `content/` needs no special permissions; if a save reports it could
   not write, that is the thing to check first.

### Deploying without destroying live posts

**`content/careers.json` is tracked in git, and the copy on the server is the
one that matters.** It ships seeded with the two roles the live site carries so
a fresh deploy is not blank — but once anyone has used the editor, the server's
copy is the real data and the repo's is stale.

So on any re-upload, **exclude `content/`**, or download the live file first and
put it back afterwards. Overwriting it silently reverts every post added since
launch, and nothing will report an error.

The editor keeps one generation of backup at `content/careers.json.bak`, which
is the fastest way back from a bad edit.

`tools/templates/` holds the canonical shared markup. See the README in that
directory.
