# Running locally

**Applies to:** both

The dev server, signing in, running both halves side by side, and the one thing that genuinely
cannot work on a development machine.

---

## The server

```bash
python3 tools/serve.py            # http://localhost:8000
python3 tools/serve.py 8080       # a different port
```

It prints a menu of the pages worth visiting, tells you whether an admin account exists, and reminds
you how to undo content edits. `Ctrl-C` stops it.

Under the hood it is `php -S localhost:8001 -t public tools/dev-router.php`. **It serves `public/`,
not the repository** — the same document root the host serves, so `lib/`, `sections/` and `content/`
404 here exactly as they do there. A development machine on which `/../lib/auth.php` resolves would
teach the wrong lesson. [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md)

It binds to localhost only. It is still a real sign-in on a real port — do not run it on a public
interface.

---

## Signing in

Nothing is faked. The editors used to be waved through locally, because on the host Apache asked for
a password before any PHP ran — there was nothing to fake against. The admin has its own accounts
now, so the local sign-in *is* the real one.

**First time:** `/setup.php` — see [setup.md](setup.md#4-create-an-admin-account).

**After that:** `/login.php`, with your password and a code from your authenticator app.

Your account lives in `../t4t-private-admin/`, beside the clone:

```
CodeSpace/
├── tech4time-website-backend/       ← this repository
├── tech4time-website-frontend/      ← the other half
├── t4t-private-admin/       ← this side
│   ├── secret.key           peppers the password hashes
│   ├── admins.json          accounts, TOTP secrets, recovery codes
│   ├── sessions/
│   ├── throttle.json
│   ├── resets.json
│   ├── audit.log
│   └── publish.key          THE SAME BYTES as the frontend's copy
└── t4t-private/             the frontend's: three files, no accounts
```

**Two levels up from `public/`, not one.** One level up is the repository, which is what a deploy
empties. [environments.md](../20-deployment/environments.md)

Deliberately the same shape as `/home/USER/t4t-private-admin` on the host, so nothing about the layout is
different in development.

### Starting over

```bash
rm -rf ../t4t-private-admin      # then visit /setup.php again
```

### Locked yourself out

The lockout is real: five wrong passwords are free, then each attempt waits longer than the last.

```bash
php tools/admin-cli.php unlock       # clear the counters
php tools/admin-cli.php passwd       # set a new password
php tools/admin-cli.php totp-clear   # unpair the authenticator
php tools/admin-cli.php list         # what accounts exist
```

Locally `admin-cli.php` runs where it sits. On the host it is uploaded, run, and deleted —
[secrets-recovery.md](../30-operations/secrets-recovery.md).

---

## Running both halves at once

Every save publishes. With nothing to publish to, the editor says so on every save — which is
correct, and is what it would do on the host.

```bash
# terminal 1 — tech4time-website-frontend
python3 tools/serve.py                                          # :8000

# terminal 2 — here
T4T_PUBLIC_URL=http://localhost:8000 python3 tools/serve.py 8001
```

**Both stores need the same `publish.key`.** Without one, every publish is refused as
`not-configured`; with two *different* keys, as `unknown-key`.

```bash
python3 tools/make_publish_key.py      # in either repository, once
# then put the printed value in the other store's publish.key
```

---

## Editing content

**Saving writes `content/careers.json` and `content/contact.json` for real**, and then publishes
them. They are tracked files, so your edits show up in `git status`.

```bash
git checkout content/careers.json content/contact.json   # undo them
```

Keep the development data rich — several job posts, every contact field populated — because it is
what exercises the renderers. An empty JSON file tests nothing.

> **This is the opposite of the rule on the host**, where `content/` is the real data and must never
> be overwritten by a deploy. Locally it is test data; there, it is the company's.
> [environments.md](../20-deployment/environments.md)

---

## What cannot work locally

### `mail()`

There is no mail server on your machine, so:

- **The contact form** validates, sanitises and answers correctly, then reports that it could not
  send. Every part except delivery is exercised.
- **Password recovery by email** has nowhere to send the code. Use a recovery code instead.

Both are proven on the host with `tools/host-probe.php`, which tests `mail()` on its own so that a
mail problem shows up as one failed probe rather than as a contact form that quietly swallows
enquiries.

`test_contact_handler.py` does test the outgoing message — it points PHP's `sendmail_path` at a
script that captures the bytes `mail()` was asked to send, then reads them back. That is how the
header-injection defences are proven to work rather than merely to look right.

### Apache

The dev server is PHP's built-in one. `public/.htaccess` is not read, so locally you do not get the
security headers, the caching rules, the compression, or the blocking of `lib/`, `content/` and
`tools/`. The dev router reproduces the URL shapes and nothing else.

**What this means in practice:** a `public/.htaccess` change cannot be verified locally. Verify it on the
host, and read [security-model.md](../40-reference/security-model.md) before changing it.

---

## Looking at pages

```bash
python3 tools/shoot_pages.py            # screenshots into tools/shots/
python3 tools/check_dark_mode.py        # every page, both themes
python3 tools/check_hover.py            # a real pointer over every control
```

`tools/shots/` is gitignored — regenerate rather than commit.

---

## Housekeeping

The browser suites can leave processes behind if a run is interrupted:

```bash
pkill firefox geckodriver
```

Worth doing after an interrupted test run. They are harmless but they accumulate.
