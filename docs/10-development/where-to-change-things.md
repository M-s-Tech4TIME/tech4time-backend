# Where to change things

**Applies to:** both

"I want to change X — which file do I open?" This page answers that and nothing else. If you read
one page in this documentation, make it this one.

**The general rule:** if it is *content*, it is edited in `/admin/` and you should not touch a file.
If it is *design or behaviour*, it is in `assets/`. If it is *structure*, it is in `pages/` or
`tools/templates/`. If it is *rules*, it is in `lib/` or `.htaccess`.

---

## Content — do not edit files for these

| I want to change | Where |
|---|---|
| A job post — add, edit, remove, reorder | `/admin/?s=careers` |
| The CV / application form link | `/admin/?s=careers` |
| An office address, phone number, email | `/admin/?s=contact` |
| The contact page's headings and copy | `/admin/?s=contact` |
| What the enquiry form says | `/admin/?s=contact` |

These write `content/careers.json` and `content/contact.json`.
[content-runbook.md](../30-operations/content-runbook.md) ·
[content-schemas.md](../40-reference/content-schemas.md)

> **The footer is the exception.** The contact details repeated in every page's footer are *markup*,
> not content, so the editor cannot reach them. After changing them in the admin, run
> `python3 tools/sync_site_contact.py` to push them out to all sixteen pages, then redeploy.
> The admin shows a banner when the two have drifted.

---

## Look and feel

| I want to change | Where |
|---|---|
| **A colour** | `assets/css/theme.css` — the tokens. Never a hex value in a component file. |
| Light/dark behaviour | `assets/css/theme.css` (`data-theme`) and `assets/js/theme-init.js` |
| Typography, the type scale | `assets/css/base.css` |
| The font itself | `assets/fonts/` + the `@font-face` in `base.css`; refetch with `tools/fetch_fonts.py` |
| Spacing, breakpoints | `assets/css/base.css` — the ladder is documented at the top (480 / 768 / 1024 / 1280 / 1440 / 1920) |
| Page scaffolding | `assets/css/layout.css` |
| Buttons, cards, forms, shared furniture | `assets/css/components.css` |
| One page only | `assets/css/pages/<name>.css` |
| The admin's appearance | `assets/css/admin.css` |

**Cascade order is fixed:** `base` → `theme` → `layout` → `components` → `animations` → `pages/*`.
[css.md](frontend/css.md)

---

## Behaviour in the browser

| I want to change | Where |
|---|---|
| Navigation, the mobile menu | `assets/js/nav.js` |
| The theme toggle | `assets/js/theme-toggle.js` |
| Scroll reveal | `assets/js/animations.js` + `assets/css/animations.css` |
| Which elements reveal | `tools/apply_reveals.py` — a structural rule, not hand-marked |
| Sliders on About / Company Profile | `assets/js/slider.js` |
| The homepage terminal | `assets/js/terminal.js` |
| The technology sphere | `assets/js/tech-sphere.js` |
| Counting figures, client logos | `assets/js/animations.js` |
| Contact form validation (convenience only) | `assets/js/forms.js` |
| Module wiring | `assets/js/main.js` |

Every module registers on `window.Tech4Time` and must degrade — the page has to work with scripting
off. [javascript.md](frontend/javascript.md) · [motion.md](frontend/motion.md)

---

## Structure and markup

| I want to change | Where |
|---|---|
| **The header or footer** | `tools/templates/`, then `python3 tools/propagate_shared.py` — **never one page** |
| A page's content | `pages/<name>/index.html` |
| The homepage | `index.html`, at the repository root |
| The 404 page | `404.html` |
| Add a whole new page | [adding-a-page.md](frontend/adding-a-page.md) |
| An icon on a page | edit the markup, then `python3 tools/inject_icons.py` |
| Add a new icon to the set | `assets/icons/sprite.svg` via `tools/build_icon_sprite.py`, then inject |
| Images | `tools/masters/`, then `python3 tools/build_images.py` |
| The favicon | `tools/build_favicons.py` |
| The social share card | `tools/build_og_image.py` |

[shared-markup.md](frontend/shared-markup.md) · [icons.md](frontend/icons.md)

---

## Server-side rules

| I want to change | Where |
|---|---|
| Who the contact form emails | `MAIL_TO` in `contact-handler.php` |
| The envelope sender for all mail | `MAIL_FROM_ADDRESS` in `lib/mailer.php` |
| Contact form validation | `contact-handler.php` — the server side is the real one |
| The contact form's rate limit | `contact-handler.php`, using `lib/throttle.php` |
| The shape of a job post | `lib/careers.php` — **and the form and the renderer with it** |
| The shape of the contact page | `lib/contact.php` — **and the form and the renderer with it** |
| What HTML is allowed in rich text | `lib/html.php` — the sanitiser |
| How JSON is read and written | `lib/store.php` |

> Changing a content shape means changing three files together — the model, the form and the
> renderer. Something fails the build if one is left behind: `check_content_model.py` for contact,
> `test_careers_admin.py` for careers, which posts a marker through every declared field and reads
> it back off the page. [content-model.md](backend/content-model.md)

---

## The admin and the sign-in

| I want to change | Where |
|---|---|
| **Add an editable page to the admin** | `ADMIN_SECTIONS` in `lib/admin.php` + a file in `admin/sections/` — [adding-an-editor.md](backend/adding-an-editor.md) |
| The icon rail | `ADMIN_SECTIONS`; new icons go in `ADMIN_ICONS`, same file |
| How long a session lasts | `AUTH_IDLE` (1 hour idle) and `AUTH_ABSOLUTE` (12 hours) in `lib/auth.php` |
| How many failures before a lockout | `AUTH_ALLOW` in `lib/auth.php`; the backoff is in `lib/throttle.php` |
| The longest lockout | `THROTTLE_MAX_BLOCK` in `lib/throttle.php` |
| How many recovery codes | `AUTH_RECOVERY` in `lib/auth.php` |
| Password rules | `auth_password_problem()` in `lib/auth.php` — currently 12 characters minimum |
| Password hashing cost | `AUTH_ARGON` / `AUTH_BCRYPT` in `lib/auth.php` |
| How long a reset code lives | `RESET_TTL` in `lib/reset.php` (10 minutes) |
| How often a reset may be asked for | `RESET_PER_ACCOUNT` / `RESET_PER_IP` / `RESET_GLOBAL` in `lib/reset.php` |
| Authenticator drift tolerance | `TOTP_DRIFT` in `lib/totp.php` |
| Where the private store lives | `T4T_PRIVATE`, or the default in `lib/private.php` |

> **These constants are quoted in the documentation.** `tools/check_docs.py` fails if you change one
> without updating the prose. [authentication.md](backend/authentication.md)

---

## Server configuration

| I want to change | Where |
|---|---|
| Security headers (CSP, X-Frame-Options…) | `.htaccess` section 1 |
| Caching | `.htaccess` section 6 |
| Clean URLs | `.htaccess` section 3 |
| What is blocked over HTTP | `.htaccess` section 8 |
| Keeping the admin out of search results | `.htaccess` section 9 |
| Enabling HSTS | `.htaccess` — uncomment, **after** the site is live on HTTPS |
| Crawl rules | `robots.txt` |
| The sitemap | `sitemap.xml` |

> `.htaccess` is not read by the local dev server. Changes there can only be verified on the host.
> [security-model.md](../40-reference/security-model.md)

---

## Deploying and operating

| I want to | Where |
|---|---|
| Deploy for the first time | [first-deploy.md](../20-deployment/first-deploy.md) |
| Push an update | [routine-deploys.md](../20-deployment/routine-deploys.md) |
| Turn on the admin sign-in | [admin-activation.md](../20-deployment/admin-activation.md) |
| Recover a lost password or secret | [secrets-recovery.md](../30-operations/secrets-recovery.md) |
| Diagnose something broken | [troubleshooting.md](../30-operations/troubleshooting.md) |

---

## Things you should not change without reading first

| | Read this first |
|---|---|
| Anything in `lib/private.php` | [security-model.md](../40-reference/security-model.md) |
| Anything in `lib/auth.php` | [authentication.md](backend/authentication.md) |
| The `.htaccess` blocking rules | [security-model.md](../40-reference/security-model.md) |
| A page's header or footer, directly | [shared-markup.md](frontend/shared-markup.md) |
| `content/*.json` on a live server | [routine-deploys.md](../20-deployment/routine-deploys.md) |
| Adding an `.htaccess` to `admin/` | Don't. [conventions.md](../00-orientation/conventions.md) |
