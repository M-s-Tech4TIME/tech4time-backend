# 0014 — A value derived from the master key carries the key's name

**Status:** accepted · **Applies to:** backend

## Decision

A stored recovery code is `fingerprint:digest`. The fingerprint is
`t4t_key_fingerprint()` — an HMAC of a fixed label under the master key, truncated — which names the
key without revealing it.

`auth_recovery_state()` reports how many of an account's codes are `live`, `dead` or `unmarked`, and
`admin-cli list` prints that instead of counting entries.

## Context

Recovery codes are hashed under `t4t_key('recovery')`, derived from `secret.key`. Lose that file and
all ten are permanently unverifiable.

Nothing said so. The account still held ten entries, `admin-cli list` counted entries, and so the
one place a person looks to check whether they still have a way in answered **`CODES 10`** — right
up until they tried one. The recovery ladder's own instruction was "always run `codes` after a key
loss", which is a rule that has to be remembered at exactly the moment somebody is least able to
think clearly.

The marker could have been recorded once per account. It is on the value instead because **seven
places across five files write a password hash or a set of codes**, and a stamp applied at each of
them is a stamp somebody forgets at the eighth — the same shape as the defect being fixed. On the
value it cannot be forgotten: whatever produces a stored code produces the marker with it.

## Consequences

**Good.** A dead code is recognisable as dead rather than merely failing to match, so the difference
between "wrong code" and "made under a key this server no longer has" reaches the person who needs
it. `admin-cli list` says `10 DEAD` and names the two commands that put it right.

**Costs.** Sixteen more characters per stored code, and stored codes are no longer a bare digest —
anything reading them directly must go through `auth_recovery_matches()`.

**Backward compatible.** A stored value with no `:` predates the marker and is still accepted on its
digest alone. That is exactly as safe as it was: a different key would not have produced that digest
either. Such codes are reported as `unmarked` rather than counted as live, because nothing can tell
whether they still verify and claiming either way would be unsupported.

**What it does not cover.** The password. It is argon2id over a peppered pre-hash, and marking the
hash string would mean parsing around `password_verify()`. The CLI infers it instead — dead codes
mean the pepper changed too, so it says the password went with them. A password on an account with
no recovery codes at all is still an unexplained failure.

**Related.** [0006](0006-argon2id-with-pepper.md) for why the pepper is derived rather than stored,
and [rung 5](../30-operations/secrets-recovery.md#5-secretkey-lost-or-corrupted) for what to do when
the key is gone.
