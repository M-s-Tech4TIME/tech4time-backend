# 0013 — A damaged store refuses; it never looks empty

**Status:** accepted · **Applies to:** backend

## Decision

`store_state()` tells `ok`, `missing`, `unreadable` and `corrupt` apart. Two callers act on the
difference:

- **`auth_problem()`** refuses to start the admin when the account file is present but will not
  parse, rather than reporting no accounts and offering setup.
- **`store_write()`** will not copy a damaged file over a good `.bak`.

`store_read()` is unchanged. It still answers `null` for anything unusable, which is what the public
pages want.

## Context

`store_read()` returned `null` for a file that was absent and for one that was damaged. For site
copy that is right: both mean "fall back to the defaults", the page renders, and a visitor can still
act on it.

For the account file the two mean opposite things. Absent means nobody has set this site up yet.
Damaged means every credential is in a file that will not parse — and `auth_has_accounts()` said
*no* to both, so the admin offered `setup.php` on a site that already had an administrator.

The damage was not in the offer. It was in accepting it: `store_write()` copies the current file to
`.bak` before writing, so the first save would copy the damaged file over the backup. **The screen
suggested the one action that destroyed what you would have recovered from**, and the operator
following it had no way to know.

The same shape reached content. A corrupt `careers.json` reads as no jobs, so the editor shows an
empty list, and saving one post would have copied the damage over the backup holding every existing
post.

## Consequences

**Good.** The account file case stops at a page that says what is wrong and how to fix it. The
content case keeps its backup, so the `.bak` that [secrets-recovery.md](../30-operations/secrets-recovery.md)
and [backups.md](../30-operations/backups.md) tell people to restore from is still there when they
reach for it.

**Costs.** Two extra `store_state()` calls per write — two reads of a file already on the page's
hot path, against a data-loss window that closes.

**A limit worth stating.** The content editors still *show* an empty page for a damaged store rather
than saying so. The backup survives, so the loss is recoverable, but the operator is not told. Doing
better means the admin knowing which file each section owns, which is a change to the section
contract in [adding-an-editor.md](../10-development/backend/adding-an-editor.md).

**Related.** The public pages deliberately keep the old behaviour. A careers page that renders "no
openings" because the file is damaged is wrong; one that renders a PHP error is worse, and the
visitor can act on the first.

**How it was found.** Not by reading the code — it was written down as a known trap and left. It
became a defect worth fixing when [0009](0009-setup-token.md) showed what an unexercised failure
path is worth. `tools/test_store.py` exists because `lib/store.php` had no test, which is how the
distinction came to be missing.
