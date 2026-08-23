# tools/

Build, audit and test scripts. **None of it is deployed** — `.htaccess` blocks `/tools/` as a
backstop, but the real rule is that this directory never gets uploaded.

Two files are exceptions, uploaded by hand and then deleted: `host-probe.php` and `admin-cli.php`.

---

## The reference has moved

**Every script is documented in [../docs/40-reference/tools.md](../docs/40-reference/tools.md)** —
what it does, when to run it, and what it proves.

This file used to carry that plus the admin's design, the host's mail configuration and the content
guidance. Those are now in `docs/`, so each fact lives in exactly one place:

| Was here | Now |
|---|---|
| What each script does | [40-reference/tools.md](../docs/40-reference/tools.md) |
| The admin, signing in, the private store | [10-development/backend/authentication.md](../docs/10-development/backend/authentication.md) |
| Recovering a lost password or secret | [30-operations/secrets-recovery.md](../docs/30-operations/secrets-recovery.md) |
| Job posts and the contact page, day to day | [30-operations/content-runbook.md](../docs/30-operations/content-runbook.md) |
| Host state — mail, DNS, DMARC, quotas | [40-reference/host-facts.md](../docs/40-reference/host-facts.md) |
| Deploying without destroying live posts | [20-deployment/routine-deploys.md](../docs/20-deployment/routine-deploys.md) |
| The checks to run before committing | [10-development/testing.md](../docs/10-development/testing.md) |

---

## The short version

```bash
python3 tools/serve.py                 # run the site locally

python3 tools/check_contrast.py        # before committing
python3 tools/inject_icons.py --check
python3 tools/check_shared_markup.py
python3 tools/check_content_model.py
python3 tools/check_secrets.py
python3 tools/check_docs.py
python3 tools/audit_pages.py
```

Adding a script? Give it a docstring saying what it proves and how to run it, keep to the standard
library (Pillow is the one exception, for the asset builders), and add it to
[40-reference/tools.md](../docs/40-reference/tools.md) — `check_docs.py` fails until you do.

---

## Subdirectories

| | |
|---|---|
| `templates/` | the canonical header, footer, head and script markup — see its own README |
| `masters/` | source artwork for the asset builders |
| `shots/` | screenshot output, gitignored |
