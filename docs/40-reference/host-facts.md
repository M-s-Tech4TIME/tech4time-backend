# Host facts

**Applies to:** both

The live state of the hosting account. **This file is a record, not a design** — update it whenever
something on the host changes, or it stops being useful.

Last confirmed: **2026-08-23**, before the first production deploy.

---

## The account

| | |
|---|---|
| Provider | cPanel shared hosting |
| Primary domain | `tech4time.bd` |
| Server IP | `103.138.189.25` |
| Document root | `/home/USER/public_html` *(confirm the exact username on the host)* |
| Private store | `/home/USER/t4t-private` |
| SSH | **enabled** |

## PHP

| | |
|---|---|
| Required | 8.1 or newer — the code uses the `never` return type |
| Developed against | 8.3 |
| On the host | **unconfirmed** — `tools/host-probe.php` reports it |
| argon2id | **unconfirmed** — the probe decides; bcrypt cost 12 is the fallback |
| `mail()` | expected available; the probe confirms it is not in `disable_functions` |

Run the probe, then record the answers here.

---

## Mail

### Mailboxes

| Address | Exists | For |
|---|---|---|
| `info@tech4time.bd` | **yes** | where the contact form sends enquiries |
| `no-reply@tech4time.bd` | **yes** | the envelope sender for outgoing mail |
| `admin@tech4time.bd` | **NO — must be created** | where a password reset code goes |

> **Outstanding.** `admin@tech4time.bd` must exist as a real mailbox that can be opened. A reset
> code goes there and nowhere else. Until it does, use `info@tech4time.bd` as the account address —
> deliberately, not by accident.

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

That global cap is not about this site. Somebody hammering `/admin/forgot.php` could use the
allowance up, which would stop the genuine reset from being delivered at the moment it was wanted.

---

## SSL

| | |
|---|---|
| AutoSSL | for `tech4time.bd` and `www.tech4time.bd` |
| HTTPS redirect | **active** in `.htaccess` |
| HSTS | **staged, commented out** — enable after the site is live |
| `includeSubDomains` | **off**, and must stay off until `admin.tech4time.bd` has its own certificate |

---

## Directory Privacy

Currently protecting `/admin` as a temporary measure during setup. To be removed once the
application's own sign-in is proven — [admin-activation.md](../20-deployment/admin-activation.md).

> **Never add an `.htaccess` to `admin/` in the repository.** cPanel writes its own there for this
> feature, and uploading over it silently removes the password.

---

## Outstanding on the host

1. **Create `admin@tech4time.bd`** as a mailbox that can be opened
2. **Run `tools/host-probe.php`** — upload, set the token, load once, read, **delete** — and record
   the PHP version, argon2id availability and hash time here
3. **Submit the real contact form twice**, once with JavaScript and once without; confirm both
   arrive and that replying reaches the visitor rather than `no-reply@`
4. **Enable HSTS** once the site has served over HTTPS a few times
5. **Consider `p=quarantine`** for DMARC once mail is proven
6. If `mail()` proves unreliable, the fix is authenticated SMTP against the host's own mail server —
   not more `mail()` retries

---

## Planned

| | |
|---|---|
| `admin.tech4time.bd` | the backend, its own document root **outside `public_html`** — [environments.md](../20-deployment/environments.md) |
| Deploy key | for rsync over SSH from GitHub Actions |
| Pinned `known_hosts` | for the same |

---

## Keeping this current

Update it whenever you change something on the host — a mailbox, a DNS record, the PHP version, an
SSL certificate. Put the date at the top.

Nobody will trust it if it is stale, and the whole value of the file is that somebody arriving cold
can read what is true rather than log in and work it out.
