# What this project is

**Applies to:** both

The public website of Tech4TIME, a Bangladeshi IT company: sixteen pages, a contact form, a job
board, and a small admin panel for editing the two pages whose content changes.

It is built to a constraint that explains almost every decision you will find odd:

> **It runs on cPanel shared hosting, and it deploys by uploading files.**

No Node, no build step, no bundler, no framework, no package manager, no database, no CDN, no
container. The files in this repository are, byte for byte, the files that run on the server.

If that sounds limiting, it is — deliberately. The company's site had to be maintainable by whoever
holds the cPanel password in three years, on the hosting they already pay for, without a toolchain
to resurrect. Every time that trade-off was made, it is written down in
[90-decisions/](../90-decisions/).

---

## The shape of it

```
tech4time.bd/                 the public website — mostly flat .html files
    /pages/careers/           .php — renders job posts from content/careers.json
    /pages/contact/           .php — renders offices and numbers from content/contact.json
    /contact-handler.php      where the enquiry form posts
    /admin/                   the editor for those two pages, behind its own sign-in
```

Fourteen of the sixteen pages are static HTML. Two are PHP, because what they say changes without a
redeploy — and re-uploading a website to change a phone number is not a workflow anyone sustains.

Those two render **on the server**, from JSON on disk. They do not fetch anything at runtime. That
matters most on the contact page, which is the page a search engine is most often asked for by name,
and content that arrives by JavaScript is indexed unreliably.

---

## What is where, in one screen

| Directory | What it holds |
|---|---|
| `pages/` | the site's pages, one directory each |
| `assets/` | CSS, JS, fonts, icons, images — all self-hosted |
| `lib/` | server-side PHP: rendering, content, and the whole sign-in |
| `content/` | the JSON the two dynamic pages render from |
| `admin/` | the editor UI |
| `tools/` | 33 build, audit and test scripts — **never deployed** |
| `docs/` | this |
| `references/` | notes kept from the original design work |

Fuller version: [repository-map.md](repository-map.md).

---

## The parts worth understanding early

**There is no database.** Content is JSON files, edited through `/admin/` and written atomically.
Accounts are a JSON file too. If you are looking for a schema migration, there isn't one — see
[0002-no-database.md](../90-decisions/0002-no-database.md).

**Secrets are not in this repository, and not on the website.** Password hashes, the key they are
peppered with, authenticator secrets and sessions live in a directory *beside* the document root at
`/home/USER/t4t-private/`. Locally that is `../t4t-private`, beside your clone. Nothing there is
ever committed. See [security-model.md](../40-reference/security-model.md).

**The admin has its own sign-in.** Password, then an authenticator app, with lockout, an audit log,
recovery codes, and password recovery by emailed code. It used to be cPanel Directory Privacy;
it is not any more. See [authentication.md](../10-development/backend/authentication.md).

**Progressive enhancement is a hard rule, not an aspiration.** Every page works with JavaScript
off: the forms post natively, content is visible, navigation works, and nothing is hidden that
cannot be revealed again. Animation may decorate; it may never be the only way to reach something.

**Nothing is fetched from another origin.** Fonts, icons and images are all served from this domain,
and the Content-Security-Policy forbids anything else — including inline `<style>` and `<script>`.
If you add a CDN link, the browser will refuse it.

---

## What is not finished

Honest status, so you do not go looking for things that are not there.

- **Two of sixteen pages are editable.** The other fourteen are hand-edited HTML. Making them
  manageable is planned work, not missing work — see
  [adding-an-editor.md](../10-development/backend/adding-an-editor.md).
- **The repository has not been split yet.** The plan is `tech4time-website-frontend` and
  `tech4time-website-backend` on `tech4time.bd` and `admin.tech4time.bd`, talking over a signed
  publish API. Not built. See [0011-two-repositories.md](../90-decisions/0011-two-repositories.md).
- **There is no CI/CD.** Deploys are manual uploads today. GitHub Actions over rsync/SSH is planned.
- **The site is not live yet.** It has never been deployed to production. The accessibility, Core
  Web Vitals and responsiveness audit is still outstanding and should run before it is.

---

## Where to go next

- Building something? [10-development/setup.md](../10-development/setup.md)
- Want the whole picture first? [architecture.md](architecture.md)
- Want to know why it is like this? [conventions.md](conventions.md)
