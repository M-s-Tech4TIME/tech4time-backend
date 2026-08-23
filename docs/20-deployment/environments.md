# Environments

**Applies to:** both

Where things live on each machine, and the one piece of path arithmetic that can go wrong.

---

## The two environments

| | Development | Production |
|---|---|---|
| Server | `php -S` via `tools/serve.py` | Apache on cPanel |
| Document root | the repository | `/home/USER/public_html` |
| Private store | `../t4t-private` | `/home/USER/t4t-private` |
| `.htaccess` | **not read** | read — it carries the real headers |
| `mail()` | unavailable | cPanel's MTA |
| `content/*.json` | test data, in git | **live data, owned by the host** |
| The sign-in | real | real |

Deliberately the same shape in both places. The private store is a sibling of the document root
either way, so nothing about the layout differs between them.

```
DEVELOPMENT                         PRODUCTION
CodeSpace/                          /home/tech4tim/
├── tech4time-website/    ← docroot ├── public_html/          ← docroot
└── t4t-private/                    └── t4t-private/
```

---

## How the private store is located

`t4t_private_dir()` in `lib/private.php`:

1. `T4T_PRIVATE`, from the environment or `$_SERVER`, if set
2. otherwise `dirname(DOCUMENT_ROOT) . '/t4t-private'`

On cPanel that arithmetic lands on `/home/USER/t4t-private` by construction. Nothing needs
configuring.

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

**Not safe** — `/home/USER/public_html/admin_sub/`

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

> **Open question for the split:** whether the two sites share one private store or get one each.
> They share by default, and the frontend does touch it — `contact-handler.php` keeps its rate-limit
> counters there. Sharing is simpler; separate stores mean the public site holds no password hashes
> at all, which is the better boundary for something two repositories and two pipelines will write
> to. Not decided.

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

| | repository holds | production gets | owner afterwards |
|---|---|---|---|
| `careers.json` | rich test data | seeded empty, once | **the live server** |
| `contact.json` | today's real content | seeded once | **the live server** |
| every other page | the page itself | deployed every time | the repository |

The planned improvement is seed files — `careers.seed.json` with `jobs: []`, copied into place
**only if the target is absent** — so a deploy creates a data file when there is none and never
overwrites one. Losing live edits stops depending on anyone remembering an exclude rule. Not built
yet.

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
