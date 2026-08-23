# 0010 — The backend pushes content; the frontend never fetches

**Status:** accepted, **not built** · **Applies to:** both

## Decision

After the split, `admin.tech4time.bd` owns all content and is the system of record. On save it
writes its own copy and **pushes** an HMAC-signed document to `tech4time.bd/api/publish`. The public
site renders from its local replica and **never calls the backend during a request**.

```
SAVE   admin → writes its record → POST (signed) → frontend verifies, sanitises, writes
VISIT  visitor → frontend reads its local file → renders          ✗ never calls the backend
```

## Context

The alternative is the frontend fetching content from the backend per page view. That is the usual
shape, and it is wrong here for three reasons:

- **Indexability.** Runtime-fetched content was rejected in [0003](0003-server-rendered-content.md)
  precisely so search engines get a reliably server-rendered page. A per-view API call reintroduces
  that risk on the contact page — the one most often searched for by name.
- **Availability.** The admin host going down would take the public site's content with it.
- **Speed.** Content is a filesystem read today. An HTTP round trip adds 50–300 ms to every request
  and turns every visitor into load on a panel sized for a handful of users.

## Consequences

**Good.** The public site keeps its current performance and has no runtime dependency on the admin.
Edits appear within a second, because the push happens on save rather than on a schedule.

**Costs.** A failed push means the two disagree. Handled by: a monotonic `revision` on every
document, so a retry or a reordered request cannot roll content backwards; visible failure in the
admin with a retry, never a silent gap; and an out-of-band reconcile that compares revisions and
re-pushes anything behind — never during a page render.

**The frontend re-sanitises.** A signature proves a payload's *origin*, not its *safety*. If the
backend is ever compromised, the frontend should still not render script — so `lib/html.php` stays
on both sides.
