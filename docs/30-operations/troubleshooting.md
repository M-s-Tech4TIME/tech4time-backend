# Troubleshooting

**Applies to:** both

Indexed by what you actually see, not by what is actually wrong.

Cannot sign in to the admin? → [secrets-recovery.md](secrets-recovery.md).

---

## The website

### Every page is unstyled

**Assets are 404ing.** Either the upload lost `assets/`, or the directory structure was flattened.
Every asset path is root-relative (`/assets/…`) — the structure has to be preserved exactly.

Locally: you opened the file over `file://`. Use `python3 tools/serve.py`.

### `/pages/about/` gives a 404, but `/pages/about/index.html` works

**`.htaccess` is not being read**, or `mod_rewrite` is off. Clean URLs come from section 3 of that
file.

Check that `.htaccess` uploaded, and check `lib/` and `content/` — if those are readable over HTTP
too, that is the same cause and it is more urgent than the 404.

### A page shows PHP source

PHP is not executing. Locally: use `tools/serve.py`, not `python3 -m http.server`. On the host: the
domain has no PHP version selected in MultiPHP Manager.

### Changed CSS has not appeared for returning visitors

**Expected.** `.htaccess` caches CSS, JS and fonts for a year and filenames are not content-hashed.
Add a version query to the tag or lower `max-age` —
[routine-deploys.md](../20-deployment/routine-deploys.md#cache-busting).

### Content is invisible until you scroll, and never appears

The scroll reveal hid something and never revealed it. `animations.js` failed to load or threw.

There is a watchdog in `theme-init.js` that lifts the hidden state at the load event, so if you are
seeing this, the watchdog did not run either — check the browser console for an error in
`theme-init.js` itself.

```bash
python3 tools/test_motion.py
```

### A page's header or footer differs from the others

Somebody edited a page instead of the template.

```bash
python3 tools/propagate_shared.py --dry-run
python3 tools/propagate_shared.py
```

If the change was *meant* to be in the page, move it into `tools/templates/` first — otherwise the
next propagate discards it. [shared-markup.md](../10-development/frontend/shared-markup.md)

### An icon renders as an empty box

The page references a symbol it does not carry.

```bash
python3 tools/inject_icons.py
```

If it says the symbol is not in the master sprite, add it with `tools/build_icon_sprite.py` first.

---

## The contact form

### "We could not send your message just now"

`mail()` failed.

- **Locally this is expected** — there is no mail server. Everything except delivery is exercised.
- **On the host:** upload `tools/host-probe.php`, load it once, read the report, delete it. It
  tests `mail()` on its own, so a mail problem shows as one failed probe rather than as a form that
  quietly swallows enquiries.
- Check `disable_functions` does not contain `mail`.

### "That is several messages in a short time"

The rate limit: five an hour from one address. Working as intended.

It **fails open** — if the counter file is unreadable, the form still works. That is deliberate: the
counter shares a directory with the passwords, and an unreachable store must not make the company
uncontactable. This is spam control, not a security boundary.

### Enquiries arrive but replying goes to `no-reply@`

`Reply-To` is not surviving. Check the host is not rewriting headers. The envelope sender is
deliberately `no-reply@` — that is what SPF and DMARC check — while `Reply-To` carries the visitor's
address.

---

## The admin

### It refuses to load and shows a message about the private directory

Working as designed. The admin refuses rather than running without its store — an editor that
quietly works without a password is worse than one that visibly does not work.

The message names the path. Usually:

| | |
|---|---|
| Not writable | `chmod 700 ~/t4t-private` |
| Cannot be created | the directory **above** the document root is not writable |
| "inside the document root" | it is in the wrong place — [environments.md](../20-deployment/environments.md) |

### "That setup key does not match"

Retype rather than paste. It is compared case- and punctuation-insensitively, so the usual cause is
an invisible character.

```bash
cat ~/t4t-private/setup-token.txt
```

### `setup.php` redirects to `login.php`

An account already exists. If it is not yours, treat it as a compromise:

```bash
php ~/admin-cli.php list
php ~/admin-cli.php log 100
```

[Rung 8](secrets-recovery.md#8-suspected-compromise).

### `setup.php` offers to create an account on a site that already had one

> **Do not go through setup.**

`admins.json` is corrupted, not missing — `store_read()` returns `null` for both, so they are
indistinguishable to the admin. A good backup is sitting beside the broken file.

[Rung 6](secrets-recovery.md#6-adminsjson-corrupted).

### The authenticator code is always wrong

- **Clock drift.** `TOTP_DRIFT` allows one 30-second step either side. More than that needs the
  server's clock corrected.
- **A code works only once.** Wait for the next one rather than retyping the same digits.
- **Wrong account** in the app, if you have several paired.

### Recovery codes do not work, but `admin-cli list` says there are ten

`secret.key` was lost or replaced. The codes were hashed under a key derived from it.

```bash
php ~/admin-cli.php codes
```

[Rung 5](secrets-recovery.md#5-secretkey-lost-or-corrupted).

### "Try again in …"

The lockout. Five failures are free, then each attempt waits longer, up to an hour.

```bash
php ~/admin-cli.php unlock
```

### Signed out constantly

`AUTH_IDLE` is one hour, `AUTH_ABSOLUTE` twelve. If it is faster than that, the session directory is
not writable, so no session is persisting:

```bash
ls -la ~/t4t-private/sessions/
```

### The reset code never arrives

`mail()` — as above. Check the spam folder, confirm the mailbox on the account is one you can open,
and confirm SPF/DKIM/DMARC. A recovery code will sign you in meanwhile.

### A banner says the footer is out of step

The contact details in the JSON and the ones in the pages' footers disagree. Expected after editing
contact details — the footer is markup, not content.

```bash
# download the server's contact.json FIRST — it is the real one
python3 tools/sync_site_contact.py
# then deploy the pages, and not content/
```

---

## The tests

### A browser test fails intermittently

Leaked processes from an interrupted run.

```bash
pkill firefox geckodriver
```

### A browser test skips with a notice

Firefox or geckodriver is missing. By design — they exit 0 rather than fail, so a machine without a
browser can still run everything else.

### `test_admin_auth.py` hangs

A leaked PHP server holding the port.

```bash
pkill -f 'php -S'
```

### `check_content_model.py` fails after adding a field

You changed the shape in one of the three places it lives. The message names the field and the
missing layer. [content-model.md](../10-development/backend/content-model.md)

### `check_secrets.py` fails

Read the message before "fixing" it. It only fails for things that would silently weaken the admin,
and every one of its checks was verified against a deliberate breakage.

### `check_docs.py` fails

Code and documentation disagree. Usually a file added and not documented, or a constant changed that
the prose quotes. The message names both sides.

---

## Known traps

Current behaviour, documented because it is surprising rather than because it is right.

| Trap | Consequence | Until it is fixed |
|---|---|---|
| Recovery codes are hashed under a key derived from `secret.key` | losing the key kills all ten silently; `admin-cli list` still reports them | run `codes` after any key loss |
| `store_read()` returns `null` for both a missing file and a corrupt one | a corrupted `admins.json` presents as a fresh install and offers setup | restore `admins.json.bak`; never re-run setup to "fix" it |
| The containment check compares against the *requesting* document root | a store inside a **sibling** docroot would pass and be web-reachable | set `T4T_PRIVATE` explicitly; keep subdomain docroots outside `public_html` |
| `check_content_model.py` covers the contact page only | a careers field can drift between model, form and renderer unnoticed | change those three files together, deliberately |

The first three are fixes scheduled with the Phase B hardening. The fourth needs a `SUBJECTS` entry.

---

## Getting more detail

```bash
php ~/admin-cli.php log 100     # the audit log
php ~/admin-cli.php where       # which files the admin is using
tail -f ~/logs/error_log        # PHP errors, path varies by host
```

The audit log records every sign-in attempt, successful or not, with the IP. It is the first place
to look when something about access is unclear.
