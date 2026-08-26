# Tech4TIME — backend

The editor at **`admin.tech4time.bd`**: a sign-in of its own, four sections, and the content of
record for the two pages of the public site that change. No build step, no framework — the files
here are the files that run on the server.

**The public site is not in this repository.** It is **`tech4time-frontend`**, serving
`tech4time.bd`. This half owns the content and *pushes* a signed copy to it on every save; that half
renders from the replica it is sent and never calls this one during a request.
[publish-api.md](docs/10-development/server-side/publish-api.md)

**Full documentation is in [docs/](docs/).** Start at [docs/README.md](docs/README.md), which routes
by intent. New to the project: [docs/00-orientation/README.md](docs/00-orientation/README.md), then
[docs/10-development/setup.md](docs/10-development/setup.md).

---

## Rules that must not be broken

Each has a reason, recorded in [docs/90-decisions/](docs/90-decisions/). If one seems wrong, read
its record before acting.

1. **No build step, no framework, no bundler, no package manager.** The files here are the files
   that run on the server.
2. **No CDN and no external origin.** Everything is self-hosted.
3. **No inline styles or scripts.** The CSP is `style-src 'self'; script-src 'self'` — a `style=`
   attribute, a `<style>` block or an `onclick` will be refused by the browser.
4. **The document root is `public/`.** `lib/`, `sections/` and `content/` sit outside it and must
   stay there. Nothing in `public/.htaccess` keeps them secret — the layout does.
5. **Never commit anything from the private store** (`t4t-private-admin/`, `*.key`, `admins.json`).
6. **`content/` is the system of record.** Never overwrite it on a live server; the deploy seeds it
   with `--ignore-existing` and never syncs it.
7. **`lib/html.php`, `lib/contract.php`, `lib/publish.php` and the icon sprite are byte-identical**
   with `tech4time-frontend`. Change one and you change both, in the same breath, and bump
   `CONTRACT_VERSION` if the *shape* of a document changed.
8. **Every save publishes.** The publish lives inside `careers_save()` and `contact_save()`, not at
   the call sites — the careers editor alone has six of those.
9. **`tools/` is never deployed.**
10. **Do not leave cPanel's Directory Privacy on `public/`.** It writes its own `.htaccess` there
    and every deploy ships ours over it, removing the password silently.

---

## Where things are

| | |
|---|---|
| `public/` | **the document root** — six entry points, `.htaccess`, and the assets a browser fetches |
| `sections/` | the four editors, included by `public/index.php` |
| `lib/` | the sign-in, the contract, the publish client, the store |
| `content/` | the JSON the editors write — **the system of record** |
| `tools/` | build, audit and test scripts — never deployed |
| `docs/` | the documentation |
| `../t4t-private-admin/` | **outside the repo** — hashes, keys, sessions, `publish.key`. Never committed |

The private store is **two** levels up from `public/`, beside the repository rather than inside it,
because the deploy target is the repository and `rsync --delete` empties what is inside it.

---

## Where to change what

Full table: [docs/10-development/where-to-change-things.md](docs/10-development/where-to-change-things.md)

| Change | Where |
|---|---|
| A job post or contact detail | **the editor** — not a file |
| **The shape of editable content** | `lib/contract.php` — **and the same file in the frontend** |
| Validation, the flag picker, the save | `lib/careers.php`, `lib/contact.php` |
| The sign-in, sessions, hashing | `lib/auth.php` — read [authentication.md](docs/10-development/server-side/authentication.md) first |
| Add an editor | [adding-an-editor.md](docs/10-development/server-side/adding-an-editor.md) |
| The editor's appearance | `public/assets/css/admin.css` |
| A colour | `public/assets/css/theme.css` — tokens only, never a hex elsewhere |
| Where the public site is | `PUBLIC_SITE` in `lib/publish_client.php`, or `$T4T_PUBLIC_URL` |
| Headers, HSTS, the blanket noindex | `public/.htaccess` — not read by the local dev server |

---

## Running it

```bash
python3 tools/serve.py          # http://localhost:8001
```

It serves **`public/`**, not the repository — the same shape the host serves, so a path that escapes
it 404s here too. The sign-in is real locally: `/setup.php` once, then `/login.php`.

To watch content actually travel, run the frontend beside it:

```bash
# terminal 1 — tech4time-frontend
python3 tools/serve.py                                          # :8000
# terminal 2 — here
T4T_PUBLIC_URL=http://localhost:8000 python3 tools/serve.py 8001
```

Both private stores need the **same** `publish.key` — `tools/make_publish_key.py`. Without it every
save reports `not-configured`; with two different keys, `unknown-key`. Both are the intended
failures, and both say exactly what is wrong.

[docs/10-development/running-locally.md](docs/10-development/running-locally.md)

---

## Before committing

```bash
python3 tools/check_contrast.py        python3 tools/check_content_model.py
python3 tools/check_secrets.py         python3 tools/check_docs.py
python3 tools/build_deploy_set.py --check
python3 tools/check_shared_lib.py
```

Touched the admin, auth or an editor? Also `test_admin_auth.py`, `test_careers_admin.py`,
`test_contact_admin.py`, `test_publish_client.py`.

Touched `lib/store.php`? Also `test_store.py`. Touched the rich-text editor? Also `test_editor.py`
— needs Firefox and geckodriver, and leaves processes behind if interrupted
(`pkill firefox geckodriver`).

Touched `lib/contract.php`, `lib/publish.php`, `lib/html.php` or the sprite? Also
**`check_shared_lib.py --update`, and copy the changed file and the manifest to the frontend.**

[docs/10-development/testing.md](docs/10-development/testing.md)

---

## Keep the documentation true

**Change the code, update the doc that owns it, in the same commit.** The ownership table is in
[docs/README.md](docs/README.md#which-doc-owns-what).

`python3 tools/check_docs.py` catches the mechanical half — an undocumented tool or library, a dead
link, a cited path that no longer exists, or a constant the prose quotes that has changed. It cannot
read prose; that part is on you.

**A path in backticks always means *this* repository.** A file in the other half is written with the
repository in front: `tech4time-frontend/pages/careers/index.php`. `check_docs.py` enforces it.

---

## Status

Work happens on `dev`; pull requests to `main` need explicit approval.

**A push to `main` deploys it** to `/home/USER/admin.tech4time.bd/`, with `admin.tech4time.bd` pointed at
`admin.tech4time.bd/public/`. Checks run, rsync over SSH, and the host is asked afterwards whether `lib/`,
`sections/` and `content/` still answer **404** — not 403, which would mean the document root is one
level too high — [ci-cd.md](docs/20-deployment/ci-cd.md). Never sync `content/`.

**Not done, and not missing:** the four accessibility crawlers never covered the admin, before the
split or after it; adapting them to its signed-in screens is outstanding and named in
[testing.md](docs/10-development/testing.md).
