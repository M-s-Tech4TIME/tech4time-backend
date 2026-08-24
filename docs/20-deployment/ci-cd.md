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
| `.github/workflows/deploy.yml` | push to `main` → checks → dry run → gate → sync → seed → verify |
| `tools/verify_live.py` | asks the deployed site whether its protections are still there |

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
| `firefox` | the eight browser suites, all of them, then a verdict | firefox, geckodriver, Pillow |

It is deliberately the **same list** as the pre-commit set in
[testing.md](../10-development/testing.md). What gates a merge and what gates a release are one set
of checks, so that "it passed on my machine" and "it is safe to put on the server" stop being two
different claims.

### All eight suites run before the job reports

The `firefox` job runs the browser suites in **one step**, collects the failures and reports at the
end, rather than giving each suite a step of its own.

A step per suite stops at the first failure, and that hid more than it looked like it would:
`test_motion.py` failed on its third check, so `test_editor.py`, `check_hover.py`,
`check_dark_mode.py`, `check_responsive.py` and `check_focus.py` — five suites, most of the
coverage — had **never executed in CI at all**. Fixing them would have meant one three-minute round
trip per suite to discover the next problem.

### The silent-pass trap, and the guard against it

Every browser suite calls `shutil.which("firefox")` and, finding nothing, prints a notice and
**exits 0**. That is right on a laptop without geckodriver installed. It is wrong in CI, where a
failed install would turn eight suites into eight green ticks that proved nothing.

So the workflow requires `php`, `firefox` and `geckodriver` to be on `PATH` in a step of its own,
before any suite runs. Note the exact name: `firefox-esr` from apt installs a binary called
`firefox-esr`, which is not what the suites look for — which is why Firefox is installed from
Mozilla's tarball and symlinked, rather than from apt.

---

## The deploy workflow

`.github/workflows/deploy.yml` runs on push to `main`, and on demand. Merging `dev` into `main` is
the approval step; there is no staging site.

```
test       .github/workflows/test.yml, called as a reusable workflow
build      python3 tools/build_deploy_set.py --out _deploy
ssh        write the deploy key, pin the host key, prove the connection
dry run    rsync --delete --itemize-changes --dry-run  →  /tmp/plan.txt
gate       read /tmp/plan.txt; fail the job if it deletes anything protected
sync       rsync --delete             _deploy/site/  →  ~/public_html/
seed       rsync --ignore-existing    _deploy/seed/  →  ~/public_html/content/
verify     python3 tools/verify_live.py https://tech4time.bd
```

Transport is **rsync over SSH**, using a deploy-only key. The host key is pinned in
`known_hosts` from a secret rather than accepted with `ssh-keyscan` on each run — keyscanning into
`known_hosts` accepts whatever answers, which is a formality rather than a check.

### The protect list, and why the gate is not redundant

`rsync --delete` into a cPanel document root is destructive by default, and what it would destroy
is not in the repository: `content/`, `admin/.htaccess`, `.well-known/`, `cgi-bin/`, `error_log`,
the MultiPHP ini files. The full list and its reasoning is
[ADR 0016](../90-decisions/0016-a-deploy-protects-what-the-panel-owns.md).

The filters prevent those deletions. The gate then reads the dry run and fails the job if any were
proposed anyway. That is deliberately two mechanisms for one rule, because a typo in a filter path
produces a rule that matches nothing, reports no error, and leaves every run green.

**The gate's own pattern was wrong when it was written** — it expected `*deleting ` with one space
where `--itemize-changes` emits three — so it matched nothing and passed everything while looking
entirely healthy. It was caught by running it against a dry run with the filters removed. That is
now how any change to either is verified:

```bash
rsync -a --delete --itemize-changes --dry-run SRC/ DST/ > plan.txt   # no filters
grep -E '^\*deleting[[:space:]]+(content/|\.well-known/|cgi-bin/|error_log)' plan.txt
```

If that prints nothing, the gate is broken. A gate that has never been seen to fail has not been
tested.

### Secrets

Set under Settings → Secrets and variables → Actions:

| Secret | What |
|---|---|
| `SSH_HOST` | the hostname or IP cPanel gives for SSH |
| `SSH_PORT` | cPanel often uses something other than 22 |
| `SSH_USER` | the cPanel account name — `techtime` |
| `SSH_KEY` | the **private** half of a deploy-only key, no passphrase |
| `SSH_HOST_KEY` | one line of `ssh-keyscan -p PORT HOST`, pinned |

Generate the key in cPanel → SSH Access → Manage SSH Keys, and authorize it there. Use a key made
for this and nothing else: it is stored by GitHub, used unattended, and should be revocable without
disturbing anything a person signs in with.

Every secret reaches the shell through `env:` rather than `${{ }}` interpolation. The values here
are trusted; the habit is not about these values.

---

## Cost

GitHub Actions is free for public repositories and metered for private ones. The `checks` and `php`
jobs take about a minute between them. The `firefox` job is the expensive one — `check_focus.py`
alone makes 1846 assertions across 22 page loads. If minutes become tight, move the `firefox` job to
pull requests and pushes to `main` only, and leave `dev` covered by the other two.
