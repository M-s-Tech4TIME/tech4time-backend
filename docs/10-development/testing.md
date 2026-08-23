# Testing

**Applies to:** both

What each check proves, when to run it, and how to read a failure.

There is no test runner and no config file. Every check is a Python script you run directly, exits
non-zero on failure, and prints what failed rather than that something did.

---

## Before every commit

Fast, no browser needed. Run all seven.

```bash
python3 tools/check_contrast.py        # WCAG AA in both colour modes
python3 tools/inject_icons.py --check  # every page's inlined icon block is current
python3 tools/check_shared_markup.py   # no page's header or footer has drifted
python3 tools/check_content_model.py   # model, form and renderer still agree
python3 tools/check_secrets.py         # nothing secret committed; no protection removed
python3 tools/check_docs.py            # the docs still describe the code
python3 tools/audit_pages.py           # SEO, accessibility, structure, internal links
```

## When you touched the admin, the auth, or the contact handler

```bash
python3 tools/test_admin_auth.py       # the whole sign-in cycle, over HTTP
python3 tools/test_contact_handler.py  # the enquiry form's endpoint
python3 tools/test_careers_admin.py    # the job post editor
python3 tools/test_contact_admin.py    # the contact page editor
```

## When you touched CSS, markup or motion

Needs Firefox and geckodriver. Slower.

```bash
python3 tools/test_motion.py           # every page, scrolled end to end
python3 tools/test_nav.py              # navigation at both widths
python3 tools/test_theme.py            # the theme switch, with a real OS preference
python3 tools/test_editor.py           # the job post editor, in a real browser
python3 tools/check_hover.py           # a real pointer over every kind of control
python3 tools/check_dark_mode.py       # every page as the browser actually paints it
```

> Interrupted browser runs leave processes behind. `pkill firefox geckodriver` clears them.

---

## What each one actually proves

### The static checks

| Script | Proves |
|---|---|
| `check_contrast.py` | every text/background pair in `theme.css` meets WCAG AA, in both modes, including the 3:1 bar for component boundaries |
| `inject_icons.py --check` | each page inlines exactly the icon symbols it references — no missing symbol, no dead weight |
| `check_shared_markup.py` | every page's header, footer and script block is byte-identical to `tools/templates/` |
| `check_content_model.py` | the model, the editor form and the page renderer describe the same fields — **in both directions**, so a field dropped from the page but left in the form is caught |
| `check_secrets.py` | no secret is committed; the private store still refuses the web root; no auth bypass constant has returned; cookie flags intact; no password reachable by the audit log; every admin page shape noindexed |
| `check_docs.py` | every tool, library and admin section is documented; no doc cites a path that does not exist; no internal link is broken; no doc quotes a constant that has changed |
| `audit_pages.py` | per page: title and meta description, heading order, `alt` text, landmark roles, canonical URL, structured data, internal links resolve |

### The HTTP tests

These start a real PHP server on a spare port and drive it over HTTP.

| Script | Proves |
|---|---|
| `test_admin_auth.py` | first-run setup; **the setup key demanded of a request from off the machine**; signing in and out; a code works once; the lockout; the emailed reset cycle; recovery codes; the audit log; the refusal to run unsafely. Includes the RFC 6238 test vectors, so the TOTP implementation is checked against the specification rather than against itself |
| `test_contact_handler.py` | method check, honeypot, every validation rule, CR/LF injection into each field, the assembled message, non-ASCII round trips, the rate limit, and the no-JavaScript HTML response |
| `test_careers_admin.py` | the job post editor: add, edit, reorder, delete, validation, CSRF, the atomic write |
| `test_contact_admin.py` | the contact page editor, and the icon rail |

`test_contact_handler.py` captures outgoing mail by pointing PHP's `sendmail_path` at a script that
writes the message to a file, then reads back the exact bytes `mail()` was asked to send. That is
what lets it assert the header-injection defences work rather than merely look right. **It does not
test delivery**, and cannot — that needs a real mail server, and is proven on the host with
`tools/host-probe.php`.

The admin tests sign in for real, through `tools/admin_session.py`, against a throwaway account in a
private directory under `/tmp` — so a test run cannot disturb your own local account.

### The browser tests

| Script | Proves |
|---|---|
| `test_motion.py` | every page scrolled end to end, with every reveal-marked element required to finish opaque — the reveal never leaves anything unread |
| `test_nav.py` | navigation is usable at desktop and mobile widths, with a keyboard as well as a pointer |
| `test_theme.py` | the theme switch honours an explicit choice, falls back to the OS preference, and survives a reload without a flash |
| `test_editor.py` | the job post editor driven as a person drives it, including a real sign-in |
| `check_hover.py` | every interactive element visibly responds to a real pointer |
| `check_dark_mode.py` | every page in both themes, as painted — catching what a CSS reader cannot, like a token that resolves to the same colour as its background |

They skip with a notice and exit 0 when Firefox or geckodriver is missing, rather than failing —
so a machine without a browser can still run everything else.

---

## Reading a failure

**`check_shared_markup.py` fails** — someone edited a header or footer in a page instead of in
`tools/templates/`. Fix the template, then `python3 tools/propagate_shared.py`.

**`check_content_model.py` fails** — you changed a content shape in one of the three places it
lives. The message names the field and which layer is missing it.
[content-model.md](backend/content-model.md)

**`check_docs.py` fails** — the code and the documentation disagree. Usually you added a file and
have not documented it, or changed a constant the prose quotes. The message names both sides.

**`check_secrets.py` fails** — read the message carefully before "fixing" it. It only fails for
things that would silently weaken the admin, and every one of its checks was verified against a
deliberate breakage before being trusted.

**`test_admin_auth.py` hangs or fails oddly** — most often a leaked PHP server from a previous run
holding the port, or a stale `/tmp` private directory. It uses a fresh one each run; if in doubt,
`pkill -f 'php -S'`.

**A browser test fails only sometimes** — check for leaked `geckodriver` processes first. That is
the usual cause of a flake here.

---

## Adding a test

Match the existing shape. Each script is standalone, starts what it needs, cleans up after itself,
prints one line per check, and exits non-zero if any failed. Do not add a framework — there is no
build step, and a dependency is a thing that has to be resurrected before a typo can be fixed.

If your change makes a protection that could be removed without anything failing, add the check to
`check_secrets.py` — and **prove the check works by deliberately breaking the thing it guards**
before you trust it.
