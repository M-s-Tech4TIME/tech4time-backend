# Tools

**Applies to:** both

Every script in `tools/`. **None of them is deployed** — `public/.htaccess` blocks `/tools/` as a backstop,
but the real rule is that the directory never gets uploaded.

Two exceptions are uploaded by hand, run, and deleted: `host-probe.php` and `admin-cli.php`.

Everything is Python 3 standard library, apart from the asset builders, which need **Pillow**. The
browser tests speak to geckodriver over its wire protocol — there is no Selenium.

---

## Running the admin

```bash
python3 tools/serve.py            # http://localhost:8001
python3 tools/serve.py 8080       # a different port
```

It serves **`public/`**, not the repository, because that is what the host serves. A request that
escapes it 404s here exactly as it would there — `tools/dev-router.php` sees to that, and a
development machine on which `/../lib/auth.php` resolves would teach the wrong lesson.

To watch content actually travel, run `tech4time-website-frontend` beside it and point this at it:

```bash
T4T_PUBLIC_URL=http://localhost:8000 python3 tools/serve.py 8001
```

Both private stores need the **same** `publish.key`, or every publish is refused as `unknown-key`.

[running-locally.md](../10-development/running-locally.md)

---

## Checks — run these before committing

| Script | Proves |
|---|---|
| `check_contrast.py` | the palette meets WCAG 2.1 AA in both modes |
| `check_content_model.py` | the model and the editor still describe the same thing, and no editor is unchecked |
| `check_secrets.py` | nothing protecting the admin has quietly stopped protecting it — including that `lib/`, `sections/` and `content/` are still outside the document root |
| `check_docs.py` | the documentation still describes the code |
| `build_deploy_set.py --check` | the set of files bound for the server holds nothing it must not, and nothing is missing |
| `check_shared_lib.py` | the four files both repositories hold identically have not been edited here |

**Half the suite is in the other repository.** The public site's pages, its markup auditors, its
icon injection and the browser crawls over it went with the pages they were written for.
`check_content_model.py` and `check_docs.py` each print which half they ran and name the repository
that does the other, rather than quietly checking less than they used to.

---

## Tests

### Over HTTP, against a real PHP server

| Script | Exercises |
|---|---|
| `test_admin_auth.py` | the whole sign-in cycle: first-run setup; **the setup key demanded of a request from off the machine**; signing in and out; a code works once; the lockout; the emailed reset cycle; recovery codes; the audit log; the refusal to run unsafely. Includes the RFC 6238 test vectors, so the TOTP implementation is checked against the specification rather than against itself |
| `test_publish_client.py` | `publish_push()` and the save that calls it: a payload an independent verifier accepts, and every way it can fail arriving as something the editor can show |
| `test_careers_admin.py` | the job post editor: add, edit, reorder, delete, validation, CSRF, the atomic write — and that **every field the model declares reaches the live site**, by pushing a marker through each one and reading it out of the published document |
| `test_contact_admin.py` | the contact page editor, its row buttons, and the same field round trip |
| `test_store.py` | `lib/store.php`: telling apart missing, unreadable and corrupt; the atomic write; and the rule that a damaged file is never copied over a good `.bak`, because the backup is what damage is recovered from |
| `admin_session.py` | *(not run directly)* gives a test an admin account and signs it in |
| `publish_stub.py` | *(not run directly)* the far side, implemented a second time in Python |

**`publish_stub.py` is the point, not a shortcut.** The real endpoint is `tech4time-website-frontend/api/publish.php`, and testing this half against it would check the two halves against each other
rather than against the format they both implement — a bug they shared would pass. So each side is
checked against an independent implementation written from the description: this stub here, and over
there `tech4time-website-frontend/tools/test_publish.py`, which signs in Python and posts to the real PHP endpoint. **Neither side is
ever checked against its own counterpart.**

Every test runs against a **copy** of the real data files, restored afterwards whether the run
passes or fails, and against a private store in a throwaway directory under `/tmp`.

### In a real browser

| Script | Proves |
|---|---|
| `test_editor.py` | the rich-text editor driven as a person drives it, including a real sign-in: the toolbar, the selection, and that alignment is a class and never an inline style |

**What is not here, and never was.** `tech4time-website-frontend/tools/check_focus.py`,
`tech4time-website-frontend/tools/check_dark_mode.py`, `tech4time-website-frontend/tools/check_responsive.py` and
`tech4time-website-frontend/tools/check_hover.py` crawl a list of public pages and never signed in, so they never covered the
editor even before the split. They went to `tech4time-website-frontend` with the pages they were written
for. Adapting them to the admin's signed-in screens is outstanding work, named here rather than
left to be discovered — see [testing.md](../10-development/testing.md).

---

## Publishing

The two halves and the one route between them — [the publish API](../10-development/server-side/publish-api.md).

| Script | Does |
|---|---|
| `make_publish_key.py` | Create the key both halves sign content with. Run **once**, then copy the printed value into the other half's private store by hand |
| `reconcile.py` | *(uploaded and run on the host — see below)* Send anything the public site is behind on, and say plainly when the public site is **ahead** |
| `check_shared_lib.py` | Assert the four shared files against a committed digest. `--update` re-records after a deliberate change |

`make_publish_key.py` is deliberately not automatic. Every other secret here creates itself on first
use; this one must not, because a key that appears by itself appears **differently** on each host and
the failure reads as "signature rejected" until somebody thinks of it.

`reconcile.py` needs no status endpoint: every answer from the public site's endpoint carries the
revision that host holds — the refusals as well as the acceptance — so an attempt *is* the question,
and an attempt refused as `not-newer` has changed nothing.

**It is uploaded, run and deleted, like `admin-cli.php`.** `tools/` is never deployed, and the host
is the only place it is useful — it reads *that* machine's `content/` and *that* machine's private
store. Run from a development clone it would publish development content to whatever it was pointed
at. So it takes the site root as an argument and lives in the HOME directory, above the deploy
target rather than inside it.

`check_shared_lib.py` covers the icon sprite as well as the three PHP files. `CONTACT_ICONS` is in
the contract, so an icon this editor offers must be one the public page can actually draw; a drifted
sprite renders as an empty box with both halves behaving exactly as written.

---

## Deploying

| Script | Does |
|---|---|
| `build_deploy_set.py` | Builds the upload set from an explicit allow list — `public/`, `lib/`, `sections/` — and asserts what is and is not in it |
| `verify_live.py <url>` | Asks the running admin whether the deploy landed: the pages answer, the headers are there, and `lib/`, `sections/` and `content/` answer **404** |

`verify_live.py` requires 404 and not 403 for those three, and the distinction is the whole point. A
403 would mean they are inside the document root and something is choosing to block them — the
weaker arrangement [0018](../90-decisions/0018-the-backend-serves-from-a-subdirectory.md) exists to
replace. It would be a finding, not a pass.

---

## Host tools — upload, run, delete

### `host-probe.php`

Answers what can only be answered on the host: the PHP version, whether argon2id is available, where
the private store resolves to, and whether `mail()` actually sends. Upload it to the document root,
request it with its token, read the output, **delete it**.

### `admin-cli.php`

The break-glass path when every way into the admin is shut. Upload it to the **home directory**,
above `~/admin.tech4time.bd` — never into the document root.

```bash
php ~/admin-cli.php list          # what accounts exist
php ~/admin-cli.php passwd        # set a new password; ends every session
php ~/admin-cli.php codes         # issue ten new recovery codes
php ~/admin-cli.php totp-clear    # unpair the authenticator
php ~/admin-cli.php unlock        # clear a lockout
php ~/admin-cli.php log 25        # the audit log
php ~/admin-cli.php where         # which files it is working on
```

It asks for no password, because anyone who can run it can already read the accounts file. Delete it
when you are done. [secrets-recovery.md](../30-operations/secrets-recovery.md)

### `reconcile.py`

Uploaded to the **home directory** the same way, and run against the site root:

```bash
python3 ~/reconcile.py ~/admin.tech4time.bd            # every document
python3 ~/reconcile.py ~/admin.tech4time.bd careers    # one of them
```

With no argument it looks for `lib/publish_client.php` beside itself and then at
`~/admin.tech4time.bd`, and refuses with the list of places it tried rather than guessing. Delete it
when you are done — the next deploy would not, because it is outside the target.

> Run `where` first, always. It prints the private store it resolved to, and a rescue tool pointed
> at the wrong directory reports an account file that does not exist while the real one sits
> untouched — which is precisely the answer a rescue tool must not give. It hands
> `lib/private.php` a `DOCUMENT_ROOT` of `<root>/public`, not `<root>`, for that reason.

---

## Adding a tool

Every script in `tools/` must carry, in its docstring: what it is for, that it is **not deployed**,
and how to run it. Then add a row to this page — `tools/check_docs.py` fails if a script here is
undocumented, or if this page names one that no longer exists.

A tool that belongs to the other half is named with the repository in front —
`tech4time-website-frontend/tools/sync_site_contact.py` — and that full path has to appear at least once,
which is what stops "it is in the other one" from keeping a dead name in the prose forever.
