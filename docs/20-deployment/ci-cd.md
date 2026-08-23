# Continuous integration and deployment

**Applies to:** both

Every change reaching the server the same way, through checks that cannot be skipped.

---

## What this replaces

The first deploy was a zip built by hand and uploaded through cPanel's File Manager. That worked,
and it does not scale past one person doing it carefully: the set of files that may go to the
server was a sentence in [routine-deploys.md](routine-deploys.md) — an `rsync` line with eight
`--exclude` flags — and seven of those flags save bandwidth while the eighth,
`--exclude='content/'`, is the only thing between a deploy and every job post the client has
written.

Nothing about the two kinds of flag looks different. That is the problem being fixed.

---

## The pieces

| | |
|---|---|
| `tools/build_deploy_set.py` | builds the upload set, and asserts what is in it |
| `.github/workflows/test.yml` | every check this repository has, on every push |
| `.github/workflows/deploy.yml` | **not built yet** — see [the transport decision](#the-transport-decision) |

---

## The upload set

`build_deploy_set.py` produces two directories:

```bash
python3 tools/build_deploy_set.py --out _deploy
#   _deploy/site/   → the document root
#   _deploy/seed/   → content/, and only where content/ is empty

python3 tools/build_deploy_set.py --check    # assert it, build nothing
```

**It is an allow list, not an ignore list.** `UPLOAD` names every top-level entry that goes, and
anything not named stays behind. The two fail in opposite directions and only one of them fails
safely:

| | a new file is added and nobody thinks about deployment | |
|---|---|---|
| ignore list | it ships | a stranger finds it |
| **allow list** | it does not ship | a visitor finds a 404 |

`DENY` then removes things carried along by a directory that is otherwise wanted — `admin/.htaccess`
above all, because cPanel writes its own there and ours would fight it.

`REQUIRED` is the other direction: files whose *absence* is a broken site rather than a missing
feature. `.htaccess` heads that list because it is a dotfile, and both FTP clients and zip tools
have been seen to drop it silently — taking the rules that block `lib/` and `content/` with it, and
leaving a site that looks completely normal.

### Content is not in the set

`content/` holds the client's data: job posts and contact details typed into `/admin/` on the live
server. The repository's copy is test data. It is **never** synced.

But a brand-new host has nothing there and the two dynamic pages need something to render, so the
seed directory is copied with `rsync --ignore-existing`: it creates what is absent and overwrites
nothing. A file already on the host has been edited by somebody and wins, permanently, without
anyone having to decide so on the day.

`deploy/seed/careers.json` carries `jobs: []` — a new host must not launch advertising the test
vacancies — while keeping the real `cv_form_url`. `contact.json` seeds from `content/contact.json`,
which is genuine content and the same file the page footers were built from; seeding it from
anywhere else would make the two disagree.

---

## The test workflow

Runs on every push to `dev` and `main`, and on every pull request to `main`. Three jobs, in
parallel:

| Job | What runs | Needs |
|---|---|---|
| `checks` | the seven static checks, plus `build_deploy_set.py --check` | python, php |
| `php` | the five suites that drive a real PHP server and a real sign-in | php |
| `firefox` | the eight browser suites | firefox, geckodriver, Pillow |

It is deliberately the **same list** as the pre-commit set in
[testing.md](../10-development/testing.md). What gates a merge and what gates a release are one set
of checks, so that "it passed on my machine" and "it is safe to put on the server" stop being two
different claims.

### The silent-pass trap, and the guard against it

Every browser suite calls `shutil.which("firefox")` and, finding nothing, prints a notice and
**exits 0**. That is right on a laptop without geckodriver installed. It is wrong in CI, where a
failed install would turn eight suites into eight green ticks that proved nothing.

So the workflow requires `php`, `firefox` and `geckodriver` to be on `PATH` in a step of its own,
before any suite runs. Note the exact name: `firefox-esr` from apt installs a binary called
`firefox-esr`, which is not what the suites look for — which is why Firefox is installed from
Mozilla's tarball and symlinked, rather than from apt.

---

## The transport decision

`deploy.yml` is not written, because how it reaches the host depends on what the hosting plan
allows. Check cPanel and see which of these exists:

| In cPanel | Transport | Notes |
|---|---|---|
| **SSH Access** / **Terminal** | `rsync` over SSH with a deploy key | The good path. Incremental, understands `--delete`, and a `--dry-run` can be read before the real run. |
| **Git™ Version Control** | cPanel pulls, `.cpanel.yml` deploys | No key leaves GitHub, but it cannot easily delete files removed from the repository. |
| neither | FTPS mirror | Works everywhere. Slower, no dry run worth reading, and the credentials are a password rather than a key. |

Whichever it is, the shape stays the same and the safety property does not move:

1. `test.yml` must have passed.
2. Build the set with `build_deploy_set.py` — *not* with a rule typed into the workflow.
3. Dry run, and **fail the job** if the output proposes deleting anything under `content/` or
   `admin/.htaccess`.
4. Sync `site/`.
5. Sync `seed/` with `--ignore-existing`.
6. Fetch `/` and `/pages/careers/` and check they return 200, and that `/lib/` and `/content/`
   still return 403.

Step 6 matters more than it looks: an `.htaccess` that failed to arrive takes the blocking rules
with it, and nothing about the site's appearance will tell you.

---

## Cost

GitHub Actions is free for public repositories and metered for private ones. The `checks` and `php`
jobs take about a minute between them. The `firefox` job is the expensive one — `check_focus.py`
alone makes 1846 assertions across 22 page loads. If minutes become tight, move the `firefox` job to
pull requests and pushes to `main` only, and leave `dev` covered by the other two.
