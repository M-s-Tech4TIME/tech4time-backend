# The first deploy

**Applies to:** both

From a cPanel account with nothing on it to a working website with a working admin. Follow it in
order — several steps exist to close a window that opens if you do them in a different sequence.

Allow two hours, most of it waiting for DNS and SSL.

---

## Before you start

- [ ] cPanel access — username, password, and the login URL
- [ ] SSH access, or cPanel's Terminal
- [ ] The domain pointing at the host
- [ ] An authenticator app on your phone
- [ ] Somewhere safe to write down ten recovery codes
- [ ] Every check passing locally: see [testing.md](../10-development/testing.md)

---

## 1. Prepare the host

Full detail in [cpanel-host-setup.md](cpanel-host-setup.md). The short version:

- [ ] PHP **8.1 or newer** selected in MultiPHP Manager (8.3 preferred)
- [ ] The document root confirmed — normally `/home/USER/public_html`
- [ ] AutoSSL issued for `tech4time.bd` and `www.tech4time.bd`
- [ ] `info@tech4time.bd` exists as a mailbox
- [ ] **`admin@tech4time.bd` exists as a mailbox you can open** — a password reset code goes there
      and nowhere else

## 2. Upload the site

```
public_html/
├── index.html   404.html
├── pages/  assets/  lib/  admin/  content/
├── contact-handler.php
├── .htaccess    robots.txt   sitemap.xml   site.webmanifest
```

Everything else stays here: `tools/`, `docs/`, `references/`, `.git/`, `.claude/`, `.gitignore`,
`.gitattributes` and every Markdown file.

Rather than pick those out by hand, build the upload set and check it:

```bash
zip -r /tmp/tech4time-deploy.zip \
  index.html 404.html contact-handler.php \
  .htaccess robots.txt sitemap.xml site.webmanifest \
  pages assets lib admin content \
  -x '*/.DS_Store' -x '*.bak'

# Nothing on this list may appear. Every line should print 0.
for bad in tools/ docs/ references/ .git/ .claude/ .md admin/.htaccess .key; do
  printf '%-18s %s\n' "$bad" "$(unzip -Z1 /tmp/tech4time-deploy.zip | grep -c -- "$bad")"
done
```

Upload the zip through cPanel's File Manager and extract it in `public_html`, which also preserves
the directory structure — every asset path is root-relative, so a flattened upload breaks the site.

> `.htaccess` is a dotfile. Some FTP clients hide it and some zip tools drop it. Confirm it arrived:
> the checks in step 3 all fail without it.

**`content/` is uploaded this once**, to seed the two JSON files. Never again — from now on the
host's copy is the real one.

**`.htaccess` must be uploaded.** It carries the real security headers. `X-Frame-Options` and
`X-Content-Type-Options` are ignored by browsers when set via `<meta>`, so the `.htaccess` copy is
the one that counts.

## 3. Check it serves

- [ ] `https://tech4time.bd/` loads and is styled
- [ ] `https://tech4time.bd/pages/about/` resolves **without** `.html`
- [ ] `https://tech4time.bd/pages/careers/` renders job posts
- [ ] `https://tech4time.bd/pages/contact/` renders offices
- [ ] A nonsense URL renders `404.html`
- [ ] `https://tech4time.bd/lib/auth.php` is **403**
- [ ] `https://tech4time.bd/content/careers.json` is **403**
- [ ] `https://tech4time.bd/tools/` is **403**
- [ ] `http://` redirects to `https://`

Any of those failing means `.htaccess` is not being read. Stop and fix it — several protections
depend on it.

## 4. Probe the host

Two things can only be answered on the server, and both fail quietly.

1. Upload `tools/host-probe.php` to `public_html/` **by hand**
2. Open it, set `PROBE_TOKEN` as its header instructs
3. Load it once and read the report
4. **Delete it**

It reports the PHP version, whether argon2id is available and how long a hash takes, where the
private store resolves to and whether it is outside the web root, and whether `mail()` works.

- [ ] argon2id available (bcrypt is an acceptable fallback)
- [ ] Private store: **"Inside the web root — no, good"**
- [ ] `mail()` available
- [ ] The test message arrives at `info@tech4time.bd`
- [ ] **`host-probe.php` deleted**

## 5. Turn the admin on

This has its own page because the **order** is the safety property:
[admin-activation.md](admin-activation.md).

In brief: read the setup key off the server → create the account → pair the authenticator → save the
recovery codes → prove a full password reset works → only then remove Directory Privacy.

- [ ] The account exists and you can sign in
- [ ] The ten recovery codes are written down somewhere safe
- [ ] A full password recovery has been run end to end

## 6. Test the contact form

- [ ] Submit it with JavaScript on → arrives at `info@tech4time.bd`
- [ ] Submit it with JavaScript off → arrives, and shows the plain HTML response
- [ ] Pressing reply reaches the visitor, not `no-reply@`

## 7. Enable HSTS

**Only now**, after the site has been served over HTTPS a few times.

In `.htaccess`, find `HSTS — READY TO ENABLE` and delete the `# ` in front of its `Header`
directive.

It tells browsers never to request this site over plain http again, which closes the one
unencrypted request that happens before the redirect. That matters more than it used to: the admin
sets a session cookie, and a cookie that travels once over plain http is a cookie that has been
seen.

`includeSubDomains` and `preload` stay off deliberately — the reasoning is written above the line
itself, and `includeSubDomains` in particular must wait until `admin.tech4time.bd` has its own
certificate.

## 8. Search engines

- [ ] Submit `sitemap.xml` in Google Search Console
- [ ] Confirm `/admin/` is **not** indexed — it is covered by an `X-Robots-Tag` rule in `.htaccess`
      rather than by `robots.txt`, deliberately: listing it in `robots.txt` advertises it

## 9. Write down what you did

Update [40-reference/host-facts.md](../40-reference/host-facts.md) with anything you discovered —
the PHP version, whether argon2id was there, the hash time, mailboxes created, DNS as it stands.
That file is the record of the live host, and it is only useful if it is current.

---

## The complete checklist

```
HOST
[ ] PHP 8.1+          [ ] SSL issued        [ ] info@ mailbox
[ ] docroot confirmed [ ] admin@ mailbox you can open

UPLOAD
[ ] Site files        [ ] .htaccess         [ ] content/ (this once only)
[ ] NOT tools/        [ ] NOT docs/         [ ] NO admin/.htaccess

VERIFY
[ ] Pages load        [ ] Clean URLs        [ ] 404 works
[ ] lib/ 403          [ ] content/ 403      [ ] tools/ 403
[ ] http → https

PROBE
[ ] argon2id          [ ] store outside web root
[ ] mail() works      [ ] host-probe.php DELETED

ADMIN
[ ] Account created   [ ] Authenticator paired
[ ] Recovery codes saved                    [ ] Full reset proven
[ ] Directory Privacy removed (last)

FINISH
[ ] Contact form, both ways                 [ ] HSTS enabled
[ ] Sitemap submitted [ ] /admin not indexed
[ ] host-facts.md updated
```

---

## If it goes wrong

[troubleshooting.md](../30-operations/troubleshooting.md) is indexed by what you actually see.

The one genuinely dangerous state is **being unable to sign in to the admin**, and there is a floor
under it: [secrets-recovery.md](../30-operations/secrets-recovery.md).
