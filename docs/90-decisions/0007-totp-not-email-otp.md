# 0007 — TOTP as the second factor; email only for recovery

**Status:** accepted · **Applies to:** backend

## Decision

The second factor is an authenticator app (RFC 6238, implemented in `lib/totp.php`). Email carries a
one-time code for **password recovery only**, and that code alone cannot set a password — the
authenticator or a recovery code is still required.

## Context

The alternative was emailing a code at every sign-in. That makes the mailbox the second factor, and
mail is slow, sometimes undelivered, and rate-limited by cPanel. It also means a compromised mailbox
is a compromised admin.

There was no TOTP library available — no Composer, no build step — so it is hand-written, about
ninety lines, and checked against all six test vectors published in the RFC, including the one past
2^32 that catches a 32-bit counter.

## Consequences

**Good.** Sign-in works when mail does not. The factor is genuinely independent of the mailbox. A
code is accepted once, so it cannot be replayed inside its window.

**Costs.** A phone must be paired, and losing it needs recovery codes or server access. Clock drift
matters — one 30-second step either side is tolerated, and more than that needs the server's clock
fixed.

**The rule that follows.** *The emailed code alone will not set a password.* If six digits sent to a
mailbox were sufficient, that mailbox would **be** the admin password, and the second factor would
be protecting nothing at the one moment it matters most.

**Not done.** No QR code. An encoder is several hundred lines for a picture of a string every
authenticator app also accepts typed in. `img-src 'self' data:` would permit it if that changes.
