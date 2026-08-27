# The first deploy

**Applies to:** backend

From a cPanel account with no editor on it to a working, signed-in admin at `admin.tech4time.bd`.
Follow it in order — several steps exist to close a window that opens if you do them in a different
sequence.

> **This page used to describe the public site.** Before the split it was one document for one host:
> it zipped `index.html`, `pages/` and `contact-handler.php` into `~/public_html/` and checked
> `https://tech4time.bd/`. None of that is this repository, and `~/public_html/` is now the **public
> site's** document root — extracting the editor there would bury the live website. The public
> site's own first deploy is `tech4time-website-frontend/docs/20-deployment/first-deploy.md`.

Allow two hours, most of it waiting for DNS and SSL.

---

## Before you start

- [ ] cPanel access — username, password, and the login URL
- [ ] SSH access, or cPanel's Terminal
- [ ] `admin.tech4time.bd` created as a subdomain, pointing at `admin.tech4time.bd/public/`
- [ ] An authenticator app on your phone
- [ ] Somewhere safe to write down ten recovery codes
- [ ] Every check passing locally: see [testing.md](../10-development/testing.md)

---

## 1. Prepare the host

Full detail in [cpanel-host-setup.md](cpanel-host-setup.md). The short version:

- [ ] PHP **8.1 or newer** selected in MultiPHP Manager (8.3 preferred)
- [ ] **The document root is `admin.tech4time.bd/public`, not `admin.tech4time.bd`** — one level too
      high and `lib/`, `sections/` and `content/` become web-reachable
- [ ] AutoSSL issued for `admin.tech4time.bd`
- [ ] `admin@tech4time.bd` exists as a mailbox **you can open** — a password reset code goes there
      and nowhere else

## 2. Upload the editor

The upload set is built, not typed:

```bash
python3 tools/build_deploy_set.py --check     # what would go, and what must not
python3 tools/build_deploy_set.py --out _deploy
```

```
~/admin.tech4time.bd/
├── public/          ← DOCUMENT ROOT. Its .htaccess, the six entry points, the assets
├── lib/             ← BESIDE it, never inside
├── sections/        ← likewise
└── content/         ← likewise. Uploaded this once to seed; never again
```

`tools/`, `docs/`, `.git/`, every Markdown file, every `*.key`, `admins.json` and `setup-token.txt`
stay here. The allow list in `tools/build_deploy_set.py` is what decides, so nothing new ships by
accident.

**`content/` is uploaded this once**, to seed the two JSON files. Never again — from now on the
host's copy is the real one and it is the system of record for the whole project.

> `public/.htaccess` is a dotfile. Some FTP clients hide it and some zip tools drop it. Confirm it
> arrived: it carries the real security headers, and `X-Frame-Options` and `X-Content-Type-Options`
> are ignored by browsers when set via `<meta>`.

## 3. Check it serves

```bash
python3 tools/verify_live.py https://admin.tech4time.bd
```

- [ ] `https://admin.tech4time.bd/` shows the sign-in
- [ ] `https://admin.tech4time.bd/lib/auth.php` is **404**
- [ ] `https://admin.tech4time.bd/sections/careers.php` is **404**
- [ ] `https://admin.tech4time.bd/content/careers.json` is **404**
- [ ] `http://` redirects to `https://`

**Those are 404, and a 403 is a failure, not a pass.** A 403 means the directory is inside the
document root and merely blocked by a rule — the document root is one level too high. Go back to
step 1. [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md)

## 4. Probe the host

Two things can only be answered on the server, and both fail quietly.

1. Upload `tools/host-probe.php` to `admin.tech4time.bd/public/` **by hand** — it is not in the
   deploy set, deliberately
2. Open it, set `PROBE_TOKEN` as its header instructs
3. Load it once and read the report
4. **Delete it.** `tools/verify_live.py` asserts it is gone on every deploy, because it was once
   left behind and was reachable

It reports the PHP version, whether argon2id is available and how long a hash takes, where the
private store resolves to and whether it is outside the web root, and whether `mail()` works.

- [ ] argon2id available (bcrypt is an acceptable fallback)
- [ ] Private store resolves to `/home/USER/t4t-private-admin` — **two** levels up from the document
      root, beside the repository, not inside it
- [ ] Private store: **"Inside the web root — no, good"**
- [ ] `mail()` available, and the test message arrives
- [ ] **`host-probe.php` deleted**

## 5. Turn the admin on

This has its own page because the **order** is the safety property:
[admin-activation.md](admin-activation.md).

In brief: read the setup key off the server → create the account → pair the authenticator → save the
recovery codes → prove a full password reset works → only then remove Directory Privacy.

- [ ] The account exists and you can sign in
- [ ] The ten recovery codes are written down somewhere safe
- [ ] A full password recovery has been run end to end — including a real code read in
      `admin@tech4time.bd`

## 6. Prove the publish reaches the public site

The editor is not finished until a save travels. Both halves need the **same** `publish.key` —
`tools/make_publish_key.py`.

- [ ] Save a job post; the public careers page shows the change
- [ ] Save a contact detail; the public contact page shows it
- [ ] With the key removed, a save reports `not-configured`; with a different key, `unknown-key`

Both of those failures are intended, and both say exactly what is wrong.
[publish-api.md](../10-development/server-side/publish-api.md)

## 7. Confirm it stays out of search results

The editor is covered by a blanket `X-Robots-Tag: noindex, nofollow, noarchive` in
`public/.htaccess:43` rather than by a `robots.txt` entry — deliberately, because listing it in a
`robots.txt` advertises it.

- [ ] `curl -sI https://admin.tech4time.bd/ | grep -i x-robots-tag` returns the header

## 8. Write down what you did

Update [40-reference/host-facts.md](../40-reference/host-facts.md) with anything you discovered —
the PHP version, whether argon2id was there, the hash time, mailboxes created, DNS as it stands.
That file is the record of the live host, and it is only useful if it is current. Put the date at
the top.

---

## The complete checklist

```
HOST
[ ] PHP 8.1+                    [ ] SSL issued for admin.tech4time.bd
[ ] docroot = admin.tech4time.bd/public     [ ] admin@ mailbox you can open

UPLOAD
[ ] build_deploy_set.py --check passes      [ ] public/.htaccess arrived
[ ] content/ seeded (this once only)        [ ] NOT tools/   NOT docs/   NO *.key

VERIFY
[ ] Sign-in loads               [ ] http → https
[ ] lib/ 404                    [ ] sections/ 404       [ ] content/ 404
    (a 403 is a FAILURE — the document root is one level too high)

PROBE
[ ] argon2id                    [ ] store at /home/USER/t4t-private-admin
[ ] store outside web root      [ ] mail() works
[ ] host-probe.php DELETED

ADMIN
[ ] Account created             [ ] Authenticator paired
[ ] Recovery codes saved        [ ] Full reset proven, code read in admin@
[ ] Directory Privacy off public/ (last)

PUBLISH
[ ] Same publish.key both halves            [ ] A save reaches tech4time.bd

FINISH
[ ] X-Robots-Tag confirmed      [ ] host-facts.md updated
```

---

## If it goes wrong

[troubleshooting.md](../30-operations/troubleshooting.md) is indexed by what you actually see.

The one genuinely dangerous state is **being unable to sign in to the admin**, and there is a floor
under it: [secrets-recovery.md](../30-operations/secrets-recovery.md).
