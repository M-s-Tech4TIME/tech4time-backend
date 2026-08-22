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
| `build_icon_sprite.py` | `assets/icons/sprite.svg` | Master set of the icons the pages use, from Font Awesome Free metadata. Resolves FA5 → FA6 renames via the official alias index, and appends this project's own symbols from `CUSTOM_SYMBOLS` — currently `grid-dots`, the dock's menu button, which FA Free has no equivalent for in any style. |
| `inject_icons.py` | Inline `<symbol>` blocks in each page | Run after adding or removing an icon reference. `--check` verifies without writing. |

## Markup

| Script | Does |
|---|---|
| `htmltree.py` | Not run directly. A small HTML tree with source offsets, for tools that edit markup structurally. Neither BeautifulSoup nor lxml is installed and this project will not add a dependency for it; `html.parser` alone cannot answer "what are this element's children". Edits are inserted at a recorded offset rather than by re-serialising, so the diff stays readable and the shared blocks keep their byte-identity. |
| `apply_reveals.py` | Marks the scroll-reveal targets on every page, from one structural rule rather than sixteen hand edits: each section's header, then its body, with runs of sibling cards broken out — at whatever depth the markup puts them — so they arrive in sequence. Its docstring lists what is deliberately left unmarked and why: heroes hold the LCP element, hidden tab panels and closed `<details>` never intersect, a slider animates its own slides, a legal document should not be animated at all. `--write` applies, `--strip` reverses the whole pass. |

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
| `check_contrast.py` | Every functional colour pair meets WCAG AA in both modes. Run after any change to `theme.css`. Note that it holds its own copy of the token values rather than reading them out of the stylesheet, so it proves the palette is sound, not that the pages use it. |
| `check_dark_mode.py` | The rendered answer to the same question, in a real browser: every page in both themes at desktop and mobile widths, plus the dock's section panel open. Measures each text element against the background actually behind it, flags any colour that fails to change with the theme, and checks part-transparent artwork against its plate — the case where a logo reads in one theme and vanishes in the other. Needs Firefox and `geckodriver`; prints a notice and exits 0 without them. |
| `audit_pages.py` | Per page: `lang`, viewport, canonical, unique title/description, one `<h1>`, no skipped heading levels, `alt` on every image, `width`/`height` on every image, valid JSON-LD, `rel="noopener noreferrer"` on external links, resolvable internal links, and that every `<use href="#icon">` has an inlined symbol. |
| `check_shared_markup.py` | The header, dock and footer on every page still match `tools/templates/`, and the script tags match. This is what keeps sixteen hand-pasted copies from drifting. |
| `propagate_shared.py` | The other half of that: pushes an edit in `tools/templates/` out to all sixteen pages. `--dry-run` lists what would change. It preserves each page's `aria-current="page"` marker, reading it from the block being replaced rather than from the page, so the active link stays marked and no new markers appear elsewhere. Run `check_shared_markup.py` and `inject_icons.py` after it. |
| `test_contact_handler.py` | Exercises `contact-handler.php` end to end. Needs the PHP CLI (`sudo apt install php-cli`); skips nothing, fails loudly if it is absent. |
| `test_editor.py` | Drives the rich text editor in headless Firefox: that clicking text formats nothing, that the buttons do, and that alignment arrives as a class rather than an inline style. Needs Firefox and `geckodriver`; prints a notice and exits 0 without them. |
| `test_nav.py` | Drives the navigation at both widths: the header nav above 64em with the dock hidden, the dock below it with the header nav hidden, and the section panel opening, trapping focus, closing on Escape and handing focus back. Asserts reachability with `elementFromPoint` rather than attributes — every nav bug it was written for had perfectly correct attributes. |
| `test_theme.py` | The theme switch itself: the OS preference decides when nothing is stored, an explicit choice wins in both directions, and it survives navigation. Drives `prefers-color-scheme` through a real Firefox pref rather than faking the media query. |
| `test_motion.py` | The scroll reveal, which works by hiding content and promising to bring it back. Loads all sixteen pages, scrolls each end to end, and requires every `[data-reveal]` to finish opaque — then checks the same with reduced motion requested, with JavaScript disabled, and with the reveal script pretended missing so the watchdog has to lift the hidden state. Also the rest of the motion: the shine sweep, the typed terminal, the two slideshows (including that the pause control genuinely stops them, which WCAG 2.2.2 requires), the figures that count up, the client logos arriving row by row from alternating sides, and the technology sphere being dragged with a real pointer. The count-up is sampled while it happens, because a figure that never animated also ends on the right number. |
| `check_hover.py` | Moves a real pointer onto one of every kind of interactive element and checks that something changes — in the element, its wrappers or its contents. A rule can exist in the stylesheet and still do nothing, which is how a link on six pages lost its hover to a specificity tie. Also reports elements the pointer cannot reach, which is how the SOC map's decorative ring was found covering its own buttons. |
| `check_content_model.py` | That the three halves of an editable page still describe the same thing: every field `lib/contact.php` defines is written by `admin/sections/contact.php` and read by `pages/contact/index.php`, and neither of those reaches for a field the model does not keep. Nothing at runtime catches a mismatch — a band added to the page and forgotten in the editor is simply unmanageable, and a field left in the editor after the page drops it is a box that changes nothing. Also compares the contact fingerprint computed in PHP against the one computed in Python, since a disagreement there makes the editor's footer-drift warning wrong in one direction or the other. |
| `sync_site_contact.py` | Pushes the contact details out of `content/contact.json` into the two places that repeat them as literal markup: the footer's contact block, and the `address`/`contactPoint` arrays in each page's base Organization structured data. See **The contact page** below. `--dry-run` lists what would change. |
| `test_contact_admin.py` | Exercises the contact page editor end to end — editing the banner, the copy, the offices and the reach rows; adding, reordering, removing and hiding; validation; CSRF; the sanitiser; the empty state; and what happens when the data file is unreadable. Nearly every case saves through the editor and then reads `/pages/contact/` back, because the question is not whether the editor accepted a change but whether it reached the page. Runs against a copy of `content/contact.json` and restores it afterwards. Also needs the PHP CLI. |
| `test_careers_admin.py` | Exercises the job post editor end to end — create, validate, publish, reorder, delete, CSRF, the empty state, and the HTML sanitiser that guards what the careers page prints unescaped. Runs against a copy of `content/careers.json` and restores it afterwards. Also needs the PHP CLI. |

Quick full pass — static, no browser needed, a few seconds:

```bash
python3 tools/check_contrast.py \
  && python3 tools/inject_icons.py --check \
  && python3 tools/check_shared_markup.py \
  && python3 tools/audit_pages.py \
  && python3 tools/check_content_model.py
```

The PHP checks, which need `php-cli` and no browser — under a minute:

```bash
python3 tools/test_contact_handler.py \
  && python3 tools/test_careers_admin.py \
  && python3 tools/test_contact_admin.py
```

The browser checks, which is where the interesting failures live. Slower —
`check_dark_mode.py` alone loads 96 pages — so run them before a commit that
touches CSS or the shared header, not on every save:

```bash
python3 tools/test_nav.py \
  && python3 tools/test_theme.py \
  && python3 tools/test_motion.py \
  && python3 tools/check_hover.py \
  && python3 tools/test_editor.py \
  && python3 tools/check_dark_mode.py
```

Both browser checks that involve the pointer declare Firefox's pointer
capability prefs. Headless Firefox otherwise reports `(hover: none)` and
`(pointer: none)`, which switches off every rule inside
`@media (hover: hover) and (pointer: fine)` — so an effect written there would
be measured as missing while working for everyone with a mouse.

On a confined machine geckodriver may survive each run; `pkill geckodriver`
clears any that pile up.

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

## After launch

One line is staged and waiting in `.htaccess`, under "HSTS — READY TO ENABLE":
delete the `# ` in front of its `Header` directive.

Do it once the site is live and a few pages have loaded over
`https://tech4time.bd` on the real server — not before. It tells browsers never
to request the site over plain http again, which closes the one unencrypted
request that happens before the redirect. That matters because `/admin` is
behind HTTP Basic auth, and Basic auth sends its password base64-encoded:
encoding, not encryption.

The reasoning, including why `includeSubDomains` and `preload` are deliberately
left off, is written above the line itself.

## The admin

Two pages here are not flat files, because what they say changes on its own
schedule and re-uploading the site to change a phone number is not a workflow
anyone sustains. Each renders from a JSON file under `content/`, and `/admin/`
edits that file. There is no database.

```
admin/index.php             the shell: auth, session, CSRF, and the router
admin/sections/overview.php what can be edited, and plainly what cannot
admin/sections/careers.php  the job post editor        -> content/careers.json
admin/sections/contact.php  the contact page editor    -> content/contact.json
lib/admin.php               the section registry, the icon rail, the page furniture
lib/store.php               reading and writing a JSON file, atomically
lib/html.php                escaping, and the rich-text sanitiser
```

Each section is at `/admin/?s=<name>`; `/admin/` itself is the overview. Adding
another editable page is a row in `ADMIN_SECTIONS` in `lib/admin.php` and a file
beside the others — the rail draws itself from that registry.

Section files refuse to run unless `T4T_ADMIN` is defined, so asking for one by
its own path gets a 403 however the server is configured. That is a backstop:
the real protection is that cPanel's Directory Privacy covers the whole
directory.

**The editor follows the page, not the other way round.** The page renders
straight from the JSON, so there is no second copy of the structure to keep in
step. Change the shape of a page and the model, the form and the renderer move
together — `check_content_model.py` fails the build if one of the three is left
behind, in either direction.

## The contact page

`pages/contact/index.php` renders from `content/contact.json`, and
`/admin/?s=contact` edits it. Rendered on the **server** for the same reason
the careers page is: a contact page whose addresses arrive by JavaScript is
indexed unreliably, and it is the page a search engine is most often asked for
by name.

```
pages/contact/index.php     the public page
content/contact.json        the data
lib/contact.php             the shape of the page, and its ContactPage schema
admin/sections/contact.php  the editor
tools/sync_site_contact.py  pushes the details into the footer and the schema
```

The editor is one form of several bands, each matching a band of the page, in
the order a visitor meets them — the banner, the copy around the enquiry form,
the "Reach Us Directly" rows, the offices, and the search/sharing text nobody
sees on the page itself. Add, remove and reorder submit the form **without
saving**, so nothing typed is lost on the way; only "Save the contact page"
writes.

A reach row holds a **list** of values, so three numbers can sit under one
"Phone" heading rather than as three rows each headed "Phone". One per line.
"Add a row" is still there for a number that deserves a heading of its own.

Office flags come from `/assets/images/flags/`. Dropping a `.jpg` or `.png` in
there makes it appear in the picker on the next reload; a matching `.webp`
beside it is used automatically when one exists, and the `width`/`height` are
read from the file itself, so a flag added by hand needs nothing typed in.

### The footer says the same things, and cannot be edited

The email address, the phone numbers, the addresses and the opening hours also
appear in **the footer of every page**, and in the `address` and `contactPoint`
arrays of each page's base Organization structured data. This project forbids
runtime partials, so those are literal markup in sixteen files — nothing on the
server can update them from a file.

Rather than let that go unnoticed, `contact_fingerprint()` digests exactly the
facts that appear in both places, `sync_site_contact.py` stores that digest as
`footer_synced` when it rebuilds them, and the editor compares the two and says
plainly when they have parted.

To bring them back into line:

```bash
# 1. the server's copy is the real one
#    (download content/contact.json from the host first)
python3 tools/sync_site_contact.py
python3 tools/check_shared_markup.py
python3 tools/audit_pages.py
# 2. upload the pages — and NOT content/, which the host owns
```

The digest is computed twice, once in PHP and once in Python, from a delimited
string rather than JSON — the two languages do not spell a JSON document the
same way, and PHP escapes the slash in `278/3` where Python does not.
`check_content_model.py` asserts the two implementations still agree, because
they have already drifted once.

## Job posts

`pages/careers/index.php` renders posts from `content/careers.json`, and
`/admin/?s=careers` edits that file.

Rendered on the **server**, not fetched in the browser: a careers page whose
listings arrive by JavaScript is one search engines index unreliably, and an
unindexed job post is an unfilled role. Each open post also emits a
`JobPosting` block, which is what puts it into Google Jobs rather than only
into ordinary results.

```
pages/careers/index.php     the public page
content/careers.json        the data — posts, and the speculative CV form link
lib/careers.php             the shape of a job post, and its JobPosting schema
admin/sections/careers.php  the editor
```

`lib/` and `content/` are both blocked over HTTP by `.htaccess`.

### Formatting

The body fields carry formatted text — bold, italic, underline, bulleted and
numbered lists, links, and alignment. `assets/js/editor.js` provides the
toolbar; the fields are plain `<textarea>` elements holding HTML, so the form
still saves if it never loads.

**Alignment is a class, never an inline style.** The CSP is `style-src 'self'`,
so a `style="text-align:center"` written by `document.execCommand` would look
right in the editor and do nothing at all on the published page. The editor
writes `ta-left` / `ta-center` / `ta-right` / `ta-justify`, which
`careers.css` styles and `RT_ALLOWED_CLASSES` in `lib/html.php` permits. Those
three lists have to stay in step.

**Everything is re-sanitised on save.** `rt_sanitise_html()` in `lib/html.php`
rebuilds the markup from an allow-list rather than filtering what it is given,
so the stored HTML cannot contain a construct that function does not know how
to write. That matters because the careers page and the contact page print it
unescaped — the only places on the site that print anything unescaped. The editor's own restrictions are a
convenience for whoever is typing and are bypassed by posting to the endpoint
directly; the PHP is the boundary.

It is hand-written because this host has no `dom` extension — `DOMDocument`
does not exist, the same way `mb_strlen` did not.

### Keeping the editor out of search results

Four things hold this together, and `audit_pages.py` asserts all of them:

- **Nothing on the site links to it.** No link, no crawl path.
- **It is absent from `sitemap.xml`.**
- **It is absent from `robots.txt` — deliberately.** A `Disallow: /admin/` would
  advertise the path in a world-readable file that is the first thing a scanner
  fetches. Worse, a disallowed page is never crawled, so its `noindex` is never
  read, and a URL found any other way can still surface as a bare result.
  Silence is stronger than an advertised `Disallow`.
- **It marks itself `noindex`** in its own `<head>`, and `.htaccess` sets
  `X-Robots-Tag: noindex, nofollow, noarchive` for the whole path. That header
  uses `always`, so it is attached to the 401 as well — which is the response a
  crawler actually receives once Directory Privacy is on.

The real protection is the 401. Everything above matters for the window before
Directory Privacy is switched on, or if it is ever removed.

**Never add an `.htaccess` to `admin/` in this repo.** cPanel writes its own
there when you enable Directory Privacy; uploading one over it would remove the
password and leave the editor open, with nothing to report the change.

### Before the editor works on the host

1. **Protect it.** cPanel → *Directory Privacy* → `admin` → tick "Password
   protect this directory" and add a user. `admin_require_auth()` in
   `lib/admin.php` refuses to load if it cannot see that protection, so it
   cannot be left open by accident — but the refusal is a backstop, not the
   lock.
2. **Make the data files writable** by PHP. On cPanel, PHP runs as your own
   user, so `content/` needs no special permissions; if a save reports it could
   not write, that is the thing to check first.

### Deploying without destroying live posts

**Both files under `content/` are tracked in git, and the copies on the server
are the ones that matter.** They ship seeded with what the live site carries so
a fresh deploy is not blank — but once anyone has used the editor, the server's
copies are the real data and the repo's are stale.

So on any re-upload, **exclude `content/`**, or download the live files first
and put them back afterwards. Overwriting them silently reverts every change
made since launch, and nothing will report an error.

The editor keeps one generation of backup beside each file —
`content/careers.json.bak`, `content/contact.json.bak` — which is the fastest
way back from a bad edit.

`tools/templates/` holds the canonical shared markup. See the README in that
directory.
