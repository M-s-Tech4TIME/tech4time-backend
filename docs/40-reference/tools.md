# Tools

**Applies to:** both

Every script in `tools/`. **None of them is deployed** — `.htaccess` blocks `/tools/` as a backstop,
but the real rule is that the directory never gets uploaded.

Two exceptions are uploaded by hand, run, and deleted: `host-probe.php` and `admin-cli.php`.

Everything is Python 3 standard library, apart from the asset builders, which need **Pillow**. The
browser tests speak to geckodriver over its wire protocol — there is no Selenium.

---

## Running the site

| Script | Does |
|---|---|
| `serve.py` | Preview the whole site locally, including the PHP parts. `python3 tools/serve.py [port]` |
| `dev-router.php` | Router for the local preview server — resolves clean URLs the way `.htaccess` does |

---

## Checks — run these before committing

| Script | Proves |
|---|---|
| `check_contrast.py` | the palette meets WCAG 2.1 AA in both modes |
| `check_shared_markup.py` | the header, footer and script blocks have not drifted between pages |
| `check_content_model.py` | the editor, the data and the page still describe the same thing, and no editor is unchecked |
| `check_secrets.py` | nothing protecting the admin has quietly stopped protecting it |
| `check_docs.py` | the documentation still describes the code |
| `audit_pages.py` | every page: SEO, accessibility and structural correctness |
| `inject_icons.py --check` | every page's inlined icon block is current |
| `build_deploy_set.py --check` | the set of files bound for the server holds nothing it must not, and nothing is missing |
| `verify_live.py <url>` | a deployed site still returns 403 for `lib/`, `content/` and dotted paths, and still carries its headers |

## Checks that need a browser

| Script | Proves |
|---|---|
| `check_dark_mode.py` | every page as the browser actually paints it, in both themes |
| `check_hover.py` | every kind of interactive element visibly responds to a real pointer |
| `check_responsive.py` | no page scrolls sideways, no control is wider than the screen, and no tap target is under 24px, at seven widths from 320px up |
| `check_focus.py` | tabbing every page: the focus ring can be seen, and nothing covers it |

Both skip with a notice and exit 0 when Firefox or geckodriver is missing.

---

## Tests

### Over HTTP, against a real PHP server

| Script | Exercises |
|---|---|
| `test_admin_auth.py` | the admin's whole sign-in cycle, including the setup key as a remote request sees it and the RFC 6238 test vectors |
| `test_contact_handler.py` | `contact-handler.php`, including header injection and the captured message |
| `test_careers_admin.py` | the job post editor, and every field of a post reaching the page |
| `test_contact_admin.py` | the contact page editor |
| `test_store.py` | `lib/store.php`: reading, writing, and the rule that a damaged file never becomes the backup |
| `admin_session.py` | *(not run directly)* gives a test an admin account and signs it in |

### In a real browser

| Script | Proves |
|---|---|
| `test_motion.py` | the scroll reveal never leaves anything unread |
| `test_nav.py` | the navigation is usable at both widths |
| `test_theme.py` | the theme switch behaves, with a real OS preference |
| `test_editor.py` | the job post editor, driven as a person drives it |

---

## Markup

| Script | Does |
|---|---|
| `assemble_page.py` | Assemble a page from the shared templates plus a per-page `<main>` block. **For creating a page, not maintaining one** |
| `propagate_shared.py` | Push a change in `tools/templates/` out to every page |
| `inject_icons.py` | Inline each page's icon subset from the master sprite |
| `apply_reveals.py` | Mark up the scroll-reveal targets on every page, from one structural rule |
| `sync_site_contact.py` | Push the contact details out of `content/contact.json` into every page's footer |
| `htmltree.py` | *(a library)* a minimal HTML tree with source offsets, for tools that edit markup structurally |

---

## Asset generation

Run rarely — usually only when the source artwork changes. **These need Pillow.**

| Script | Does |
|---|---|
| `build_icon_sprite.py` | Build the self-hosted SVG icon sprite from Font Awesome Free metadata |
| `build_images.py` | Copy, rename and optimise the site's content images |
| `build_logos.py` | Normalise the master logo artwork into the web asset set |
| `build_favicons.py` | Generate the favicon set from the 512px master |
| `build_og_image.py` | Build the 1200×630 Open Graph / Twitter Card share image |
| `fetch_fonts.py` | Fetch and self-host the Inter variable font (latin + latin-ext) |
| `stage_live_images.py` | Copy the live site's imagery into `tools/masters/` under readable names |

Sources live in `tools/masters/`.

---

## Looking at things

| Script | Does |
|---|---|
| `shoot_pages.py` | Photograph pages in headless Firefox, into `tools/shots/` (gitignored) |

---

## Host tools — upload, run, delete

### `host-probe.php`

Answers the questions that can only be answered on the server, and that all fail quietly:

- the PHP version
- whether argon2id is available, and how long a hash takes
- where the private store resolves to, and **whether it is outside the web root**
- whether `mail()` works — it sends a real test message

```
1. upload to public_html/ by hand
2. set PROBE_TOKEN as its header instructs
3. load it once, read the report
4. DELETE IT
```

It refuses to run until the token is changed, and its recipient is hard-coded so it cannot be
pointed anywhere else.

### `admin-cli.php`

The floor under every way into the admin. Upload to your **home** directory — above `public_html`,
so it is never reachable over HTTP — run over SSH, then delete.

```bash
php ~/admin-cli.php list          # what accounts exist
php ~/admin-cli.php passwd        # set a new password; ends every session
php ~/admin-cli.php codes         # issue ten new recovery codes
php ~/admin-cli.php totp-clear    # unpair the authenticator
php ~/admin-cli.php unlock        # clear a lockout
php ~/admin-cli.php log 25        # the audit log
php ~/admin-cli.php where         # which files it is working on
```

It asks for no password because it does not need one: anyone who can run a command on that server
can already read the accounts file. That is what makes it a floor and not a hole. It also returns a
404 if reached over HTTP.

[secrets-recovery.md](../30-operations/secrets-recovery.md)

---

## Directories

| | |
|---|---|
| `tools/templates/` | the canonical header, footer, head and script markup |
| `tools/masters/` | source artwork for the asset builders |
| `tools/shots/` | screenshot output, gitignored |

---

## Adding a tool

A docstring saying **what it proves and how to run it**, standard library only (or Pillow), exits
non-zero on failure, prints what failed rather than that something did, and cleans up after itself.

Then add it to this page — `check_docs.py` fails until you do.
