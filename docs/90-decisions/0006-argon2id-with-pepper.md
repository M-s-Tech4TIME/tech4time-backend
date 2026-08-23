# 0006 — argon2id over a peppered pre-hash

**Status:** accepted · **Applies to:** backend

## Decision

```php
$pre    = hash_hmac('sha256', $password, t4t_key('password-pepper'));
$stored = password_hash($pre, PASSWORD_ARGON2ID, ['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1]);
```

argon2id at 32 MB and three passes, over an HMAC of the password under a key from `secret.key`.
bcrypt cost 12 where argon2id is unavailable, decided by probe.

## Context

`password_hash()` already generates a fresh random salt per password and embeds it in the hash
string, so no separate salt handling is needed or wanted.

The pepper addresses a different threat: an attacker who obtains `admins.json` alone. A salt does
not help there — it prevents precomputation, not cracking. A secret in a *different file* does.

32 MB is above the 19 MB OWASP names as a floor, and below PHP's own 64 MB default — a fine number
on a server you own and an unkind one on shared hosting. It measures around 90 ms per hash.

## Consequences

**Good.** Two files must be stolen, not one. Cracking is slow by design. `password_needs_rehash()`
upgrades stored hashes at the next sign-in, so raising the cost later costs nobody a reset.

**Costs.** `secret.key` becomes critical: losing it invalidates every password hash. Recoverable via
`admin-cli.php passwd`, but it means everyone resets — hence the advice to back the file up.

**A consequence that bit us.** Recovery codes are hashed under a key derived from the same master
key, so losing it kills them too — silently, since `admin-cli list` still counts them. Documented in
[troubleshooting.md](../30-operations/troubleshooting.md); the fix is to detect the mismatch.

**Forbids.** Storing a password any other way. Any change here must keep `password_needs_rehash()`
working so existing accounts migrate transparently.
