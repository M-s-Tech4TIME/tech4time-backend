# 0002 — No database

**Status:** accepted · **Applies to:** both

## Decision

Editable content is JSON files on disk, written atomically. Admin accounts are a JSON file too.

## Context

Two pages have content that changes without a redeploy, and the admin needs accounts. On cPanel a
database is a separate product with its own credentials, its own backup story and its own failure
modes.

The data is tiny — two files, a handful of accounts — and read far more often than written.

## Consequences

**Good.** Nothing to provision, migrate or separately back up. The content is human-readable and
diffable. A page render is a filesystem read. Restoring is copying a file back.

**Costs.** No transactions and no query language. Concurrent writes need care — `store_write()` is
atomic via rename, and `store_edit()` locks for read-modify-write, which is why counters use the
latter. This does not scale to thousands of records, and does not need to.

**Forbids.** Reaching for MySQL when a feature feels relational. If the data outgrows flat files that
is a real signal — but two JSON files are not close to it.
