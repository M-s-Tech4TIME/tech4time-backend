# Deployment

**Applies to:** both

The site deploys by uploading files. There is no build step, so what is in the repository is what
runs on the server.

---

## Current status

**The site is live at `https://tech4time.bd`**, on cPanel with LiteSpeed and PHP 8.2. The first
deploy was done by hand through the File Manager; the admin sign-in is set up and working.

**Deploys now go through GitHub Actions** — push to `main`, checks run, rsync over SSH, and the
site is asked afterwards whether its protections are still in place. See [ci-cd.md](ci-cd.md).
[routine-deploys.md](routine-deploys.md) is the manual fallback for when the pipeline cannot run.

---

## In this section

| | |
|---|---|
| [first-deploy.md](first-deploy.md) | scratch → live website, in order |
| [cpanel-host-setup.md](cpanel-host-setup.md) | the host: domains, SSL, PHP, mailboxes, DNS |
| [admin-activation.md](admin-activation.md) | the setup key, the first account, the cutover order |
| [ci-cd.md](ci-cd.md) | **the pipeline**: checks on every push, deploy on `main` |
| [routine-deploys.md](routine-deploys.md) | pushing an update by hand, when the pipeline cannot |
| [environments.md](environments.md) | document roots, `T4T_PRIVATE`, dev data vs production data |

---

## The three rules

Everything else is detail.

### 1. Never upload `content/`

The host's `content/careers.json` and `content/contact.json` are the **real data**, written by people
using `/admin/`. A deploy that includes them destroys live job posts and contact details.

### 2. Never upload `tools/`

It contains scripts that manipulate the site and two that can reset an admin password. `.htaccess`
blocks the path as a backstop; the rule is that it is never uploaded at all.

### 3. Never upload an `admin/.htaccess`

cPanel writes its own file there. Uploading over it silently removes whatever protection it was
applying. There is no `.htaccess` in `admin/` in this repository, and there must never be one.

---

## What gets uploaded

```
UPLOAD                          DO NOT UPLOAD
  index.html  404.html            tools/
  pages/                          docs/
  assets/                         references/
  lib/                            .git/
  admin/                          *.md
  contact-handler.php             content/          ← after the first deploy
  .htaccess                       admin/.htaccess   ← ever
  robots.txt  sitemap.xml
  site.webmanifest
```

`content/` is uploaded **once**, on the very first deploy, to seed the files. Never again.

---

## The order that matters

Turning the admin on has a sequence, and the sequence *is* the safety property — it exists so the
window in which somebody else could create the first admin account never opens.

[admin-activation.md](admin-activation.md) has it. Do not improvise it.
