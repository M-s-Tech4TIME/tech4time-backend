# 0017 — Two private stores, one per half

**Status:** accepted, **built** · **Applies to:** both

## Decision

The frontend and the backend each get their own private store, and neither can read the other's.

```
/home/USER/t4t-private/         frontend   secret.key, throttle.json, publish.key
/home/USER/t4t-private-admin/   backend    secret.key, admins.json, sessions/,
                                           resets.json, audit.log, publish.key
```

The one thing both hold is `publish.key`, and it is deliberately **the same bytes** — it is what the
two sign to each other with. Everything else, including the master key, is separate and unrelated.

## Context

[environments.md](../20-deployment/environments.md) left this open, and said why it was hard: the
two sites share a host today, sharing is simpler, and the frontend genuinely does touch a store —
`tech4time-website-frontend/contact-handler.php` keeps its rate-limit counters there.

What settles it is a fact that was not on the table when the question was first written down. The
split exists because the two halves are meant to end up on **different machines**: the public site
on one server, the admin on another, talking over the API. On that day the frontend must be able to
run with no access to the backend's secrets at all, because there will be no path to them.

Building on a shared store now means migrating off it later, at the moment when everything else is
also moving.

## Consequences

**Good.** The public site holds no password hash, no authenticator secret, no session and no
recovery code. It is not a convention: the frontend's `T4T_PRIVATE_FILES` has **three entries**, and
`t4t_private_path()` throws on a name it does not know — so there is no path on that host for a
credential to be written to. `tools/check_secrets.py` asserts it on every run.

The two master keys are unrelated, so rotating one (*secrets-recovery.md*, in tech4time-website-backend) has no
effect on the other. The frontend's `secret.key` peppers nothing but the throttle's keys, which is
the only thing it was ever doing there.

**Costs.** One value has to be copied by hand, once: `publish.key`. That is the price of two stores,
and it is paid deliberately rather than hidden — see below.

**`publish.key` is never derived, and never created on demand.** Deriving it from `secret.key` is
the obvious idea and does not work: the two master keys differ by construction, so the derived
values would differ too and every publish would be refused. Creating it on demand is worse — it
would appear *differently* on each host, and the failure would read as "signature rejected" for as
long as it took somebody to think of it.

So both sides refuse to start publishing without one and say what to do, and
`tools/make_publish_key.py` prints the value once for a person to place on both hosts. Every
signature carries the key's fingerprint, per
*0014 — a value derived from the master key carries the key's name* (in tech4time-website-backend), so a mismatch answers *"the live site holds a
different publish key"* rather than *"wrong signature"*.

**The containment check still compares against the requesting document root.** On a host running
both sites that is a real gap: a store beside one document root is not inside the other, so the
check would pass on a layout that was nevertheless wrong. Two stores do not fix it; what fixes it is
[0018](0018-the-backend-serves-from-a-subdirectory.md), which puts the backend's document root
somewhere a sibling cannot be reached from at all.

**Related.** [0008](0008-private-store-outside-docroot.md) for why a store sits outside the document
root at all, and [the publish API](../10-development/server-side/publish-api.md) for what the shared
key is used for.
