# Repository map

**Applies to:** both

Every directory: what it holds, who owns it, and what must never happen to it.

---

## Top level

```
tech4time-website/
├── index.html              the homepage — stays at the root, not in pages/
├── 404.html                the custom error page
├── contact-handler.php     where the enquiry form posts
├── .htaccess               security headers, caching, clean URLs, blocking
├── robots.txt              crawl rules
├── sitemap.xml             submitted to Search Console
├── site.webmanifest        PWA manifest (the one .json that stays public)
├── .gitattributes          line endings and diff behaviour
├── .gitignore              includes the private store, as a backstop
│
├── pages/                  the other fifteen pages
├── assets/                 css, js, fonts, icons, images — all self-hosted
├── lib/                    server-side PHP
├── content/                the JSON the dynamic pages render from
├── admin/                  the editor UI
├── tools/                  build, audit and test scripts — NEVER DEPLOYED
├── docs/                   this documentation
└── references/             notes kept from the original design work
```

---

## `pages/` — the website

Every page lives at `pages/<name>/index.*` and is served at `/pages/<name>/` with no extension,
because `.htaccess` resolves it. The homepage is the exception: it stays at the root.

| Page | File | |
|---|---|---|
| Home | `index.html` | at the repository root |
| About | `pages/about/index.html` | |
| Services hub | `pages/services/index.html` | |
| — Cybersecurity | `pages/services/cybersecurity/index.html` | |
| — Software development | `pages/services/software-development/index.html` | |
| — Cloud infrastructure | `pages/services/cloud-infrastructure/index.html` | |
| — HR solutions | `pages/services/hr-solutions/index.html` | |
| — IT consultancy & training | `pages/services/it-consultancy-training/index.html` | |
| — IT equipment supply | `pages/services/it-equipment-supply/index.html` | |
| Company profile | `pages/company-profile/index.html` | |
| Careers | `pages/careers/index.php` | **dynamic** — renders `content/careers.json` |
| Contact | `pages/contact/index.php` | **dynamic** — renders `content/contact.json` |
| Resource certifications | `pages/resource-certifications/index.html` | |
| Branding & advertisement | `pages/branding-and-advertisement/index.html` | |
| Privacy policy | `pages/privacy-policy/index.html` | |
| Not found | `404.html` | at the repository root |

Fourteen static, two dynamic. Adding one: [adding-a-page.md](../10-development/frontend/adding-a-page.md).

**Every page carries its own copy of the header and footer**, because runtime `fetch()` partials are
forbidden. `tools/templates/` holds the canonical copies and `check_shared_markup.py` proves no page
has drifted. Never hand-edit a header in one page — see
[shared-markup.md](../10-development/frontend/shared-markup.md).

---

## `assets/` — everything the browser loads

```
assets/
├── css/            17 files
│   ├── base.css            reset, type scale, the breakpoint ladder (documented at the top)
│   ├── theme.css           the colour tokens, and the light/dark switch
│   ├── layout.css          page scaffolding
│   ├── components.css      buttons, cards, forms, the shared furniture
│   ├── animations.css      keyframes and reveal states
│   ├── admin.css           the editor UI — loaded only under /admin/
│   └── pages/              one optional file per page
├── js/             13 files — see frontend/javascript.md
├── fonts/          Inter, self-hosted, two subsets (latin, latin-ext)
├── icons/          sprite.svg — the master icon set
└── images/         170 files: logo, favicon, og, tech, clients, photos, sections, flags, branding
```

**Cascade order matters** and is fixed: `base` → `theme` → `layout` → `components` → `animations` →
optional `pages/<name>.css`. See [css.md](../10-development/frontend/css.md).

**`assets/icons/sprite.svg` is a master, not a runtime asset.** Pages inline the symbols they use;
they do not link to this file. The reason is in [icons.md](../10-development/frontend/icons.md).

---

## `lib/` — server-side PHP

Never reachable over HTTP: `.htaccess` has `RewriteRule ^lib/ - [F,L]`.

| File | Owns |
|---|---|
| `html.php` | escaping, and the rich-text sanitiser |
| `store.php` | reading and writing a JSON file atomically, with a lock |
| `careers.php` | the shape of a job post, and its JobPosting schema |
| `contact.php` | the shape of the contact page, and its ContactPage schema |
| `admin.php` | the section registry, the icon rail, the page furniture |
| `auth.php` | accounts, hashing, sessions, the audit log |
| `private.php` | where the secrets are, and the keys derived from them |
| `totp.php` | RFC 6238, hand-written, checked against its published vectors |
| `reset.php` | the emailed one-time code |
| `throttle.php` | counting attempts, so guessing costs something |
| `mailer.php` | the one place mail leaves this site |

Detail on each: [libraries.md](../10-development/backend/libraries.md).

---

## `content/` — the data

```
content/
├── careers.json     job posts and the CV form link
└── contact.json     offices, phone numbers, the enquiry form's copy
```

**On the host, this directory is the real data and the repository's copy is not.** It is written by
people using `/admin/`, not by developers. A deploy that overwrites it destroys live job posts.

> **Rule:** never upload `content/` to a server that already has one.
> See [routine-deploys.md](../20-deployment/routine-deploys.md).

Field-by-field: [content-schemas.md](../40-reference/content-schemas.md).

---

## `admin/` — the editor

```
admin/
├── index.php               the shell and the router; sections load through here
├── login.php               password, then six digits from an authenticator app
├── logout.php              POST only, with a token
├── forgot.php              asks for a reset code
├── reset.php               the code, then the app, then the new password
├── setup.php               creates the first account; refuses ever after
└── sections/
    ├── overview.php        what can be edited, and plainly what cannot
    ├── careers.php         the job post editor      → content/careers.json
    ├── contact.php         the contact page editor  → content/contact.json
    └── account.php         password, second factor, recovery codes, the log
```

The rail draws itself from `ADMIN_SECTIONS` in `lib/admin.php`:

| URL | Section | Edits |
|---|---|---|
| `/admin/` | `overview` | nothing — it says what can and cannot be changed |
| `/admin/?s=careers` | `careers` | `content/careers.json` |
| `/admin/?s=contact` | `contact` | `content/contact.json` |
| `/admin/?s=account` | `account` | your own password, second factor and recovery codes |

`ADMIN_PAGE_SECTIONS` names the subset that edits a page of the website — `careers` and `contact` —
so anything counting "the pages you can edit" asks there rather than filtering the registry by hand. Section files refuse to run unless `T4T_ADMIN` is defined, so requesting one by its
own path gets a 403 however the server is configured — a backstop, not the lock. The lock is the
sign-in.

> **Rule:** never add an `.htaccess` file to `admin/` in this repository. cPanel writes its own
> there, and uploading over it removes whatever protection it was applying.

---

## `tools/` — never deployed

33 scripts: asset builders, markup propagators, auditors, and the test suite. Blocked over HTTP by
`RewriteRule ^tools/ - [F,L]` as a backstop, but the real rule is that they are not uploaded at all.

Two files there are exceptions, uploaded by hand and then deleted:

- `tools/host-probe.php` — answers what can only be answered on the host
- `tools/admin-cli.php` — the break-glass path when every way into the admin is shut

Full list: [tools.md](../40-reference/tools.md).

---

## Not in the repository at all

### The private store

`/home/USER/t4t-private/` on the host, `../t4t-private` beside your clone locally. Password hashes,
the master key, authenticator secrets, sessions, counters and the audit log.

Never committed, never deployed, never inside the document root. `.gitignore` lists it as a
backstop; `lib/private.php` refuses to start if it finds itself in the web root.

[security-model.md](../40-reference/security-model.md) ·
[secrets-recovery.md](../30-operations/secrets-recovery.md)

### Generated and ignored

| | |
|---|---|
| `tools/shots/` | screenshots from `shoot_pages.py`, regenerated on demand |
| `content/*.json.bak` | one generation of backup, written on every save |
| `__pycache__/`, `*.pyc` | Python bytecode |
| `Tech4TIME-Static-Website-Plan_v3.md` | the original working brief, kept locally |
