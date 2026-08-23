# Tech4TIME website

Static site for a Bangladeshi IT company: sixteen pages, a contact form, a job board, and an admin
panel for the two pages whose content changes. Deploys to **cPanel shared hosting by uploading
files** — that constraint explains most of what follows.

**Full documentation is in [docs/](docs/).** Start at [docs/README.md](docs/README.md), which routes
by intent. New to the project: [docs/00-orientation/README.md](docs/00-orientation/README.md), then
[docs/10-development/setup.md](docs/10-development/setup.md).

---

## Rules that must not be broken

Each has a reason, recorded in [docs/90-decisions/](docs/90-decisions/). If one seems wrong, read
its record before acting.

1. **No build step, no framework, no bundler, no package manager.** The files here are the files
   that run on the server.
2. **No CDN and no external origin.** Everything is self-hosted.
3. **No inline styles or scripts.** The CSP is `style-src 'self'; script-src 'self'` — a `style=`
   attribute, a `<style>` block or an `onclick` will be refused by the browser.
4. **Every page must work with JavaScript off.** Progressive enhancement is a hard rule. Motion may
   decorate; it may never be the only route to anything.
5. **Content renders on the server.** No runtime `fetch()` for content, ever — including for the
   header and footer.
6. **Never commit anything from the private store** (`t4t-private/`, `*.key`, `admins.json`).
7. **Never overwrite `content/` on a live server.** The host's copy is the real data — live job
   posts and contact details.
8. **Never add an `.htaccess` to `admin/`.** cPanel writes its own there.
9. **`tools/` is never deployed.**
10. **Never edit a header or footer in a page file.** Edit `tools/templates/`, then
    `python3 tools/propagate_shared.py`.

---

## Where things are

| | |
|---|---|
| `pages/` `index.html` | the sixteen pages — two are `.php` and render from `content/` |
| `assets/` | css, js, fonts, icons, images — all self-hosted |
| `lib/` | server-side PHP: rendering, content, and the whole sign-in |
| `content/` | the JSON the two dynamic pages render from |
| `admin/` | the editor, behind its own sign-in |
| `tools/` | 33 build, audit and test scripts — never deployed |
| `docs/` | the documentation |
| `../t4t-private/` | **outside the repo** — hashes, keys, sessions. Never committed |

---

## Where to change what

Full table: [docs/10-development/where-to-change-things.md](docs/10-development/where-to-change-things.md)

| Change | Where |
|---|---|
| A colour | `assets/css/theme.css` — tokens only, never a hex elsewhere |
| Layout, components | `assets/css/layout.css`, `components.css` |
| Browser behaviour | `assets/js/` — modules register on `window.Tech4Time` |
| Header / footer | `tools/templates/` → `propagate_shared.py` |
| An icon | the markup, then `python3 tools/inject_icons.py` |
| A job post or contact detail | **`/admin/`** — not a file |
| The shape of editable content | `lib/careers.php` or `lib/contact.php` — **and the form and the renderer with it** |
| The sign-in, sessions, hashing | `lib/auth.php` — read [authentication.md](docs/10-development/backend/authentication.md) first |
| Make a page editable | [adding-an-editor.md](docs/10-development/backend/adding-an-editor.md) |
| Add a page | [adding-a-page.md](docs/10-development/frontend/adding-a-page.md) |
| Headers, caching, blocking | `.htaccess` — not read by the local dev server |

---

## Running it

```bash
python3 tools/serve.py          # http://localhost:8000  — NOT python3 -m http.server
```

Four things need PHP: the careers page, the contact page, the admin and the contact handler. The
admin sign-in is real locally too — `/admin/setup.php` once, then `/admin/login.php`.

[docs/10-development/running-locally.md](docs/10-development/running-locally.md)

---

## Before committing

```bash
python3 tools/check_contrast.py        python3 tools/check_content_model.py
python3 tools/inject_icons.py --check  python3 tools/check_secrets.py
python3 tools/check_shared_markup.py   python3 tools/check_docs.py
python3 tools/audit_pages.py
```

Touched the admin, auth or the contact handler? Also `test_admin_auth.py`,
`test_contact_handler.py`, `test_careers_admin.py`, `test_contact_admin.py`.

Touched `lib/store.php`? Also `test_store.py`.

Touched CSS, markup or motion? Also `test_motion.py`, `test_nav.py`, `test_theme.py`,
`check_hover.py`, `check_dark_mode.py`, `check_responsive.py`, `check_focus.py` — these need
Firefox and geckodriver,
and leave processes behind if interrupted (`pkill firefox geckodriver`).

[docs/10-development/testing.md](docs/10-development/testing.md)

---

## Keep the documentation true

**Change the code, update the doc that owns it, in the same commit.** The ownership table is in
[docs/README.md](docs/README.md#which-doc-owns-what).

`python3 tools/check_docs.py` catches the mechanical half — an undocumented tool, library or admin
section, a dead link, a cited path that no longer exists, or a constant the prose quotes that has
changed. It cannot read prose; that part is on you.

New failure mode discovered? Add it to
[docs/30-operations/troubleshooting.md](docs/30-operations/troubleshooting.md). New decision that
constrains future work? A record in [docs/90-decisions/](docs/90-decisions/).

---

## Status

Work happens on `dev`; pull requests to `main` need explicit approval.

**Live** at `https://tech4time.bd` — cPanel, LiteSpeed, PHP 8.2.33, admin signed in and working.
**A push to `main` deploys it.** Checks run, rsync over SSH, and the site is asked afterwards
whether `lib/`, `content/` and dotted paths still answer 403 —
[ci-cd.md](docs/20-deployment/ci-cd.md). Never sync `content/`; it is seeded with
`--ignore-existing` and the host's copy always wins.

**Not done, and not missing:** two of sixteen pages are editable; the repository has not been split
into frontend and backend yet; field-measured LCP/CLS/INP against the live host is outstanding.
