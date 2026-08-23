# Security model

**Applies to:** both

What is protected, by what, and what is deliberately not protected. Read this before changing
`.htaccess`, `lib/private.php` or anything in `lib/auth.php`.

---

## The classes of data, and why they get different protection

| | Protected by | If that protection fails |
|---|---|---|
| Public pages, assets | nothing — they are public | nothing |
| `content/*.json` | an `.htaccess` rule | a stranger reads the office addresses the contact page already shows them |
| `lib/*.php` | an `.htaccess` rule | source disclosure — bad, not catastrophic |
| **`t4t-private/`** | **not being inside the website** | — there is no request that reaches it |

The distinction is the design. **An `.htaccess` rule is a policy the server chooses to apply.** If
`mod_rewrite` is off, or an upload replaces the file, it silently stops applying. That is an
acceptable risk for site copy and not for a password hash.

A directory outside the document root has no URL at all, which is a stronger thing than a rule. So
`lib/private.php` refuses to start if it finds itself inside the web root, rather than trusting the
rule.

---

## What is in the private store, and how each part is protected

```
/home/USER/t4t-private/        0700, owned by the cPanel user
├── secret.key      0600   32 bytes; every other key derives from it
├── admins.json     0600   hashes, TOTP secrets, recovery hashes
├── sessions/              session.save_path
├── throttle.json          failed-attempt counters
├── resets.json            pending reset codes, hashed
├── audit.log              one JSON line per event
└── setup-token.txt        only until the first account exists
```

| | Stored as | Survives the file being stolen? |
|---|---|---|
| Password | argon2id over an HMAC under `secret.key` | **yes** — two files must be stolen |
| Recovery codes | HMAC-SHA256 under a derived key | **yes** |
| Reset codes | HMAC-SHA256 under a derived key | **yes** |
| **TOTP secret** | **plain text** | **no** |
| **Session ids** | **plain text** | **no** |

> **Two of these cannot be hashed, and that is the point.**
>
> A TOTP secret is *shared* — the server must compute the same six digits your phone computes, so
> the value has to be used forwards. A session file is a bearer token by definition. Cryptography
> has nothing to offer either of them.
>
> **Their only protection is that nobody can read the file.** The containment is not belt-and-braces
> on the password hash — the password is already double-locked. It is the entire protection for the
> second factor.

Two protections, not one: **outside the document root** (no URL maps there) and **0700, owned by
your user** (this is shared hosting — other tenants are on the same box).

---

## The password

```php
$pre    = hash_hmac('sha256', $password, t4t_key('password-pepper'));
$stored = password_hash($pre, PASSWORD_ARGON2ID, AUTH_ARGON);
```

The salt is generated fresh per password by `password_hash()` and written into the hash string. The
HMAC is a **pepper**, and it lives in a different file — so `admins.json` alone yields nothing.

Argon2id at 32 MB and three passes, ~90 ms. bcrypt cost 12 as a probed fallback.

[authentication.md](../10-development/backend/authentication.md)

---

## Key derivation

Everything comes from `secret.key`, by purpose:

```
secret.key (32 random bytes)
    └── t4t_key($purpose) = hash_hmac('sha256', $purpose, master, true)
            ├── 'password-pepper'   the pepper
            ├── 'recovery'          recovery code hashes
            ├── 'reset'             reset code hashes
            └── 'throttle'          counter keys, so usernames never hit disk
```

Separate purposes get separate keys so a weakness in how one is used cannot be carried into another.

`secret.key` is created with `fopen(…, 'x')`, which fails if the file exists — making "create only
if absent" one atomic step. The creation path is written to **lose** a race, because regenerating
the key would invalidate every stored password at a stroke.

---

## HTTP-level protections

### Content-Security-Policy

```
default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'
```

No inline styles, no inline scripts, no external origins. A `style="…"` attribute is exactly what an
injected payload looks like, and forbidding the whole category means the browser rejects it without
having to tell the two apart.

This is why the rich-text sanitiser has no `style` attribute and why alignment is a class from a
fixed list.

### Other headers

`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and HSTS
(staged, commented until the site is live).

> `X-Frame-Options` and `X-Content-Type-Options` are **ignored by browsers when set via `<meta>`**.
> The `.htaccess` copy is the one that counts; the `<meta>` equivalents are defence in depth for
> hosts that strip headers.

### Blocking

```apache
<FilesMatch "^\.">                          Require all denied
<FilesMatch "\.(md|py|sh|json|lock|yml|yaml)$">  Require all denied
<Files "site.webmanifest">                  Require all granted   # the one public .json
RewriteRule ^tools/       - [F,L]
RewriteRule ^lib/         - [F,L]
RewriteRule ^content/     - [F,L]
RewriteRule ^t4t-private/ - [F,L]
```

The last is for the one case the containment check cannot help with: somebody restoring a backup and
dropping the folder into the web root by hand.

### Keeping the admin out of search results

```apache
Header always set X-Robots-Tag "noindex, nofollow, noarchive" "expr=%{REQUEST_URI} =~ m#^/admin(/|$)#"
Header always set Cache-Control "no-store, max-age=0"          "expr=…"
```

`always` matters: without it the header attaches only to 2xx responses, and the login page answers a
crawler with 200 while every other admin URL answers with a redirect.

> **`/admin` is deliberately not in `robots.txt`.** Listing it there advertises it to anyone who
> reads the file.

---

## The admin's protections, and what each defeats

| Protection | Defeats |
|---|---|
| argon2id + pepper | offline attack on a stolen accounts file |
| TOTP second factor | a compromised password |
| A code accepted once | replay within its 30-second window |
| Throttle **before** verification | brute force, and lockout used as an oracle |
| `auth_password_dummy()` | username enumeration by timing |
| Identical forgot-password response | username enumeration by response |
| Reset code ≠ password reset | a compromised mailbox owning the account |
| `session.use_strict_mode` | session fixation |
| CSRF token on every POST | cross-site request forgery |
| `token_version` | sessions surviving a password change |
| Setup token | a stranger creating the first account |
| Host-only cookie, no `Domain` | the session leaking to the public site |
| Audit log | not knowing any of the above happened |

---

## What is deliberately not protected

**The contact form's rate limit fails open.** If the counter file is unreadable, the form still
works. The counter shares a directory with the passwords, and an unreachable store must not make the
company uncontactable. This is spam control, not a security boundary.

**`admin-cli.php` asks for no password.** Anyone who can run it can already read the accounts file.
Requiring one would add no security and remove the last way in on the day it is needed.

**`content/` is only `.htaccess`-protected.** It holds addresses the contact page already displays.

**There is no intrusion detection**, no WAF, no fail2ban. The audit log records; nothing watches it.

---

## The enforced invariants

`tools/check_secrets.py` asserts the protections that would go on *working* after being removed —
the dangerous kind:

- no secret file is tracked in git
- the private store still refuses the document root
- no auth-bypass constant has returned (`ADMIN_REQUIRE_HTTP_AUTH` in particular)
- the session cookie flags are intact
- no password can reach the audit log
- every admin page shape is noindexed

**Every one of those checks was verified against a deliberate breakage before being trusted.** A
check nobody has watched fail is a check nobody should believe. Keep that going.

---

## Known weaknesses

| | Consequence | Mitigation |
|---|---|---|
| The containment check compares against the *requesting* document root | a store inside a sibling docroot passes and is web-reachable | set `T4T_PRIVATE`; keep subdomain docroots outside `public_html` — [environments.md](../20-deployment/environments.md) |

Scheduled with the Phase B hardening.

> **Fixed 2026-08-23.** Recovery codes derived from `secret.key` died silently when it was lost —
> `admin-cli list` counted stored entries and reported ten. Each code now carries the fingerprint of
> the key that made it, so the CLI reports `10 DEAD` and says what to do.
>
> **Fixed 2026-08-23.** `store_read()` could not tell a missing file from a corrupt one, so a
> damaged `admins.json` presented as a fresh install and offered setup — whose first save copied the
> damage over the `.bak`. `store_state()` now tells them apart: the admin refuses to start, and
> `store_write()` never lets a damaged file become the backup.

---

## Reporting

Found something? Do not open a public issue. Contact whoever holds the cPanel account directly. If
`secret.key` may have been exposed, treat it as
[rung 8](../30-operations/secrets-recovery.md#8-suspected-compromise) and rotate rather than merely
removing the file.
