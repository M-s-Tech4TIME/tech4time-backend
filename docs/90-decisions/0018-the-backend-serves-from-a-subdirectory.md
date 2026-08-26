# 0018 — The backend serves from a subdirectory of its repository

**Status:** accepted, **built** · **Applies to:** both

## Decision

`admin.tech4time.bd`'s document root is **`public/` inside the backend repository**, not the
repository root.

```
/home/USER/admin.tech4time.bd/            ← the deploy target
├── public/                    ← the document root. Everything the browser may ask for
│   ├── index.php login.php setup.php logout.php forgot.php reset.php
│   ├── .htaccess
│   └── assets/
├── lib/                       ← outside it. Cannot be requested at all
├── sections/                  ← likewise: included by index.php, never fetched
└── content/                   ← likewise: the system of record
```

The frontend keeps its existing shape — repository root *is* document root — and that asymmetry is
deliberate. See below.

## Context

[0011](0011-two-repositories.md) catalogued what breaks on a subdomain and noted the resolution
already: *"`RewriteRule ^lib/` and `^content/` are docroot-relative — resolved by moving both
outside the document root, which is stronger than the rewrite."*

The deciding argument is the one [0008](0008-private-store-outside-docroot.md) already made about
the private store, applied one level out:

> A directory rule is a policy the server chooses to apply. A file outside the document root cannot
> be requested at all, because no URL maps to it.

The backend is a new site on a new subdomain, which means a **new `public/.htaccess` that has never been
tested on that host**. The frontend's has been proven against the live server; a fresh one for a
subdomain has not. Making the admin's secrecy depend on it is making it depend on the least-tested
file in the deploy — and on the one most likely to arrive damaged or not at all.

It is also where this is going. On a self-hosted nginx there is no `public/.htaccess` at all, and a
`public/` root is the shape every server already understands.

## Consequences

**Good.** `lib/`, `sections/` and `content/` are unreachable by construction. The backend still
ships an `public/.htaccess`, but only for headers — the CSP, HSTS and the blanket `X-Robots-Tag` that
[0011](0011-two-repositories.md) says the subdomain needs, since the frontend's rule matches
`^/admin(/|$)` and the subdomain's URI is `/`. **Nothing depends on it for secrecy.** Delete it and
the admin becomes indexable and unhardened; it does not become readable.

`sections/*.php` stop being web-reachable files that happen to refuse to run on their own. The
`defined('T4T_ADMIN')` guard at the top of each stays — it costs nothing, and a guard that is
unnecessary today is exactly what protects against a document root pointed one level too high
tomorrow.

**Costs.** The two repositories have different shapes, which is one more thing to know. It is
recorded here because it will look like an inconsistency to whoever meets it first.

The asymmetry is justified rather than tolerated: **the frontend is a document tree and the backend
is an application.** The public site's files *are* its URLs — sixteen pages at the paths they are
served from — and giving it a `public/` root would move every one of them to gain protection it
already has and has verified in production. The admin has six entry points and everything else is
internal.

**PHP reaches into `public/` for two things.** The icon sprite is read from disk and inlined, and
the flag images are listed for the editor's picker, so
`tech4time-website-backend/lib/admin.php` and `tech4time-website-backend/lib/contact.php` name `../public/assets/…`. That is one directory of assets, in the place the browser needs it, read by
the server rather than copied twice.

**The private store is not inside the deploy target.** `T4T_PRIVATE` is set explicitly to
`/home/USER/t4t-private-admin`, beside `public_html` rather than inside `backend/` — because
`rsync --delete` onto `backend/` would otherwise remove it. The default path arithmetic would have
landed it at `admin.tech4time.bd/t4t-private-admin`, which is exactly that mistake.

**Related.** [0017](0017-two-private-stores.md) for the stores themselves, and
[environments.md](../20-deployment/environments.md) for the subdomain trap this avoids.
