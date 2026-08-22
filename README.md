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
│   ├── careers/index.php            renders content/careers.json
│   ├── contact/index.php            renders content/contact.json
│   ├── resource-certifications/index.html
│   ├── branding-and-advertisement/index.html
│   └── privacy-policy/index.html
├── admin/                           the editors — its own sign-in
│   ├── index.php                    the shell and router
│   ├── login.php                    password, then an authenticator code
│   ├── logout.php                   POST only, with a token
│   ├── forgot.php  reset.php        recovery by a code emailed to the account
│   ├── setup.php                    creates the first account, once
│   └── sections/                    one file per editable page
├── lib/                             server-side helpers — never served
├── content/                         the edited data — the HOST's copy is real
├── assets/
│   ├── css/      base, theme, layout, components, animations, admin, pages/
│   ├── js/       theme-init, theme-toggle, nav, animations, forms, main
│   ├── fonts/    self-hosted Inter (latin, latin-ext)
│   ├── icons/    master SVG sprite
│   └── images/   logo, favicon, og, tech, clients, photos, sections, flags
├── contact-handler.php              the enquiry form's endpoint
├── tools/                           build and audit scripts — NOT deployed
├── .htaccess                        security headers, caching, clean URLs
├── robots.txt
├── sitemap.xml
└── site.webmanifest
```

Every page except the homepage lives at `/pages/[name]/index.*`, so it is
served at `/pages/[name]/` with no extension in the address bar.

Two of them are `.php` rather than `.html`, and only two: the careers page and
the contact page say things that change without a redeploy, so they render from
`content/*.json` and are edited at `/admin/`. Everything else is a flat file.
`lib/`, `content/` and `tools/` are blocked over HTTP by `.htaccess`.
See `tools/README.md` for the editors, and for the one thing they cannot
reach — the contact details repeated in every page's footer.

### The private store, which is not in this repository

Password hashes, the master key they are peppered with, the authenticator
secrets, sessions, the attempt counters and the audit log live in a directory
**beside** the document root, not inside it:

```
/home/USER/
├── public_html/          ← the document root, everything above
└── t4t-private/          ← never served, never committed, never deployed
    ├── secret.key            32 bytes; every other key derives from it
    ├── admins.json           accounts: argon2id hashes, TOTP secrets
    ├── sessions/             session.save_path
    ├── throttle.json         failed-attempt counters
    ├── resets.json           pending reset codes, stored hashed
    └── audit.log             every sign-in, successful or not
```

`.htaccess` blocking a directory is a rule the server chooses to apply. A
directory outside the document root has no URL at all, which is a stronger
thing, and `lib/private.php` refuses to start if it finds itself anywhere
inside the web root. Set `T4T_PRIVATE` if it needs to live elsewhere.

Locally it lands in `../t4t-private`, beside the repository — the same shape,
so nothing about the layout is different in development.

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

1. Confirm HTTPS works, then uncomment the HSTS header in `.htaccess`. The
   HTTPS redirect is already active. HSTS matters more now than it did: the
   admin sets a session cookie, and a cookie that travels once over plain http
   is a cookie that has been seen.
2. Upload `tools/host-probe.php` to `public_html/` by hand, set its token, load
   it once, read the report, and **delete it**. It says whether `mail()` works,
   whether argon2id is available, and whether the private store is in the right
   place — all things that fail quietly rather than loudly.
3. Set up the admin account. See **Turning the admin on**, below.
4. Submit `sitemap.xml` in Google Search Console and request indexing.
5. Verify `/pages/about/` resolves without `.html`, and that a bad URL renders
   `404.html`.

### Turning the admin on

Do this in order. The point of the order is that the window in which the admin
could be created by somebody else never opens.

1. Deploy, with cPanel **Directory Privacy** still switched on for `/admin` if
   it already is. It is no longer required, but there is no reason to remove it
   before the replacement is proven.
2. Read the setup key off the server:
   `cat ~/t4t-private/setup-token.txt` over SSH, or open that file in cPanel's
   File Manager. It is created the first time `/admin/setup.php` is loaded.
3. Open `https://tech4time.bd/admin/setup.php`, paste the key, and create the
   account. Pair an authenticator app and **save the ten recovery codes** —
   they are shown once.
4. Sign out and back in. Change the password once. Confirm other sessions end.
5. Run a full password recovery: *I have forgotten my password* → check the
   code arrives → set a new password. This is the step worth not skipping,
   because it is the one you will need on the day you cannot sign in.
6. Only now remove Directory Privacy, if it was on.

The setup page refuses to run once an account exists, and deletes the setup key
at the same moment.

**If email ever fails and you are locked out**, a recovery code signs you in.
If those are gone too, reset the password from the server itself. Upload
`tools/admin-cli.php` to your home directory — the level *above* `public_html`,
so it is never reachable over HTTP — then:

```
php ~/admin-cli.php list        # what accounts exist
php ~/admin-cli.php passwd      # set a new password
php ~/admin-cli.php unlock      # clear a lockout
php ~/admin-cli.php codes       # issue new recovery codes
```

Delete it when you are done. It asks for no password, because it does not need
one: anyone who can run a command on that server can already read the accounts
file. That is what makes it the floor under every other way back in.

`admin@tech4time.bd` must exist as a real mailbox in cPanel and must be one you
can open — a reset code goes there and nowhere else.

### Cache busting

Asset filenames are not content-hashed, because there is no build step to
generate hashes. `.htaccess` caches CSS, JS and fonts for a year, so a changed
`base.css` will not reach returning visitors on its own. When you edit one,
either append a version query to the `<link>`/`<script>` tags
(`base.css?v=2`) or lower the `max-age` for that file type.

---

## Contact form

The site stays static; the enquiry form posts to one small PHP handler,
`contact-handler.php`, which runs on cPanel with no dependencies. It validates
server-side, uses a honeypot, and mails to `info@tech4time.bd`. Client-side
validation in `assets/js/forms.js` is a convenience only — never the security
boundary.

Job applications do not post here at all: each role links out to its own Google
Form, and the link is set per role in the editor.

`mail()` cannot be tested locally; verify the forms on the live host before
launch.
