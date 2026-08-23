# Operations

**Applies to:** both

Keeping the site running once it is live.

| | |
|---|---|
| [content-runbook.md](content-runbook.md) | day-to-day content work, for whoever maintains the site |
| [troubleshooting.md](troubleshooting.md) | **symptom → cause → fix**, indexed by what you see |
| [secrets-recovery.md](secrets-recovery.md) | every way back into the admin, tested |
| [backups.md](backups.md) | what to back up, and proving a restore works |

---

## In an emergency

| | |
|---|---|
| **Cannot sign in to the admin** | [secrets-recovery.md](secrets-recovery.md) — find your rung in the first table |
| **The site is down or looks broken** | [troubleshooting.md](troubleshooting.md) |
| **Live content has been overwritten by a deploy** | `content/*.json.bak` on the host holds one generation. Then [backups.md](backups.md) |
| **You think the secrets were stolen** | [rung 8](secrets-recovery.md#8-suspected-compromise) — rotate everything, in order |

---

## The five-minute health check

```bash
php ~/admin-cli.php list        # accounts, 2FA paired, codes remaining, last sign-in
php ~/admin-cli.php log 25      # recent sign-in attempts, successful and not
php ~/admin-cli.php where       # which files the admin is using
```

Plus, in a browser: the homepage loads, `/pages/careers/` renders, `/admin/` signs in, and
`https://tech4time.bd/content/careers.json` returns **403**.

That last one is the check nobody thinks to run and the one that fails silently — an `.htaccess`
that did not upload takes the blocking rules with it, and nothing about the site's appearance will
tell you.

---

## Things worth doing before you need them

- [ ] A full password recovery, run once deliberately while everything works
- [ ] `secret.key` in a password manager
- [ ] The cPanel backup confirmed to cover the **home directory**
- [ ] Recovery codes somewhere that is not this server and not a browser tab
- [ ] `admin@tech4time.bd` confirmed to be a mailbox you can open
