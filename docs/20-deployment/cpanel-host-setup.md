# cPanel host setup

**Applies to:** both

Preparing the hosting account before the first upload. Everything here is done in cPanel's web
interface or over SSH.

Current live facts — DNS, mailboxes, quotas — are recorded in
[40-reference/host-facts.md](../40-reference/host-facts.md). This page is the procedure.

---

## 1. PHP

**cPanel → MultiPHP Manager**

Select **PHP 8.1 or newer** for the domain. 8.3 is what the code was developed against.

Anything older than 8.1 will not parse — the code uses the `never` return type.

**cPanel → MultiPHP INI Editor**, worth confirming:

| | |
|---|---|
| `memory_limit` | 128M is plenty |
| `upload_max_filesize` | irrelevant; nothing here uploads files |
| `disable_functions` | must **not** contain `mail` |

`tools/host-probe.php` reports all of this, plus whether argon2id is available and how long a hash
takes.

## 2. The document root

**cPanel → Domains**

Confirm where `tech4time.bd` serves from. Normally `/home/USER/public_html`.

You need to know this exactly, because the private store is placed one level above it. If it is
anywhere unusual, read [environments.md](environments.md) before going further — the arithmetic that
places the store has one failure mode and it is worth understanding before you hit it.

## 3. SSL

**cPanel → SSL/TLS Status**

Run AutoSSL for `tech4time.bd` and `www.tech4time.bd`. Wait for certificates to issue.

The HTTPS redirect in `.htaccess` is already active, so this must work before the site is usable.
HSTS stays commented out until the site has served over HTTPS a few times —
[first-deploy.md](first-deploy.md#7-enable-hsts).

## 4. Mailboxes

**cPanel → Email Accounts**

Two are needed, and one of them is easy to overlook:

| Address | For | Must you be able to read it? |
|---|---|---|
| `info@tech4time.bd` | where the contact form sends enquiries | yes |
| `no-reply@tech4time.bd` | the envelope sender for outgoing mail | no |
| **`admin@tech4time.bd`** | **where a password reset code goes** | **yes** |

> **`admin@tech4time.bd` must exist as a real mailbox you can open.** A reset code goes there and
> nowhere else. If it does not exist, use `info@tech4time.bd` as the account address instead — but
> do that deliberately, not by accident.

## 5. DNS and deliverability

**cPanel → Zone Editor**

The reset code and every enquiry are only useful if they arrive.

| Record | Why |
|---|---|
| **MX** | mail for the domain must be handled somewhere you can read |
| **SPF** | must authorise this server to send as the domain |
| **DKIM** | cPanel signs outbound mail; confirm the selector is published |
| **DMARC** | start at `p=none` — monitoring only |

> Leave DMARC at `p=none` until reset delivery is proven. At `p=none` a failure is visible; at
> `p=quarantine` it is silently binned, which is the worst way to discover a mail problem.

Current values: [host-facts.md](../40-reference/host-facts.md).

## 6. SSH

**cPanel → SSH Access**

You want this. It is how the setup key is read, how `admin-cli.php` is run, and how a rebuild is
recovered from. cPanel's Terminal works too if SSH is unavailable.

```bash
ssh user@tech4time.bd
cat ~/t4t-private/setup-token.txt
```

## 7. Directory Privacy — temporary

**cPanel → Directory Privacy → `public_html/admin`**

Switch it on before the first deploy and remove it after the sign-in is proven. It covers the window
between the files landing and the first account existing.

Not required — the application is the lock — but there is no reason to remove it before the
replacement is proven. [admin-activation.md](admin-activation.md)

> **Never put an `.htaccess` in `admin/` in the repository.** cPanel writes its own file there for
> this feature; uploading over it silently removes the password.

## 8. Backups

**cPanel → Backup**

Confirm the backup covers the **home directory**, not only `public_html`.

`t4t-private/` lives at `/home/USER/`. A `public_html`-only backup restores your whole site except
the ability to log into it. [backups.md](../30-operations/backups.md)

---

## Before you upload

```
[ ] PHP 8.1+ selected
[ ] Document root confirmed
[ ] AutoSSL issued for the domain and www
[ ] info@ exists
[ ] admin@ exists AND you can read it
[ ] MX, SPF, DKIM published; DMARC at p=none
[ ] SSH or Terminal works
[ ] Directory Privacy on /admin, temporarily
[ ] Backup covers the home directory
```

Then [first-deploy.md](first-deploy.md).
