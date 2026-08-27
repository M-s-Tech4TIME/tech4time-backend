# Host facts

**Applies to:** both

The live state of the hosting account. **This file is a record, not a design** — update it whenever
something on the host changes, or it stops being useful.

Last confirmed: **2026-08-23**, from `tools/host-probe.php` run on the live host.
Last reviewed against the repository: **2026-08-27**.

---

## The account

| | |
|---|---|
| Provider | cPanel shared hosting |
| Web server | **LiteSpeed** |
| Primary domain | `tech4time.bd` |
| Server IP | `103.138.189.25` |
| Account | `techtime` |
| Document root | `/home/techtime/public_html` |
| Private store | `/home/techtime/t4t-private-admin` — 0700, writable, **outside the web root** |
| HTTPS | on, AutoSSL |
| SSH | **enabled** on port 22, with Terminal and Git Version Control also available |
| SSH host key | `SHA256:bSJs6qWqhlP3gLNzXrKClg5uP0zQoOYypucJH6fOF0U` (ED25519) |

## PHP

| | |
|---|---|
| Required | 8.1 or newer — the code uses the `never` return type |
| Developed against | 8.3 |
| On the host | **8.2.33** |
| argon2id | **available** — this is what is used; bcrypt is not needed |
| One hash costs | **80 ms** — the deliberate expense that makes an offline attack slow |
| `mbstring`, `dom` | both present |
| `random_bytes`, sessions | both present |
| `mail()` | available; `sendmail_path` is `/usr/sbin/sendmail -t -i` |

Mail was proven end to end: the contact form delivers to `info@tech4time.bd` with JavaScript on
and off.

### If the deploy fails on the host key

`deploy.yml` pins that fingerprint in `known_hosts` rather than accepting whatever answers, so a
changed key **stops the deploy** instead of trusting it. Shared hosts do move accounts between
machines, so this can happen for an innocent reason — but it looks identical to the guilty one, and
the check exists because you cannot tell them apart by looking.

Confirm the new key out of band before updating the `SSH_HOST_KEY` secret: ask the host, or read
the fingerprint from cPanel's own Terminal, rather than from another `ssh-keyscan` — which would
ask the same question of the same answerer.

```bash
ssh-keyscan -p 22 -t ed25519 103.138.189.25 2>/dev/null | ssh-keygen -lf -
```

`SSH_HOST` must stay the bare IP. The pinned line names the host as `103.138.189.25`, and a
hostname in that secret would not match it.

> **The probe itself is gone, and must stay gone.** `tools/host-probe.php` is upload-run-delete.
> It was left on the host after the first run and was reachable at `/host-probe.php` — token-gated,
> but it sends mail on every request and reports the PHP build, the paths and the store location.
> `verify_live.py` now asserts `/tools/host-probe.php` answers 403 on every deploy.
>
> One trap worth knowing, because it cost an hour: **the probe's output caches in the browser.**
> Re-running the same URL after creating the admin account reported `Account set up: no` from cache
> while `admins.json` sat on disk. Re-request with a changed query string, or use another browser.

---

## Mail

### Mailboxes

| Address | Exists | For |
|---|---|---|
| `info@tech4time.bd` | **yes** | where the contact form sends enquiries |
| `no-reply@tech4time.bd` | **yes** | the envelope sender for outgoing mail |
| `admin@tech4time.bd` | **yes** — created 2026-08-23 | where a password reset code goes |

> **Created, not yet proven.** Existing and receiving are different facts, and only the second one
> matters on the day you cannot sign in. It is proven by
> [admin-activation.md](../20-deployment/admin-activation.md) step 6 — a real reset code, sent by
> the live site, read in that mailbox — not by sending a test message to it from elsewhere, which
> exercises none of the path that matters.

### DNS — confirmed

| Record | Value | |
|---|---|---|
| **MX** | `0 tech4time.bd` | mail for the domain is handled by **the web server itself** and never leaves the box |
| **SPF** | `v=spf1 +a +mx +ip4:103.138.189.25 include:spf.mysecurecloudhost.com ~all` | authorises this server to send as the domain |
| **DKIM** | cPanel `default` selector | outbound mail is signed |
| **DMARC** | `v=DMARC1; p=none;` | **monitoring only** |

**DMARC is deliberately at `p=none`.** Worth tightening to `p=quarantine` once reset delivery is
proven — not before. At `p=none` a failure is visible; at `p=quarantine` it is silently binned,
which is the worst way to discover a mail problem.

### Quotas

cPanel enforces an **hourly outbound mail limit**. The reset throttle is sized to stay under it:
three per hour per account, five per address, **twenty overall**.

That global cap is not about this site. Somebody hammering `/forgot.php` could use the
allowance up, which would stop the genuine reset from being delivered at the moment it was wanted.

---

## SSL

| | |
|---|---|
| AutoSSL | for `tech4time.bd` and `www.tech4time.bd` |
| HTTPS redirect | **active** in `public/.htaccess` |
| HSTS | **active** — `max-age=31536000`, `public/.htaccess:55`. No `preload`, deliberately |
| `includeSubDomains` | **off**, and must stay off until `admin.tech4time.bd` has its own certificate |

---

## Directory Privacy

There is no `/admin` directory on this host any more — the editor is a document root of its own,
`admin.tech4time.bd/public/`. Directory Privacy is optional here, and was only ever a temporary lock
while the application's own sign-in was being proven —
[admin-activation.md](../20-deployment/admin-activation.md).

> **Do not leave it on `public/`.** cPanel writes its own `.htaccess` into whatever directory it
> protects, and `public/.htaccess` is a file this repository ships — so the next deploy uploads ours
> over cPanel's and removes the password silently.

---

## Outstanding on the host

1. **Prove `admin@tech4time.bd` receives** what this host sends it — the mailbox exists as of
   2026-08-23, and delivery is confirmed at [admin-activation.md](../20-deployment/admin-activation.md)
   step 6, by a real reset code read in that mailbox. Existing and receiving are different facts,
   and only the second one matters on the day you cannot sign in
2. **Prove a reply reaches the visitor.** Both contact-form paths were confirmed arriving at
   `info@tech4time.bd` on 2026-08-23, with JavaScript and without. Pressing Reply on one of those
   mails and seeing it addressed to the visitor rather than `no-reply@` has not been checked — that
   form is `tech4time-website-frontend/contact-handler.php`, not this half
3. **Consider `p=quarantine`** for DMARC once reset delivery is proven — not before, because at
   `p=none` a failure is visible and at `p=quarantine` it is silently binned
4. If `mail()` proves unreliable, the fix is authenticated SMTP against the host's own mail server —
   not more `mail()` retries

Two items that stood here are done and are recorded above rather than pending: `tools/host-probe.php`
ran on 2026-08-23 (its figures are the PHP and signing rows at the top of this file, and the probe
itself was deleted from the host), and HSTS is active.

---

## Built since this file first named them as planned

| | |
|---|---|
| `admin.tech4time.bd` | this half, serving from `admin.tech4time.bd/public/` — its own document root, outside `public_html` — [environments.md](../20-deployment/environments.md) · [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) |
| Deploy key | rsync over SSH from GitHub Actions, in place for both halves — [ci-cd.md](../20-deployment/ci-cd.md) |
| Pinned `known_hosts` | the host key comes from a secret rather than `ssh-keyscan` on each run |

Nothing on the host is planned-but-absent today. What is left is the list above this one — things
to *prove*, not things to build.

---

## Keeping this current

Update it whenever you change something on the host — a mailbox, a DNS record, the PHP version, an
SSL certificate. Put the date at the top.

Nobody will trust it if it is stale, and the whole value of the file is that somebody arriving cold
can read what is true rather than log in and work it out.
