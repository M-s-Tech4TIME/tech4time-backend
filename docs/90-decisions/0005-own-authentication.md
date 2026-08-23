# 0005 — The admin authenticates itself

**Status:** accepted · **Applies to:** backend
**Supersedes:** cPanel Directory Privacy as the access control for `/admin`

## Decision

`/admin` has its own accounts: password, authenticator app, lockout, audit log, recovery codes and
password recovery by emailed code. Apache is no longer the lock.

## Context

`/admin` was protected by cPanel Directory Privacy. Apache did the checking, and PHP never verified
a credential — `admin_require_auth()` only looked at whether `REMOTE_USER` had been filled in.

That was a real lock. But it had no sign-out, no lockout, no record of who signed in, no second
factor, and a browser dialogue instead of a page. It could not be tested, because its state lived in
a control panel. And `ADMIN_REQUIRE_HTTP_AUTH` was a flag whose **false value granted full access**
rather than less access.

## Consequences

**Good.** Signing out is possible. Guessing costs something. There is a record. A second factor
exists. The whole cycle is testable — `test_admin_auth.py` drives it over HTTP. Access control moves
into the repository, where it is reviewed and version-controlled.

**Costs.** Considerably more code to maintain, and it must be right. A private store now has to
exist and be reachable. There are more ways to be locked out — hence
[secrets-recovery.md](../30-operations/secrets-recovery.md) and `admin-cli.php`.

**Forbids.** Any flag that turns authentication off. `check_secrets.py` fails the build if
`ADMIN_REQUIRE_HTTP_AUTH` returns.

**Directory Privacy remains useful** during the bootstrap window, and is removed after the sign-in
is proven — [admin-activation.md](../20-deployment/admin-activation.md).
