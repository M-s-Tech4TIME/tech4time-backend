# 0008 — The private store lives outside the document root

**Status:** accepted · **Applies to:** backend

## Decision

Password hashes, the master key, authenticator secrets, sessions, counters and the audit log live at
`/home/USER/t4t-private/` — a **sibling** of `public_html`, not a directory inside it.
`lib/private.php` refuses to start if it finds itself inside the web root.

## Context

The obvious place was `content/`, which is already blocked by `RewriteRule ^content/ - [F,L]`.

But an `.htaccess` rule is a policy the server *chooses* to apply. If `mod_rewrite` is off, or an
upload replaces the file, it silently stops applying — and the failure is invisible.

That risk is acceptable for site copy: if `content/` were ever served, a stranger would read the
office addresses the contact page already shows them. It is not acceptable for what is in this
directory, and the reason is sharper than the password hash:

> **Two of these values cannot be hashed.** A TOTP secret is *shared* — the server must compute the
> same six digits the phone computes. A session id is a bearer token. Cryptography has nothing to
> offer either. Their only protection is that nobody can read the file.

## Consequences

**Good.** No URL maps to the directory, which is stronger than any rule. Plus 0700 and single
ownership, which handles the other tenants on a shared box. The layout is identical in development
(`../t4t-private`), so nothing about it is special-cased.

**Costs.** It cannot be in the repository, so it must be backed up separately — and a
`public_html`-only backup silently omits it. Path arithmetic is needed to find it, which has one
failure mode: a subdomain whose document root is *inside* `public_html` would place the store in the
public site's web root, and the containment check would not catch it because it compares against the
requesting document root. [environments.md](../20-deployment/environments.md)

**Forbids.** Putting anything secret under the document root, however well blocked.
