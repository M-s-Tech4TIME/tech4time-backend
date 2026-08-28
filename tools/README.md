# tools/

Build, audit and test scripts. **None of it is deployed** — the admin serves from `public/`, so
this directory is outside the document root and unreachable either way. The real rule is that it
never gets uploaded.

One file is an exception, uploaded by hand and then deleted: `host-probe.php`. `admin-cli.php` is
the other thing that goes up by hand — see
[30-operations/secrets-recovery.md](../docs/30-operations/secrets-recovery.md) — and it comes back
down the moment it has done its job.

---

## The reference has moved

**Every script is documented in [../docs/40-reference/tools.md](../docs/40-reference/tools.md)** —
what it does, when to run it, and what it proves.

This file used to carry that plus the admin's design, the host's mail configuration and the content
guidance. Those are now in `docs/`, so each fact lives in exactly one place:

| Was here | Now |
|---|---|
| What each script does | [40-reference/tools.md](../docs/40-reference/tools.md) |
| The admin, signing in, the private store | [10-development/server-side/authentication.md](../docs/10-development/server-side/authentication.md) |
| Recovering a lost password or secret | [30-operations/secrets-recovery.md](../docs/30-operations/secrets-recovery.md) |
| Job posts and the contact page, day to day | [30-operations/content-runbook.md](../docs/30-operations/content-runbook.md) |
| Host state — mail, DNS, DMARC, quotas | [40-reference/host-facts.md](../docs/40-reference/host-facts.md) |
| Deploying without destroying live posts | [20-deployment/routine-deploys.md](../docs/20-deployment/routine-deploys.md) |
| The checks to run before committing | [10-development/testing.md](../docs/10-development/testing.md) |

---

## The short version

```bash
python3 tools/serve.py                      # run the admin locally
python3 tools/preview.py                    # ...and look at it without signing in

python3 tools/check_contrast.py             # before committing
python3 tools/check_css.py
python3 tools/check_content_model.py
python3 tools/check_secrets.py
python3 tools/check_docs.py
python3 tools/build_deploy_set.py --check
python3 tools/check_shared_lib.py
python3 tools/check_shared_repos.py
```

That is this repository's gate, and it is not the frontend's — `inject_icons.py`,
`check_shared_markup.py` and `audit_pages.py` belong to the half that has pages, and are not here.
`CLAUDE.md` carries the conditional tests on top of the list above.

Adding a script? Give it a docstring saying what it proves and how to run it, keep to the standard
library, and add it to [40-reference/tools.md](../docs/40-reference/tools.md) — `check_docs.py`
fails until you do.

---

## Subdirectories

| | |
|---|---|
| `shots/` | screenshot output, gitignored |

The frontend's `templates/` and `masters/` have no counterpart here: this half has no pages to
assemble and no artwork to build.
