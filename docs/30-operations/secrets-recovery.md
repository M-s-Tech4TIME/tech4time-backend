# Recovering the admin

**Applies to:** backend

Every way back into `/admin/`, from "I forgot my password" to "the entire private store is gone",
in order of severity.

**Nothing here can lose the website.** The private store holds credentials and nothing else. Every
page, every job post and every contact detail is in `public_html/content/`, untouched by anything on
this page. The worst case costs about two minutes and ends with you signing in again.

Each rung below was tested against a deliberately broken store, not reasoned about.

---

## Find your rung

| What is gone | Still works | Go to |
|---|---|---|
| The password | app, email | [1](#1-forgotten-password) |
| The phone | password | [2](#2-lost-phone) |
| Phone **and** recovery codes | password | [3](#3-phone-and-codes-gone) |
| Password, phone, codes, and email is down | — | [4](#4-everything-forgotten) |
| `secret.key` | the app | [5](#5-secretkey-lost-or-corrupted) |
| `admins.json` corrupted | — | [6](#6-adminsjson-corrupted) |
| The entire store | — | [7](#7-the-whole-store-is-gone) |
| Nothing — but it may have been stolen | everything | [8](#8-suspected-compromise) |

Every rung has one below it. **The bottom rung is the ability to run a command on the server**, which
cannot be forgotten, expire, or be thrown away with an old phone.

---

## 1. Forgotten password

1. `/admin/forgot.php`
2. Enter the username or the account email
3. A six-digit code arrives at the address **on the account** — never one typed into the form
4. Enter the code, then an authenticator code, then the new password

The code lasts ten minutes, allows five guesses, works once, and only in the browser that asked for
it.

> **The emailed code alone will not set a password.** The authenticator or a recovery code is still
> required. If six digits sent to a mailbox were enough, that mailbox would *be* the admin password.

Asking is rationed: three times an hour per account, five per address, twenty overall.

## 2. Lost phone

Use a recovery code in place of the six digits. Each works once.

Then re-pair immediately: **Account → Authenticator app → pair a new device**. That issues a new
secret and invalidates the old one.

Running low? Account → Recovery codes → issue ten new ones. The old set stops working.

## 3. Phone and codes gone

You still know the password, so you need the server only to unpair the app.

```bash
ssh user@tech4time.bd
# upload tools/admin-cli.php to your HOME directory first — see below
php ~/admin-cli.php totp-clear
```

You can now sign in with the password alone. **Pair a new app immediately** — until you do, the
password is the only protection on the account.

## 4. Everything forgotten

Password gone, phone gone, codes gone, email not delivering.

```bash
php ~/admin-cli.php list          # what accounts exist
php ~/admin-cli.php passwd        # set a new password; ends every session
php ~/admin-cli.php totp-clear    # unpair, so a new phone can be paired
php ~/admin-cli.php codes         # issue ten new recovery codes
```

Sign in, pair a new authenticator, save the new codes.

## 5. `secret.key` lost or corrupted

**Tested.** With `secret.key` deleted and `admins.json` intact:

| | |
|---|---|
| The account still lists | yes |
| The **correct** password verifies | **no** |
| The authenticator still works | yes — the TOTP secret is not peppered |
| Recovery codes | **dead, and reported as dead** — `admin-cli list` shows `10 DEAD` |

Every password was hashed with a pepper derived from that file. Without it, nothing verifies.
A new key is generated automatically, so the site keeps running and simply rejects everybody.

**Recovery:**

```bash
php ~/admin-cli.php passwd        # sets a new password under the new key
php ~/admin-cli.php codes         # REQUIRED — see below
```

> ### Why `codes` is not optional
>
> Recovery codes are hashed under `t4t_key('recovery')`, which derives from `secret.key`. When that
> file is lost, **every recovery code stops working.**
>
> Each stored code carries the fingerprint of the key that made it, so `admin-cli list` says so
> rather than counting entries:
>
> ```
>   USER             EMAIL           2FA      CODES     LAST SIGN-IN
>   admin            admin@…         paired   10 DEAD   never
> ```
>
> It used to print `10`, and all ten would fail — which you would have discovered on the day you
> needed one, the worst possible day. Run `codes` and the new ones are live again.

Losing the key is survivable. It costs everyone a password reset and a new set of codes. That is
why it is worth backing up — [backups.md](backups.md).

## 6. `admins.json` corrupted

A truncated or malformed file — an interrupted write, a bad restore.

> ### What you will see
>
> **The admin refuses to start**, saying the account file is present but cannot be read. That is
> deliberate: a damaged file is otherwise indistinguishable from a site nobody has set up, and the
> admin would offer you setup — whose first save copies the damage over `admins.json.bak`,
> destroying the copy this rung recovers from.
>
> A good backup is sitting right beside the broken file. Restore it rather than setting up again,
> which would enrol a new authenticator and discard the account you already have.

```bash
cd ~/t4t-private
cp admins.json admins.json.broken        # keep the evidence
cp admins.json.bak admins.json           # one generation, written on every save
chmod 600 admins.json
php ~/admin-cli.php list                 # confirm the account is back
```

If there is no `.bak`, go to rung 7.

## 7. The whole store is gone

A rebuilt server, a restore that missed the home directory, a deleted folder.

**Tested:** the admin rebuilds the directory, finds no accounts, and presents `/admin/setup.php` —
which **still demands the setup key**, so a stranger cannot walk in during your rebuild.

```bash
cat ~/t4t-private/setup-token.txt      # load /admin/setup.php first; that writes it
```

Then work through [admin-activation.md](../20-deployment/admin-activation.md) again: create the
account, pair an app, save the codes.

**The website itself is unaffected.** Pages, job posts and contact details are in `public_html/` and
were never in the private store.

## 8. Suspected compromise

Different problem: the secrets are not lost, they may have been *copied*.

Assume the worst — whoever read `admins.json` holds your **authenticator secret in plain text**. It
cannot be hashed, because the server must compute the same code your phone does. They can generate
valid codes indefinitely.

Rotate everything, in this order:

```bash
# 1. force a new master key — this invalidates every password hash
mv ~/t4t-private/secret.key ~/secret.key.old

# 2. new password under the new key; ends every session
php ~/admin-cli.php passwd

# 3. NEW authenticator secret — the old one is compromised
php ~/admin-cli.php totp-clear

# 4. new recovery codes — the old ones were hashed under the old key anyway
php ~/admin-cli.php codes
```

Then sign in, pair a new authenticator immediately, and read the audit log:

```bash
php ~/admin-cli.php log 100
```

Look for successful sign-ins you do not recognise, and for `setup-token-failed`.

Finally: `rm ~/secret.key.old`, and change the cPanel password too — anyone who read that file had
filesystem access, which is a bigger problem than the admin account.

---

## The command-line tool

`tools/admin-cli.php` is the floor under everything above.

```bash
# upload to your HOME directory — /home/USER/, the level ABOVE public_html
scp tools/admin-cli.php user@tech4time.bd:~/

ssh user@tech4time.bd
php ~/admin-cli.php list

rm ~/admin-cli.php        # when you are done
```

| Command | Does |
|---|---|
| `list` | what accounts exist, and what they can sign in with |
| `passwd [user]` | set a new password; ends every session |
| `codes [user]` | issue ten new recovery codes and print them once |
| `totp-clear [user]` | unpair the authenticator so a new phone can be paired |
| `unlock` | clear the lockout after too many failed attempts |
| `log [n]` | the last n entries from the audit log |
| `where` | which files it is working on |

With one account, `[user]` can be left out.

> **Not into `public_html`.** Nothing here should be reachable over HTTP, and outside the document
> root it cannot be. It also refuses to run over HTTP at all — `PHP_SAPI !== 'cli'` returns a 404.

**It asks for no password**, and that is deliberate: anyone who can run a command on that server can
already read the accounts file. Requiring one would add no security and would remove the last way in
on the day it is needed. That is what makes it a floor and not a hole.

If SSH is unavailable, cPanel's **Terminal** runs the same commands, and **File Manager** can read
`~/t4t-private/setup-token.txt`.

---

## What to do now, before you need this

- [ ] **Back up `secret.key`** — 65 bytes, in a password manager. It turns rung 5 from "everyone
      resets" into "restore one file".
- [ ] **Check your cPanel backup covers the home directory**, not just `public_html`.
      [backups.md](backups.md)
- [ ] **Store the recovery codes somewhere real** — not a browser tab, not a screenshot.
- [ ] **Confirm `admin@tech4time.bd` is a mailbox you can actually open.**
- [ ] **Run a full password recovery once**, deliberately, while everything works.
