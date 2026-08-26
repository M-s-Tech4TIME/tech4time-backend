# 0010 — The backend pushes content; the frontend never fetches

**Status:** accepted, **built** · **Applies to:** both

## Decision

After the split, `admin.tech4time.bd` owns all content and is the system of record. On save it
writes its own copy and **pushes** an HMAC-signed document to `tech4time.bd/api/publish.php`. The
public site renders from its local replica and **never calls the backend during a request**.

> **Amended when built.** The URL carries its `.php`. It was written here as `/api/publish` and is
> the plain path instead, so that the one route content travels over does not depend on a rewrite
> rule — `public/.htaccess` is not read by the local dev server, would have to be reproduced on any server
> this moves to, and is the single file most likely to arrive damaged or not at all. A path that is
> just a file works everywhere, unchanged.

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
admin with a retry, never a silent gap; and an out-of-band reconcile that re-pushes anything behind
— never during a page render.

> **Amended when built.** The revision turned out to do more than order writes. Inside the
> five-minute timestamp window a captured request is signed perfectly well, so the signature cannot
> refuse it — the revision can, and does: a document must be **strictly newer** to be written, which
> makes a replay a no-op rather than a rollback of the live site to whatever it said five minutes
> ago. It is minted inside `careers_save()` and `contact_save()` rather than at a call site, because
> the careers editor alone has six of those.
>
> `reconcile.py` needs no status endpoint either. Every answer from the endpoint carries the
> revision that host holds, refusals included, so an attempt *is* the question — and an attempt
> refused as `not-newer` has changed nothing.

**The frontend re-sanitises.** A signature proves a payload's *origin*, not its *safety*. If the
backend is ever compromised, the frontend should still not render script — so `lib/html.php` stays
on both sides.
