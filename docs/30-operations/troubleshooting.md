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

**If `cat` shows a different key each time you run it**, the file is not being recognised on
re-reading and a fresh one is minted on every call — so the key you are shown is never the key you
are checked against, and no amount of careful typing will work. The length a stored key must have is
derived from `AUTH_SETUP_BYTES` in `lib/auth.php`; if the format is ever changed, that is where the
two must stay in step. `test_admin_auth.py` covers this.

### `setup.php` redirects to `login.php`

An account already exists. If it is not yours, treat it as a compromise:

```bash
php ~/admin-cli.php list
php ~/admin-cli.php log 100
```

[Rung 8](secrets-recovery.md#8-suspected-compromise).

### The admin refuses: "The account file is present but cannot be read"

`admins.json` is corrupted, not missing. The admin stops rather than continue, because from there a
damaged file is indistinguishable from a site nobody has set up — and going through setup would
copy the damage over `admins.json.bak`, which may be the only intact copy left.

A good backup is sitting beside the broken file. Restore it:

```bash
cd ~/t4t-private
cp admins.json admins.json.broken        # keep the evidence
cp admins.json.bak admins.json
```

[Rung 6](secrets-recovery.md#6-adminsjson-corrupted).

> **If you are on a version that offers setup instead of refusing**, do not go through it — restore
> the `.bak` as above. That was the behaviour before the refusal existed.

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

### `check_responsive.py` fails

A page scrolls sideways, a control is wider than the screen, or a tap target is under 24px, at one
of seven widths.

The message names the page, the width and the element. Two things to know before chasing it:

- **The overflow may not be where it looks.** An element clipped by an ancestor cannot extend the
  page, so the check already skips those; what it names is the element that can. A carousel's
  off-screen slides are never the answer.
- **`… has no control wider than the screen` is a separate failure from `… does not scroll
  sideways`,** and the page can look perfect while failing it. `.btn` clips its own overflow for the
  shine sweep, so an over-long label is cut off in silence rather than pushing the layout.

- **`… has no tap target under 24px` is WCAG 2.2 SC 2.5.8, with its exceptions applied.** Before
  enlarging anything, check which exception was meant to cover it: a `<label>` counts towards the
  control it names, an inline link in a sentence is exempt, and a small target with 24px of clear
  space around it is exempt however small. The reported size is the *union* of the control and its
  label, which is why a checkbox can be reported as `597x23`. Note the height, not the width.

  The stylesheet aims higher than this check enforces — several components declare `2.75rem` for a
  44px target. That is deliberate: the check holds the line at the AA standard, and the design
  exceeds it. Do not lower a 44px control to 24 because the check would still pass.

Each width is measured in a frame, and the run prints the viewport it actually got. If that number
stops matching the width asked for, stop and read
[0015](../90-decisions/0015-narrow-widths-need-a-frame.md) — nothing in the run can be believed.

Two of the widths are criteria rather than devices: 320px is SC 1.4.10 Reflow, defined at exactly
that width, and 640px is a 1280px desktop at 200% zoom, which is SC 1.4.4 Resize Text.

### `check_focus.py` fails

**`Reduced motion did not take effect … Refusing to run.`** Not a site failure. The check needs
`scroll-behavior: auto` so it reads where a focused element came to rest rather than a position it
is still travelling through. Either the Firefox pref stopped working or the reduced-motion block in
`assets/css/base.css` moved. Fix that before trusting any focus result.

**`focus is not hidden`** — something covers the focused element entirely (SC 2.4.11). On mobile it
is almost always the dock, which is `position: fixed` at the bottom. The mitigation is
`scroll-padding-bottom` on `html`, next to the `scroll-padding-top` that does the same job for the
sticky header. If a new piece of fixed chrome is added, it needs its own allowance.

**`focus can be seen`** — the element has no visible indicator (SC 2.4.7). Check the computed
`outline` *and* the pixels: `<summary>` reports the right outline colour while painting nothing, so
computed style alone will tell you it is fine. Screenshot it focused and unfocused and count the
differing pixels; zero means no indicator, whatever the stylesheet says. That is why
`summary:focus-visible` uses a `box-shadow` in `base.css` rather than an outline.

### `check_docs.py` fails

Code and documentation disagree. Usually a file added and not documented, or a constant changed that
the prose quotes. The message names both sides.

---

## Known traps

Current behaviour, documented because it is surprising rather than because it is right.

| Trap | Consequence | Until it is fixed |
|---|---|---|
| The containment check compares against the *requesting* document root | a store inside a **sibling** docroot would pass and be web-reachable | set `T4T_PRIVATE` explicitly; keep subdomain docroots outside `public_html` |

That one is a fix scheduled with the Phase B hardening.

**Fixed 2026-08-23** — the whole repository, unpacked into the document root. The first deploy was
an upload of everything, and it left `docs/`, `tools/`, `references/`, `.git/`, `.claude/`, the
`.md` files and a **63 MB `tech4time-website.zip`** beside `index.html`.

`.htaccess` section 8 exists for exactly this — *"if the whole tree is ever uploaded, these must not
be readable over HTTP"* — and did not deliver it. `<FilesMatch "^\.">` matches the **filename**, so
`/.git/HEAD` was the file `HEAD` to it: no leading dot, no blocked extension, straight through. And
nothing covered `.zip` at all, so the entire source and its commit history were downloadable by
anyone who guessed the filename. The host answered 403 for `.git` by a rule of its own — not ours,
and not present on a server we run.

Now: a rewrite rule blocks any path segment beginning with a dot (exempting `.well-known/`, which
AutoSSL needs), archives and dumps join the extension rule, `references/` is blocked, and
`verify_live.py` asserts all of it against the running site after every deploy. The deploy set is
built from an allow list, so none of it can be uploaded again.

**Fixed 2026-08-23** — the setup token outlived setup. `admin/setup.php` promises the bootstrap
window is *"shut by the code rather than by a step somebody has to remember"*, and on the live host
`setup-token.txt` sat in the private store beside a working account.

`auth_setup_done()` had deleted it correctly. The recovery-codes screen — which skips the "setup is
over" redirect on purpose, so it can show the codes — then fell through to `auth_setup_token()` and
re-minted it, seconds later. The token was inert, because a stranger with no `codes` stage in
session is redirected to `login.php` before it is ever compared, but the guarantee was false.

The guard now lives in `auth_setup_token()` itself rather than at the call site, so the file cannot
come back whoever asks; `auth_setup_token_check()` refuses outright once an account exists, so an
empty token can never meet an empty stored value in `hash_equals()` and agree with it. Both halves
are asserted by `check_secrets.py`.

The test that should have caught it *existed and passed vacuously*: it ran from 127.0.0.1, where
`auth_is_loopback()` is true, no key is ever demanded and no file is ever written — so "the setup
key file is gone" was true because nothing had created it. `test_admin_auth.py` now carries the
remote setup flow through to the codes screen, which is the only branch that can prove it.

**Fixed 2026-08-23** — tabbing on a phone put the focus ring underneath the dock. The browser
scrolls a focused element into view and was happy to land it exactly where the fixed bar covers it,
on footer links, phone numbers and the end of the contact form — twelve stops across three pages.
`html` now carries a `scroll-padding-bottom` below 64em, the mirror of the `scroll-padding-top` that
already kept the sticky header clear.

**Fixed 2026-08-23** — the certification accordions and the job posts had no focus indicator at all.
`<summary>` will not take an outline in Firefox: the computed style reports the right colour while
`outline-style` stays `none`, and an explicit `summary:focus-visible { outline: … }` changes
nothing. A screenshot settled it — zero pixels differ when a summary is focused, against 307 for an
ordinary link. It is drawn with a `box-shadow` now.

**Fixed 2026-08-23** — the contact form's consent checkbox was a 23px tap target at the widths where
its label fits on one line, one pixel under WCAG 2.2 SC 2.5.8. The box is now `1.5rem`, which is 24
exactly. Reading the stylesheet would never have shown it: the box was 18px, the label 23, and the
target is the two together.

**Fixed 2026-08-23** — the About page scrolled sideways on a 320px screen, and its call to action
was cut off. The specialities slider's control row is eight 44px tap targets, centred, which is
wider than the screen and overhangs both edges; and `.btn` had an unconditional `white-space:
nowrap`, so a 34-character label became a 351px button that `.cta-band` clipped. Neither was
visible to any check, because Firefox will not size a window below about 488px and nothing here had
ever measured a narrower one. `tools/check_responsive.py` now does, in a frame.

**Fixed 2026-08-23** — a careers field drifting between model, form and renderer unnoticed.
`check_content_model.py` could never have caught it: both sides of that page are loops, so its
regexes read the loop variable rather than the fields. `tools/test_careers_admin.py` proves it by
round trip instead — a marker through every field the model declares, editor to visitor — and
`check_content_model.py` now fails if an editor is in neither `SUBJECTS` nor `COVERED_ELSEWHERE`.

**Fixed 2026-08-23** — recovery codes dying silently with `secret.key`. Stored codes carry the
fingerprint of the key that made them, so `admin-cli list` prints `10 DEAD` and says what to run
instead of counting entries. Covered by `tools/test_admin_auth.py`.

**Fixed 2026-08-23** — `store_read()` answering `null` for both a missing and a corrupt file. They
are told apart by `store_state()` now: the admin refuses to start on a damaged account file instead
of offering setup, and `store_write()` will not let a damaged file become the `.bak`. Covered by
`tools/test_store.py` and `tools/test_admin_auth.py`.

---

## Getting more detail

```bash
php ~/admin-cli.php log 100     # the audit log
php ~/admin-cli.php where       # which files the admin is using
tail -f ~/logs/error_log        # PHP errors, path varies by host
```

The audit log records every sign-in attempt, successful or not, with the IP. It is the first place
to look when something about access is unclear.
