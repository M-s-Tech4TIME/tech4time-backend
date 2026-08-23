# Tech4TIME — Static Website

The Tech4TIME company website: semantic HTML5, plain CSS3 and vanilla JavaScript, with **no
framework, bundler or build step**. It deploys to cPanel shared hosting by uploading the files as
they are.

Sixteen pages, a contact form, a job board, and an admin panel — with its own sign-in — for the two
pages whose content changes without a redeploy.

Structure, layout and copy are ported from the internal NextJS site. The colour system is new: pure
monochrome with a metallic silver accent derived from the logo's clock face, in full light and dark
modes.

---

## Quick start

```bash
python3 tools/serve.py          # http://localhost:8000
```

Needs the PHP CLI (`sudo apt install php-cli`). **Not** `python3 -m http.server` — four things need
PHP: the careers page, the contact page, the admin, and the contact form's handler.

Full setup, including the browser tests and the admin account:
**[docs/10-development/setup.md](docs/10-development/setup.md)**

---

## Documentation

Everything is in **[docs/](docs/)**, organised by what you are trying to do.
Start at **[docs/README.md](docs/README.md)**, which routes by intent.

| I want to | |
|---|---|
| Understand this project | [docs/00-orientation/](docs/00-orientation/) |
| Set it up and change things | [docs/10-development/](docs/10-development/) |
| Deploy it | [docs/20-deployment/](docs/20-deployment/) |
| Fix something that broke | [docs/30-operations/](docs/30-operations/) |
| Look up a fact | [docs/40-reference/](docs/40-reference/) |
| Know why it is built this way | [docs/90-decisions/](docs/90-decisions/) |

**The two pages worth reading first:**

- [What this project is](docs/00-orientation/README.md) — ten minutes
- [Where to change things](docs/10-development/where-to-change-things.md) — "I want to change X,
  which file do I open?"

---

## The shape of it

```
index.html  404.html      the homepage and the error page
pages/                    the other fourteen — two are .php and render from content/
assets/                   css, js, fonts, icons, images — all self-hosted
lib/                      server-side PHP: rendering, content, and the whole sign-in
content/                  the JSON the two dynamic pages render from
admin/                    the editor, behind its own sign-in
tools/                    33 build, audit and test scripts — never deployed
docs/                     the documentation
.htaccess                 security headers, caching, clean URLs, blocking
```

Not in this repository: **the private store** — password hashes, the master key, authenticator
secrets and sessions — which lives *beside* the document root at `/home/USER/t4t-private/`, and
`../t4t-private` locally. See
[docs/40-reference/security-model.md](docs/40-reference/security-model.md).

Full map: [docs/00-orientation/repository-map.md](docs/00-orientation/repository-map.md)

---

## Before committing

```bash
python3 tools/check_contrast.py        python3 tools/check_content_model.py
python3 tools/inject_icons.py --check  python3 tools/check_secrets.py
python3 tools/check_shared_markup.py   python3 tools/check_docs.py
python3 tools/audit_pages.py
```

What each proves, and which browser suites to run when:
[docs/10-development/testing.md](docs/10-development/testing.md)

---

## Deploying

Upload everything except `tools/`, `docs/`, `references/`, `.git/` and the Markdown files.
**Never upload `content/`** to a server that already has it — the host's copy is the real data.

First time: [docs/20-deployment/first-deploy.md](docs/20-deployment/first-deploy.md)
Turning the admin on: [docs/20-deployment/admin-activation.md](docs/20-deployment/admin-activation.md)

---

## Status

The site has not been deployed yet. Two of sixteen pages are editable; the repository has not been
split into frontend and backend; there is no CI/CD; the accessibility, Core Web Vitals and
responsiveness audit is still outstanding.
