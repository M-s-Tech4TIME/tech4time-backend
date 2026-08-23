# 0011 — Two repositories, two hosts

**Status:** accepted, **not built** · **Applies to:** both

## Decision

Split into `tech4time-website-frontend` (`tech4time.bd`) and `tech4time-website-backend`
(`admin.tech4time.bd`), communicating over a signed API.

## Context

The admin is a different application with a different audience, a different risk profile and a
different deploy cadence from a public brochure site. Today they share a document root, so a mistake
in one can affect the other, and the public site carries code that only the admin needs.

## Consequences

**Good.** The public site stops shipping authentication code entirely. The two deploy independently.
The blast radius of an admin vulnerability shrinks to a subdomain. Each gets a CI pipeline suited to
what it is.

**Costs.** Two repositories to keep in step. Deliberately minimised: the backend sanitises and
normalises at write time and publishes complete, schema-versioned documents, so the frontend needs
only two shared files — `lib/html.php` (the sanitiser, as defence in depth) and `lib/contract.php`
(schema version and shape check). Both are byte-identical across the repositories, with a CI step in
each comparing a SHA-256 against a committed value; a mismatch fails **both** builds.

**What breaks on a subdomain, catalogued:**

- `.htaccess` matches `expr=%{REQUEST_URI} =~ m#^/admin(/|$)#`. On the subdomain the URI is `/`, so
  the rule **silently stops firing** — the backend needs its own blanket `X-Robots-Tag`.
- `ADMIN_SECTIONS['view']` links `/pages/contact/` root-relative; those become absolute.
- `RewriteRule ^lib/` and `^content/` are docroot-relative — resolved by moving both outside the
  document root, which is stronger than the rewrite ever was.
- HSTS `includeSubDomains` must stay **off** until AutoSSL has issued for the subdomain.
- **The subdomain's document root must be outside `public_html`** —
  [environments.md](../20-deployment/environments.md).

**This documentation is built for it.** Every file carries an **Applies to:** line, so the split is a
move rather than a rewrite.
