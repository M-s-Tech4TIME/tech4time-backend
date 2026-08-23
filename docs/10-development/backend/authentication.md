# Authentication

**Applies to:** backend

The admin's sign-in: how a password is stored, how the second factor works, what happens when
somebody guesses, and how a locked-out person gets back in.

Operational recovery is in [secrets-recovery.md](../../30-operations/secrets-recovery.md). This page
is the design.

---

## What it replaced, and why that matters

`/admin` used to be protected by cPanel Directory Privacy. Apache did the checking and PHP never
verified a credential at all — `admin_require_auth()` only looked at whether `REMOTE_USER` had been
filled in.

That was a real lock, but it was **Apache's** lock: no sign-out, no lockout, no record of who signed
in, no second factor, and a browser dialogue instead of a page. Worse, `ADMIN_REQUIRE_HTTP_AUTH` was
a flag whose *false* value granted full access rather than less access.

It is gone, and `check_secrets.py` fails the build if it returns.

---

## Signing in

Two stages.

```
1. username + password  ──► argon2id verify, after the throttle
2. six digits           ──► TOTP, or a recovery code
                            ──► session id regenerated, signed in
```

The intermediate state lives in `$_SESSION['pending']` and expires after five minutes. A fresh
password post clears any abandoned attempt first — otherwise a wrong password typed while a stale
attempt sat in the session left the *code* form on screen.

---

## The password

```php
$pre    = hash_hmac('sha256', $password, t4t_key('password-pepper'));
$stored = password_hash($pre, PASSWORD_ARGON2ID, AUTH_ARGON);
```

**The salt is inside the hash string.** `password_hash()` generates a fresh random salt for every
password and writes it into what it returns, along with the algorithm and its cost:

```
$argon2id$v=19$m=32768,t=3,p=1$c2hVZFAvOUR6SXZlU2RMbA$VintFkguf60CDwJmQxw2nhXyfhTB/iBGMvuZ5qbou4Y
└ algo  ┘└ v ┘└─── cost ────┘└───── salt ─────┘└──────────── digest ────────────┘
```

Verifying reads the salt back out of that same string and compares in constant time. There is no
separate salt field to manage, and there should not be one.

**The HMAC on top is a pepper.** It lives in `secret.key`, in a different file from `admins.json`, so
a stolen copy of the accounts file alone cannot be attacked offline. With the hash above and the
correct password, `password_verify()` returns `false` unless `secret.key` is also stolen.

**Cost:** `AUTH_ARGON = ['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1]` — 32 MB and
three passes. Above the 19 MB OWASP names as a floor, and below PHP's own 64 MB default, which is a
fine number on a server you own and an unkind one on shared hosting. Roughly 90 ms per hash.

**Fallback:** bcrypt at cost 12 where argon2id is unavailable, decided by probe rather than assumed.
The pre-HMAC produces 64 hex characters, safely under bcrypt's 72-byte truncation limit.

**Upgrades are free.** `password_needs_rehash()` re-stores the password at the next successful
sign-in whenever the settings change, so raising the cost later costs nobody a reset.

**Rules:** at least 12 characters, at most 200, not only spaces, and not containing `password`,
`12345678`, `tech4time`, `qwerty` or `admin`. See `auth_password_problem()`.

---

## The second factor

`lib/totp.php` — RFC 6238, 30-second step, 6 digits, `TOTP_DRIFT` of one step either side for a
phone clock that is slightly out.

**A code is accepted once.** The counter it matched is stored on the account and anything at or
below it is refused, so a code cannot be replayed inside the thirty seconds it stays valid.

No QR code. An encoder is several hundred lines for a picture of a string that every authenticator
app will also accept typed in, and `img-src 'self' data:` would allow it if that ever changes.

**Ten recovery codes** (`AUTH_RECOVERY`), shown once at enrolment and stored hashed. Each signs you
in once in place of the app. They are hashed under `t4t_key('recovery')` — a key derived from
`secret.key` — so [losing the master key kills all ten](../../30-operations/secrets-recovery.md).

A stored code is `fingerprint:digest`, where the fingerprint names the key it was made under
(`t4t_key_fingerprint()`). That is what lets a dead code be recognised as dead rather than merely
failing to match, so `admin-cli list` can report `10 DEAD` instead of counting entries.

The marker lives on the value rather than on the account because seven places write a secret, and a
stamp applied at each of them is a stamp somebody forgets at the eighth. Anything that produces a
stored code produces the marker with it. Codes written before the marker existed are still accepted
on their digest alone — a different key would not have produced that digest either.

---

## Sessions

`auth_boot()`, and the details are load-bearing:

| | |
|---|---|
| `session_name` | `t4tadm` (`AUTH_COOKIE`) |
| save path | `t4t-private/sessions/` — never a shared `/tmp` |
| `httponly` | true |
| `secure` | when the request is HTTPS |
| `samesite` | `Lax` |
| `path` | `/`, **no `Domain`** — host-only, so it cannot leak to the public site |
| idle timeout | `AUTH_IDLE`, one hour |
| absolute timeout | `AUTH_ABSOLUTE`, twelve hours however active |
| `session.use_strict_mode` | **1** |

> **`use_strict_mode` is what closes session fixation.** PHP refuses a session id it did not issue,
> so an attacker cannot plant an id in a browser and wait for it to be signed in.

The id is regenerated on every privilege change. Sessions record the account's `token_version`;
bumping it on a password change ends every other session.

`auth_sweep_sessions()` deletes expired session files, because PHP's own collector is switched off by
default on Debian and its derivatives — normally harmless, since a cron job cleans the shared
directory, but this directory is ours and nothing else will.

---

## When somebody guesses

`lib/throttle.php`. Five failures are free (`AUTH_ALLOW`), then each one waits longer than the last,
doubling from 30 seconds up to `THROTTLE_MAX_BLOCK` — one hour.

**The throttle is applied before the password is verified.** Otherwise "you are locked out" and
"that password was wrong" take different amounts of time, and the difference is an oracle.

Two more details in the same spirit:

- `auth_password_dummy()` hashes against a throwaway hash when the account does not exist, so a
  missing username costs the same 90 ms as a wrong password.
- `throttle_key()` HMACs the identifier, so usernames never appear on disk in the counter file.
- `throttle_ip()` reads `REMOTE_ADDR` and never `X-Forwarded-For`, which a stranger sets.

---

## Forgetting the password

`/admin/forgot.php` is **the one admin page a stranger can reach**, because it has to work at the
moment nobody can sign in.

So: it answers identically whether or not the account exists, sends only to the address on file
— never to one typed into the form — and the code is `random_int(0, 999999)`, stored only as an
HMAC, good for `RESET_TTL` (ten minutes), five guesses, once, and only in the browser that asked.

**The emailed code alone will not set a password.** The authenticator, or a recovery code, is still
required.

> If six digits sent to a mailbox were sufficient, that mailbox would *be* the admin password, and
> the second factor would be protecting nothing at the one moment it matters most.

Rationed three per hour per account, five per address, twenty overall. The last is not about this
site: cPanel caps outbound mail per hour, and somebody hammering the page could use the allowance
up, stopping the genuine reset from being delivered.

On success, `token_version` is bumped and every session ends.

---

## The bootstrap window

Between deploying and creating an account, a page that creates the administrator is reachable — and
**whoever creates the first account owns the website**. Being first is not a defence; the gap
between an upload finishing and somebody getting round to setup can be days.

`admin/setup.php` therefore asks for a value that exists only in the private directory on the
server's own disk — `t4t-private/setup-token.txt`. Reading it takes SSH, cPanel's Terminal or its
File Manager: the access whoever is setting this up has and a stranger does not.

It is created **by the page that asks for it** and **destroyed the moment an account exists**, so
the window is shut by the code rather than by a step somebody has to remember. Loading `setup.php`
from anywhere but the server itself writes the file, which is what makes `cat` work as the next step
of the procedure. It is skipped when the request comes from the machine itself — by peer address,
and never by the `Host` header, which a stranger can set.

The key is recognised on re-reading by its length, and that length is derived from
`AUTH_SETUP_BYTES` rather than written out a second time. A guard carrying its own idea of the
length rejects every key it ever stored, mints a new one on each call, and compares the operator
against a value they were never shown — which is a setup page that can never be completed and says
nothing about why.

The account is not written until the authenticator app has been proven to work. An admin enrolled
but unable to produce a code is an admin locked out on the first sign-in, and setup is the one moment
that is still free to put right.

[admin-activation.md](../../20-deployment/admin-activation.md)

---

## Refusing to run

`auth_problem()` is checked **before anything else** on every admin page. It returns a sentence to
show a person when the private store is missing, unreachable or unwritable — and the admin refuses
to load rather than proceeding.

> An editor that quietly works without a password is worse than one that visibly does not work at
> all.

This is the same principle the old Directory Privacy check followed; only its trigger changed.

---

## The audit log

`t4t-private/audit.log`, one JSON line per event: sign-ins successful and not, sign-outs, password
changes, recovery codes spent, reset requests, setup attempts. The Account page shows the last
fifteen; `admin-cli.php log 25` shows more.

`check_secrets.py` asserts **no password can reach it**, and its check was verified by deliberately
breaking it.

---

## The constants

| Constant | File | Value | Means |
|---|---|---|---|
| `AUTH_IDLE` | `lib/auth.php` | 3600 | one hour of inactivity ends a session |
| `AUTH_ABSOLUTE` | `lib/auth.php` | 43200 | twelve hours, however active |
| `AUTH_ALLOW` | `lib/auth.php` | 5 | failures before a wait is imposed |
| `AUTH_RECOVERY` | `lib/auth.php` | 10 | recovery codes issued at enrolment |
| `AUTH_ARGON` | `lib/auth.php` | 32 MB, t=3, p=1 | the hashing cost |
| `AUTH_BCRYPT` | `lib/auth.php` | cost 12 | the fallback |
| `THROTTLE_MAX_BLOCK` | `lib/throttle.php` | 3600 | the longest lockout |
| `RESET_TTL` | `lib/reset.php` | 600 | ten minutes |
| `RESET_ATTEMPTS` | `lib/reset.php` | 5 | guesses at a reset code |
| `RESET_PER_ACCOUNT` / `_PER_IP` / `_GLOBAL` | `lib/reset.php` | 3 / 5 / 20 | resets per hour |
| `AUTH_SETUP_BYTES` | `lib/auth.php` | 6 | random bytes behind a setup key |
| `AUTH_SETUP_CHARS` | `lib/auth.php` | twice that | the length a stored key must have to be recognised |
| `TOTP_STEP` / `_DIGITS` / `_DRIFT` | `lib/totp.php` | 30 / 6 / 1 | the authenticator |

> **These values are quoted in this documentation.** `tools/check_docs.py` fails if you change one
> without updating the prose.

---

## If you are changing this code

- **Keep `auth_second_factor(array &$account, …)` by reference.** It took the account by value once,
  and `auth_login()` overwrote its result one line later — silently restoring spent recovery codes
  and the old TOTP counter. Nothing errored.
- Fail closed. Anything that cannot reach its store must refuse, not continue.
- Throttle before verifying, always.
- Add a check to `check_secrets.py` for anything that could be removed without a test failing — and
  prove the check works by breaking the thing it guards.
- `python3 tools/test_admin_auth.py` drives the whole cycle over HTTP, including the RFC test
  vectors.
