# 0009 — A setup token closes the bootstrap window

**Status:** accepted · **Applies to:** backend

## Decision

`admin/setup.php` demands a value that exists only in `t4t-private/setup-token.txt` on the server's
disk. It is created on demand, destroyed the moment an account exists, and skipped when the request
comes from the machine itself — by peer address, never by a header.

## Context

Whoever creates the first account owns the website. Between an upload finishing and somebody getting
round to setup, that page is reachable by anyone who finds the URL, and the gap can be days.

**Being first is not a defence.** Neither is nobody knowing the URL.

cPanel Directory Privacy also closes the window, and is recommended alongside this — but it is a
state in a control panel: strong when present, invisible when absent, and impossible for any test to
assert. It does not follow a site to a new document root, which the coming subdomain move will
create, and it lives in an `admin/.htaccess` that a careless deploy can remove.

## Consequences

**Good.** The window is shut by the code rather than by a step somebody has to remember. The
protection ships with the repository and survives a new document root. `test_admin_auth.py` asserts
the gate itself by dialling from `127.0.0.2` — still loopback, so nothing leaves the machine, but
not an address `auth_is_loopback()` accepts, so the application sees a request from elsewhere and
must demand the key. Setup requires the access whoever is setting this up already has — SSH,
Terminal or File Manager — and a stranger does not.

**Costs.** One `cat` command during setup, once in the life of the site.

**Learned the hard way.** Until this was tested, it did not work. The stored key is fourteen
characters and the guard that read it back demanded sixteen, so every call minted a fresh key and
the value the operator read was never the value they were checked against — the first account could
not have been created on the live server at all. Every other test in the suite dials from
`127.0.0.1`, where the key is deliberately skipped, so none of them could see it. A gate that no
test has ever passed through is a gate nobody has opened.

**Learned the hard way, twice.** The same blind spot produced a second defect, found on the live
host in August 2026: `setup-token.txt` was still in the private store beside a working account.
`auth_setup_done()` had deleted it, and the recovery-codes screen — which skips the "setup is over"
redirect on purpose — called `auth_setup_token()` on its next render and put it straight back.

The suite already asserted "the setup key file is gone". It asserted it from `127.0.0.1`, where the
key is never demanded and never written, so the check passed because there was nothing there to
begin with. **An assertion that can only be true is not an assertion.**

Two changes, so it cannot recur by a different route. The guard moved into `auth_setup_token()`
itself — the only place that can create the file — rather than living at the call site that happened
to be wrong; a condition on one caller fixes one caller. And `auth_setup_token_check()` now refuses
outright once an account exists, because a function that answers `''` for a finished store would
otherwise meet an empty submission in `hash_equals()` and agree with it, turning "no token" into a
valid one. `check_secrets.py` drives both through the real functions in a throwaway store, and each
was confirmed by removing it and watching the check fail.

**Also true.** The token is regenerated if the whole store is ever lost, so it protects a rebuild as
well as a first install — [rung 7](../30-operations/secrets-recovery.md#7-the-whole-store-is-gone).

**Related.** The account is not written until the authenticator has produced a valid code. An admin
enrolled but unable to produce one is an admin locked out on the first sign-in, and setup is the one
moment that is still free to put right.
