# Environments

**Applies to:** both

Where things live on each machine, and the one piece of path arithmetic that can go wrong.

**Two sites now, on two hosts.** `admin.tech4time.bd` from this repository, `tech4time.bd` from
`tech4time-frontend`. They share a cPanel account today and are meant not to need to — see
[0017](../90-decisions/0017-two-private-stores.md) and
[0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md).

---

## The two environments

| | Development | Production |
|---|---|---|
| Server | `php -S` via `tools/serve.py` | LiteSpeed on cPanel |
| Document root | `public/` | `/home/USER/backend/public` |
| Deploy target | the repository | `/home/USER/backend` |
| Private store | `../t4t-private-admin` | `/home/USER/t4t-private-admin` |
| The other half | `../tech4time-frontend`, its own `serve.py` | `tech4time.bd` |
| Publishing | `$T4T_PUBLIC_URL` at localhost | `https://tech4time.bd/api/publish.php` |
| `.htaccess` | **not read** | read — it carries the real headers |
| `mail()` | unavailable | cPanel's MTA |
| `content/*.json` | test data, in git | **live data, owned by the host** |
| The sign-in | real | real |

Deliberately the same shape in both places. The private store is a sibling of the document root
either way, so nothing about the layout differs between them.

```
DEVELOPMENT                          PRODUCTION
CodeSpace/                           /home/techtime/
├── tech4time-frontend/   ← docroot  ├── public_html/              ← docroot  (frontend)
├── tech4time-backend/               ├── backend/                  ← deploy target
│   └── public/           ← docroot  │   └── public/               ← docroot  (backend)
├── t4t-private/                     ├── t4t-private/              frontend store
└── t4t-private-admin/               └── t4t-private-admin/        backend store
```

The frontend's document root is its repository root; the backend's is `public/` inside its
repository, so its `lib/`, `sections/` and `content/` are unreachable by construction rather than by
a rewrite rule. [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) explains why
the two differ.

---

## How the private store is located

`t4t_private_dir()` in `lib/private.php`:

1. `T4T_PRIVATE`, from the environment or `$_SERVER`, if set
2. otherwise **two** levels up from the document root, `/t4t-private-admin`

Two, not one, and the difference matters. The document root here is `<repo>/public`, so one level up
is `<repo>` — which is the rsync target. A store there would be inside the thing `--delete` empties
on every deploy, and the first release after somebody relied on the arithmetic would take the
accounts with it. Two levels up is beside the repository: `/home/USER/t4t-private-admin` on the
host, `CodeSpace/t4t-private-admin` locally.

**Set `T4T_PRIVATE` explicitly anyway.** This is arithmetic and the value is the accounts.

Then it **refuses if the result is inside the document root** — before creating anything, and again
on the resolved path, because `realpath()` follows symlinks. It creates the directory 0700 and
re-asserts those permissions on every run, since a File Manager or an unpacked archive can widen
them later.

### Setting it explicitly

```apache
# .htaccess
SetEnv T4T_PRIVATE /home/USER/t4t-private
```

```ini
; PHP-FPM pool, via cPanel's "Additional Configuration"
env[T4T_PRIVATE] = /home/USER/t4t-private
```

Under FPM the value may arrive in `$_SERVER` rather than the process environment depending on how
cPanel wires it, which is why the code reads both.

The test harnesses set `T4T_PRIVATE` to a throwaway directory under `/tmp`, so a test run cannot
disturb your own account.

---

## The subdomain trap

**Read this before creating `admin.tech4time.bd`.**

cPanel has had two different defaults for where a subdomain's document root goes, and only one of
them is safe with the default path arithmetic.

**Safe** — `/home/USER/admin.tech4time.bd/`

```
/home/USER/
├── public_html/                 tech4time.bd
├── admin.tech4time.bd/          the backend
└── t4t-private/                 ← one level up from both. Correct.
```

**Not safe** — `/home/USER/the backend's document root_sub/`

```
/home/USER/
└── public_html/                 tech4time.bd  ← document root
    ├── admin_sub/               the backend   ← its document root
    └── t4t-private/             ← REACHABLE at https://tech4time.bd/t4t-private/
```

Up one level from `admin_sub` is `public_html`, so the store would be created **inside the public
site's document root**.

**The containment check would not catch it.** It compares against the *requesting* document root,
which for the subdomain is `admin_sub` — and `t4t-private` is not inside that. It would pass, and
the only thing between the internet and the authenticator secrets would be the
`RewriteRule ^t4t-private/ - [F,L]` in `.htaccess`, which is exactly the kind of protection the
store was placed outside the web root to avoid depending on.

**Do both of these:**

1. Give the subdomain a document root **outside `public_html`** when you create it. This is the
   right shape anyway — the backend is a separate site, not a folder of the public one.
2. Set `T4T_PRIVATE` explicitly rather than relying on the arithmetic.

> **That question is settled: one store each.**
> [0017](../90-decisions/0017-two-private-stores.md).
>
> The frontend's holds three things — `secret.key`, `throttle.json` and `publish.key` — and its
> `T4T_PRIVATE_FILES` has no *name* for an account file, so there is no path on that host for a
> password hash to be written to. The backend's holds the accounts, the sessions, the audit log and
> its own unrelated master key.
>
> The one value both hold is `publish.key`, and it is the **same bytes** on purpose:
> `tools/make_publish_key.py` prints it once and a person places it on both. It is never derived
> from `secret.key` — the two master keys differ by construction, so a derived value would differ
> too and every publish would be refused.
>
> **The backend must set `T4T_PRIVATE` explicitly**, to `/home/USER/t4t-private-admin`. The default
> arithmetic would put it at `backend/t4t-private-admin`, inside the deploy target, where
> `rsync --delete` would remove it.

---

## Development data vs production data

The two are genuinely different things, and conflating them is how live content gets destroyed.

**Careers** varies the most. The site will launch with **no job posts**. Every job post that ever
exists in production is created on the live server, through the admin, and never comes from a
deploy.

**The development data matters** — several job posts, every contact field populated. It is what
exercises the renderers, and an empty JSON file tests nothing. Keep it rich, keep it in git, and
keep it away from the server.

**Everything else** — the other fourteen pages — is part of the website itself, lives in the
repository, and is deployed normally.

**And on this side, `content/` is the system of record.** Everything the public site shows for those
two pages is a copy of what is here. Losing it is the loss that matters — the frontend's copy is
reconstructible from this one with `tools/reconcile.py`, and not the other way round.

| | repository holds | production gets | owner afterwards |
|---|---|---|---|
| `careers.json` | rich test data | seeded empty, once | **the live server** |
| `contact.json` | today's real content | seeded once | **the live server** |
| every other page | the page itself | deployed every time | the repository |

**That improvement is built.** `deploy/seed/` holds a `careers.json` with `jobs: []`, and CI copies
it with `rsync --ignore-existing`: it creates a data file when there is none and overwrites nothing.
Losing live edits stopped depending on anyone remembering an exclude rule.

It is a **seed, not a migration.** On a host that should have inherited content — the backend
standing up beside a public site that already has job posts — the content has to be copied across by
hand *before the first save*, or the first save publishes an empty document over the live page.
[first-deploy.md](first-deploy.md)

---

## What differs locally, and what that costs you

**`.htaccess` is not read** by PHP's built-in server. Locally you do not get the security headers,
the caching rules, the compression, or the blocking of `lib/`, `content/` and `tools/`.
`tools/dev-router.php` reproduces the URL shapes and nothing else.

**A change to `.htaccess` cannot be verified locally.** Verify it on the host, and read
[security-model.md](../40-reference/security-model.md) first.

**`mail()` does not work.** The contact form validates and answers correctly, then reports that it
could not send. A password reset code has nowhere to go — use a recovery code. Both paths are proven
on the host with `tools/host-probe.php`.
