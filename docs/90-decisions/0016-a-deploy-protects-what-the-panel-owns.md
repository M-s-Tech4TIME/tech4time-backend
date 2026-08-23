# 0016 — A deploy protects what the panel owns

**Status:** accepted · **Applies to:** both

## Decision

`rsync --delete` into a cPanel document root runs with an explicit **protect list**, and a separate
gate reads the dry run and fails the job if it proposes deleting anything on that list.

```
P /content/            live job posts and contact details
P /admin/.htaccess     cPanel writes it; ours must never replace it
P /.well-known/        AutoSSL's ACME challenges
P /cgi-bin/            created by cPanel
P /error_log           written by the server
P /.user.ini           MultiPHP INI Editor
P /php.ini             MultiPHP INI Editor
P /.htpasswd           Directory Privacy
```

The filters prevent the deletions. **The gate is a separate check that they did**, and it is not
redundant.

## Context

The document root is not ours alone. The repository is one contributor to it; cPanel is another,
and the server itself is a third. None of what those two write is in git, so `--delete` — which
exists to remove files dropped from the repository — treats every one of them as stale.

The costs are not equal, and that is what makes this worth a record:

- **`content/`** is the client's data. Deleting it destroys job posts and contact details typed
  into `/admin/`, and the loss is silent: the site keeps working, showing whatever the seed put
  there, until somebody notices their vacancy is gone.
- **`.well-known/`** is how AutoSSL answers ACME challenges. Deleting it breaks certificate
  renewal, and that failure surfaces *months later* as an expired certificate on a site nobody
  changed. It was not present on the host when this was written, which is exactly the trap: it
  appears at renewal time, so a deploy is only dangerous during the window that matters.
- The rest are recoverable, and are on the list because the list should not require judgement at
  three in the morning.

## Consequences

**The gate stays even though the filters make it "redundant".** They are not the same claim. The
filters are a rule the deploy is asked to follow; the gate is a reading of what the deploy actually
proposed. A typo in a filter path produces a rule that matches nothing and reports no error — the
protection silently disappears and every run goes green. Only reading the plan catches that.

That is not hypothetical: **the gate's own pattern was wrong when first written.** It matched
`^\*deleting ` with a single space, and `rsync --itemize-changes` pads the flag field, so the real
line reads `*deleting   content/careers.json` with three. The gate matched nothing, passed
everything, and looked exactly like a gate that was working. It was found by running it against a
deliberately unprotected dry run — which is now how it is verified, and the only way this kind of
check can be believed.

**Verification is a negative test.** Any change to the filters or the gate is checked by removing
the filters and confirming the gate fires. A gate that has never been seen to fail has not been
tested; it has been admired.

**Content is seeded, never synced.** `deploy/seed/` goes across with `--ignore-existing`, which
creates what is absent and overwrites nothing — so a file on the host always wins, permanently,
without anyone deciding so on the day. See [ci-cd.md](../20-deployment/ci-cd.md).

**This list grows with the host.** A panel feature that writes into the document root — Directory
Privacy, a hotlink rule, a cron that drops a file — belongs here on the day it is switched on, not
on the day a deploy removes it.
