# Routine deploys

**Applies to:** both

Pushing an update to a site that is already live, without destroying anything people have written.

---

## The one rule

> **The host's `content/` is the real data. Yours is test data.**

Job posts and contact details are written by people using `/admin/`. They are not in your working
copy. A deploy that includes `content/` destroys them, and the loss is silent — the site keeps
working, showing older content, until somebody notices their job post is gone.

---

## What to upload, and what never to

```
UPLOAD                            NEVER UPLOAD
  index.html   404.html             content/          ← live data
  pages/                            tools/            ← scripts, incl. password reset
  assets/                           docs/
  lib/                              references/
  admin/                            .git/
  contact-handler.php               *.md   *.py
  .htaccess                         admin/.htaccess   ← cPanel owns it
  robots.txt   sitemap.xml
  site.webmanifest
```

### With rsync

```bash
rsync -avz --delete \
  --exclude='content/' \
  --exclude='tools/' \
  --exclude='docs/' \
  --exclude='references/' \
  --exclude='.git*' \
  --exclude='*.md' \
  --exclude='*.py' \
  --exclude='admin/.htaccess' \
  ./ user@tech4time.bd:~/public_html/
```

**`--delete` without those excludes will remove live content.** Run it with `--dry-run` first, every
time. Read the output. Look specifically for `deleting content/`.

### With SFTP or File Manager

Upload the changed directories only. Never drag the whole repository across.

---

## Before you upload

```bash
python3 tools/check_contrast.py
python3 tools/inject_icons.py --check
python3 tools/check_shared_markup.py
python3 tools/check_content_model.py
python3 tools/check_secrets.py
python3 tools/check_docs.py
python3 tools/audit_pages.py
```

And if the change touched anything server-side:

```bash
python3 tools/test_admin_auth.py
python3 tools/test_contact_handler.py
python3 tools/test_careers_admin.py
python3 tools/test_contact_admin.py
```

---

## After you upload

- [ ] The changed pages look right
- [ ] `/pages/careers/` and `/pages/contact/` still render — **live content intact**
- [ ] `/admin/` still signs in
- [ ] `lib/`, `content/` and `tools/` still return 403

That third check matters more than it looks: an `.htaccess` that failed to upload takes the blocking
rules with it, and nothing about the site's appearance will tell you.

---

## Cache busting

Asset filenames are not content-hashed — there is no build step to hash them — and `.htaccess`
caches CSS, JS and fonts for a year.

**A changed `base.css` will not reach returning visitors on its own.**

When you change one, either append a version query to the tag:

```html
<link rel="stylesheet" href="/assets/css/base.css?v=2">
```

(via `tools/templates/head.html`, then `propagate_shared.py`) or lower the `max-age` for that file
type in `.htaccess`.

---

## Changing the footer's contact details

The footer is markup, not content, so the editor cannot reach it. The sequence is:

```bash
# 1. the server's copy is the real one — download it first
scp user@tech4time.bd:~/public_html/content/contact.json content/contact.json

# 2. push the details into every page
python3 tools/sync_site_contact.py
python3 tools/check_shared_markup.py

# 3. upload the PAGES — and not content/
```

Getting step 1 wrong pushes your stale local details into all sixteen pages.

The admin shows a banner when the JSON and the footers disagree, so the gap is never invisible — but
closing it is a deploy, not a save. [shared-markup.md](../10-development/frontend/shared-markup.md)

---

## Rolling back

There is no deploy history — the server holds one copy of the site.

- **Code** is in git. Check out the previous commit and re-upload.
- **Content** has one generation of backup on the host: `content/careers.json.bak`, written on every
  save. Restore by renaming it.
- **Anything older** comes from the cPanel backup. [backups.md](../30-operations/backups.md)

---

## When CI/CD arrives

The planned GitHub Actions workflow is `test` on pull request, `test` then `deploy` on push to
`main`, deploying by rsync over SSH with a deploy key.

The exclude list above becomes a `.deployignore`, which is an improvement worth naming: **losing
live content stops depending on anyone remembering an exclude flag.** Not built yet.
