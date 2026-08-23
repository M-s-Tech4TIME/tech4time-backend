# Conventions

**Applies to:** both

The rules this project holds to, and the reason for each. Several of them look arbitrary until you
know what they are protecting — that is what this page is for.

If you are about to break one of these, read its reason first. If the reason no longer applies,
write a decision record in [90-decisions/](../90-decisions/) and change the rule deliberately.

---

## The hard rules

These are not style preferences. Breaking one breaks something.

### No build step, no framework, no bundler, no package manager

The files in this repository are the files that run on the server. There is nothing to compile and
nothing to install.

**Why:** the site is maintained by whoever holds the cPanel password, on shared hosting, possibly
years from now. A toolchain is a dependency that has to be resurrected before a typo can be fixed.
[0001-no-build-step.md](../90-decisions/0001-no-build-step.md)

### No CDN, no external origin, no inline styles or scripts

Fonts, icons, images, CSS and JS are all served from this domain. The Content-Security-Policy is:

```
default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'
```

**Why:** privacy, availability, and the fact that a `style="…"` attribute or a `<script>` block is
exactly what an XSS payload looks like. Forbidding them wholesale means the browser rejects the
attack without having to distinguish it from legitimate markup.

**In practice:** a `<style>` block, a `style=` attribute, an inline `onclick`, or a CDN `<link>` will
be refused by the browser. Put it in a CSS file and a JS file.
[0004-self-hosted-strict-csp.md](../90-decisions/0004-self-hosted-strict-csp.md)

### Progressive enhancement — every page works with JavaScript off

Forms post natively. Content is in the markup. Navigation works. Nothing is hidden unless the code
has already established it can be revealed again.

**Why:** it is the accessibility floor, and it is what makes the site indexable. Animation may
decorate; it may never be the only route to something.
[0012-motion-may-not-gate.md](../90-decisions/0012-motion-may-not-gate.md)

### No database

Content is JSON on disk, written atomically. Accounts are JSON on disk.

**Why:** one fewer thing to provision, back up, migrate and secure on hosting where the database is
a separate product with its own credentials. The data is small and read far more than written.
[0002-no-database.md](../90-decisions/0002-no-database.md)

### Content renders on the server, never by `fetch()`

**Why:** content that arrives by JavaScript is indexed unreliably, and the contact page is the one
most often searched for by name.
[0003-server-rendered-content.md](../90-decisions/0003-server-rendered-content.md)

### Never commit anything from the private store

`secret.key`, `admins.json`, sessions, the audit log. `.gitignore` covers them as a backstop, and
`check_secrets.py` fails the build if one appears.

**Why:** a leaked master key means every stored password can be attacked offline, and a leaked
`admins.json` hands over the authenticator secrets, which cannot be hashed and so cannot be
protected any other way.

### Never add an `.htaccess` to `admin/`

**Why:** cPanel writes its own file there when Directory Privacy is used. Uploading over it silently
removes whatever protection it was applying.

### Never overwrite `content/` on a live server

**Why:** the host's copy is the real data. Job posts and contact details are edited through `/admin/`
by people who are not you, and a deploy that includes `content/` destroys their work.
[routine-deploys.md](../20-deployment/routine-deploys.md)

### `tools/` is never deployed

**Why:** it contains scripts that manipulate the site, and two that can reset an admin password.
`.htaccess` blocks the path as a backstop; the real rule is that it never gets uploaded.

---

## Code conventions

### PHP

- `declare(strict_types=1);` at the top of every file.
- Every file opens with a comment block saying **what it owns and why it exists** — not what the
  functions are named. Follow that pattern; it is the main reason this codebase can be read.
- Escape on output, always: `h()` from `lib/html.php`. Never trust that a value was cleaned earlier.
- Section files under `admin/sections/` must refuse to run unless `T4T_ADMIN` is defined.
- **Fail closed on authority, fail open on convenience.** The sign-in refuses to run when it cannot
  reach its store; the contact form's rate limit carries on if its counter is unreadable. An
  unreachable file must not make the company uncontactable.

### CSS

- Mobile-first. BEM class names (`block__element--modifier`).
- Fluid sizing with `clamp()` and auto-fit grids, so layouts scale between breakpoints rather than
  snapping at them. The documented breakpoint ladder is at the top of `base.css`.
- Colour comes from tokens in `theme.css`. Never write a hex value in a component file.
- No inline styles. The CSP forbids them.

### JavaScript

- Each module registers itself on `window.Tech4Time` and exposes an `init()`.
- `main.js` runs last and calls each `init()` inside a try/catch, so one broken feature cannot take
  the page down.
- `theme-init.js` is the only synchronous script, and only because two decisions have to be made
  before the first frame is painted.
- Everything else is deferred, at the end of `<body>`.

### Python (`tools/`)

- Standard library only, with one exception: **Pillow**, used by the asset builders.
- The browser tests speak to geckodriver over its wire protocol directly. There is no Selenium.
- Every script opens with a docstring saying what it proves and how to run it.
- Checks exit non-zero on failure and print what failed, not just that something did.

---

## Documentation conventions

- Every doc opens with an **Applies to:** line — `frontend`, `backend`, or `both`. That is what makes
  the coming repository split a move rather than a rewrite.
- One fact lives in one place. Link to it rather than repeating it; a fact in three files drifts in
  three files.
- When you change code, update the doc that owns it in the same commit.
  [The ownership table](../README.md#which-doc-owns-what) says which one that is.
- `python3 tools/check_docs.py` catches the mechanical half — dead links, undocumented files, quoted
  values that no longer match the code.

---

## Git

- Work on `dev`. Pull requests to `main` only with explicit approval.
- Commit messages explain **why**, not what — the diff already says what.
- Run the pre-commit checks first. [testing.md](../10-development/testing.md) lists them.
