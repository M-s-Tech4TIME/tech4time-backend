#!/usr/bin/env python3
"""
Prove that nothing which protects the admin has quietly stopped protecting it.

Build/audit tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/check_secrets.py

WHY THIS EXISTS
The failures this looks for share one property: everything goes on working.
A master key committed by accident, a private store that turns out to be
web-reachable, a bypass flag left in for one afternoon's convenience, a
password written into the audit log — none of them break a page, fail a save
or raise an error. The site is exactly as usable the day after as the day
before, and the only difference is that somebody else can sign in.

So these are asserted mechanically, on every run, rather than remembered.

WHAT IS CHECKED BY BEHAVIOUR RATHER THAN BY READING
Where a check can run the real code, it does — pointing lib/private.php at a
directory inside the web root and insisting it refuses is worth more than
grepping for the function that refuses, because the grep goes on passing when
the call to it is deleted.
"""

import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Names that must never be committed, wherever they turn up.
SECRET_NAMES = [
    "secret.key", "admins.json", "audit.log", "audit.log.1",
    "throttle.json", "resets.json", "setup-token.txt",
]
SECRET_DIRS = ["t4t-private", ".dev-private"]

# Every file that can emit an HTML page under /admin/.
ADMIN_PAGES = [
    "admin/login.php", "admin/forgot.php", "admin/reset.php", "admin/setup.php",
]

problems: list[str] = []
notes: list[str] = []


def ok(label: str) -> None:
    print(f"  ok    {label}")


def bad(label: str, detail: str = "") -> None:
    print(f"  FAIL  {label}" + (f"\n          {detail}" if detail else ""))
    problems.append(label)


def log_contexts(text: str):
    """Every auth_log() call's context argument, with its line number.

    Brace counting rather than a regex, because the context is an array literal
    that contains its own brackets and commas, and a lazy match would stop at
    the first one.
    """
    for m in re.finditer(r"auth_log\(", text):
        i, depth = m.end(), 1

        while i < len(text) and depth:
            if text[i] == "(":
                depth += 1
            elif text[i] == ")":
                depth -= 1
            i += 1

        args = text[m.end():i - 1]
        # Drop the event name — the first quoted literal — and keep the rest.
        context = re.sub(r"^\s*'[^']*'\s*,?", "", args, count=1)

        yield text.count("\n", 0, m.start()) + 1, context


def tracked() -> list[str]:
    out = subprocess.run(["git", "ls-files"], cwd=ROOT,
                         capture_output=True, text=True)
    return out.stdout.splitlines() if out.returncode == 0 else []


# ------------------------------------------------------- nothing is committed


def check_nothing_committed() -> None:
    print("\nnothing secret is in git")

    files = tracked()
    if not files:
        notes.append("not a git checkout — the commit checks were skipped")
        print("  --    skipped: git said nothing")
        return

    found = [f for f in files
             if Path(f).name in SECRET_NAMES
             or any(part in SECRET_DIRS for part in Path(f).parts)
             or f.endswith(".key")]

    if found:
        bad("no secret file is tracked", ", ".join(found))
    else:
        ok("no secret file is tracked")

    # .gitignore has to catch them where they would actually land.
    samples = [f"{d}/{n}" for d in SECRET_DIRS for n in SECRET_NAMES[:2]]
    missed = []

    for sample in samples:
        done = subprocess.run(["git", "check-ignore", "-q", sample], cwd=ROOT)
        if done.returncode != 0:
            missed.append(sample)

    if missed:
        bad("a stray private store would be ignored by git", ", ".join(missed))
    else:
        ok("a stray private store would be ignored by git")


# ------------------------------------------------- the store stays out of reach


def check_store_refuses_web_root() -> None:
    print("\nthe private store refuses to be reachable")

    inside = ROOT / "content" / "would-be-web-readable"

    done = subprocess.run(
        ["php", "-r",
         "require 'lib/private.php';"
         "try { t4t_private_dir(); echo 'ACCEPTED'; }"
         "catch (RuntimeException $e) { echo 'REFUSED'; }"],
        cwd=str(ROOT), capture_output=True, text=True,
        env=dict(os.environ, T4T_PRIVATE=str(inside)),
    )

    if done.stdout.strip() == "REFUSED":
        ok("a store inside the document root is refused")
    else:
        bad("a store inside the document root is refused",
            f"php said {done.stdout.strip()!r} {done.stderr.strip()[:200]}")

    if inside.exists():
        bad("and refusing it creates nothing",
            f"{inside.relative_to(ROOT)} was created anyway")
        try:
            inside.rmdir()
        except OSError:
            pass
    else:
        ok("and refusing it creates nothing")

    # The admin must consult that refusal rather than merely have it available.
    admin = (ROOT / "lib" / "admin.php").read_text()
    start = re.search(r"function admin_start_session\(\).*?\n}", admin, re.S)

    if start and "auth_problem()" in start.group(0) and "admin_refuse(" in start.group(0):
        ok("the admin stops when the store is not sound")
    else:
        bad("the admin stops when the store is not sound",
            "admin_start_session() no longer calls auth_problem() then admin_refuse()")


def check_setup_window_closes() -> None:
    """A setup token and an account must never exist at the same time.

    admin/setup.php promises the window "is shut by the code rather than by a
    step somebody has to remember". It was not: the recovery-codes screen
    survives the "setup is over" redirect on purpose, and it re-created the
    token auth_setup_done() had just deleted. Found on the live host, where
    setup-token.txt sat in the private store beside a working account.

    Driven through the real functions in a throwaway store, because the
    interesting question is what the code does, not what it says. A grep for
    the guard passes the moment somebody keeps the guard and adds a second way
    in beside it.
    """
    print("\nthe setup window shuts behind itself")

    work = Path(tempfile.mkdtemp(prefix="t4t-setupwin-"))
    private = work / "private"
    env = dict(os.environ, T4T_PRIVATE=str(private))

    def php(code: str) -> str:
        done = subprocess.run(
            ["php", "-r", "require 'lib/auth.php';" + code],
            cwd=str(ROOT), capture_output=True, text=True, env=env, timeout=60,
        )
        return (done.stdout + done.stderr).strip()

    try:
        # Before any account: minting one is the whole point, and must work.
        php("auth_setup_token();")
        token_file = private / "setup-token.txt"

        if token_file.exists():
            ok("a fresh store still hands out a setup key")
        else:
            bad("a fresh store still hands out a setup key",
                "auth_setup_token() wrote nothing — setup would be impossible")

        minted = token_file.read_text().strip() if token_file.exists() else ""

        php("var_export(auth_put(auth_defaults(["
            "'user' => 'someone', 'hash' => 'x', 'totp' => 'y'])));")

        if not (private / "admins.json").exists():
            bad("the setup window shuts once an account exists",
                "could not create a test account, so nothing below was proved")
            return

        # The token is deleted the way setup.php deletes it, then everything
        # that could bring it back is asked to.
        php("auth_setup_done();")

        if token_file.exists():
            bad("auth_setup_done() removes the key file",
                "it is still there")
        else:
            ok("auth_setup_done() removes the key file")

        php("auth_setup_token();")

        if token_file.exists():
            bad("and nothing re-creates it once an account exists",
                "auth_setup_token() minted a new one — this is the live defect")
        else:
            ok("and nothing re-creates it once an account exists")

        # The empty-token trap: with no file to read, a comparison against an
        # empty submission must not agree with itself.
        for given, label in ((minted, "the real key"), ("", "an empty key")):
            said = php(f"var_export(auth_setup_token_check({given!r}));")
            if said == "false":
                ok(f"and {label} no longer opens setup")
            else:
                bad(f"and {label} no longer opens setup", f"php said {said!r}")
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ------------------------------------------------------------- no way around it


def check_no_bypass() -> None:
    print("\nthere is no way past the sign-in")

    shipped = [p for p in ROOT.rglob("*.php")
               if "tools" not in p.parts and p.is_file()]

    # The old escape hatch: a constant whose false value granted full access.
    hits = [str(p.relative_to(ROOT)) for p in shipped
            if "ADMIN_REQUIRE_HTTP_AUTH" in p.read_text()]
    if hits:
        bad("the old Basic-auth bypass constant is gone", ", ".join(hits))
    else:
        ok("the old Basic-auth bypass constant is gone")

    # Identity must not come from a request header ever again.
    hits = []
    for p in shipped:
        text = p.read_text()
        for m in re.finditer(r"\$_SERVER\[['\"](REMOTE_USER|REDIRECT_REMOTE_USER|PHP_AUTH_USER)", text):
            hits.append(f"{p.relative_to(ROOT)}: {m.group(1)}")
    if hits:
        bad("nothing takes its identity from the web server", "; ".join(hits))
    else:
        ok("nothing takes its identity from the web server")

    # Every page that can be reached signed out must start the session through
    # admin_start_session(), which is what runs the refusal check.
    for name in ADMIN_PAGES:
        text = (ROOT / name).read_text()
        if "admin_start_session()" in text:
            ok(f"{name} goes through the shell's checks")
        else:
            bad(f"{name} goes through the shell's checks",
                "it does not call admin_start_session()")

    # And every page behind it must require an account.
    index = (ROOT / "admin" / "index.php").read_text()
    if "admin_require_auth()" in index:
        ok("admin/index.php requires an account")
    else:
        bad("admin/index.php requires an account")


# --------------------------------------------------------------- what is stored


def check_nothing_leaks() -> None:
    print("\nsecrets stay out of the places we write to")

    auth = (ROOT / "lib" / "auth.php").read_text()

    # Session cookie flags. A cookie carrying a signed-in session without
    # HttpOnly is readable by any script that gets onto the page.
    boot = re.search(r"function auth_boot\(\).*?\n}", auth, re.S)
    boot = boot.group(0) if boot else ""

    for flag, label in [("'httponly' => true", "HttpOnly"),
                        ("'samesite' => 'Lax'", "SameSite"),
                        ("auth_is_https()", "Secure when the connection is")]:
        if flag in boot:
            ok(f"the session cookie is set {label}")
        else:
            bad(f"the session cookie is set {label}", f"{flag} missing from auth_boot()")

    if "session_regenerate_id(true)" in auth:
        ok("the session id is replaced when signing in")
    else:
        bad("the session id is replaced when signing in")

    # Nothing that would help an attacker may reach the audit log.
    #
    # Only the CONTEXT is examined, never the event name: 'password-reset' and
    # 'totp-enrolled' are things that happened, and a check that cannot tell
    # those from a logged password is a check that gets switched off.
    hits = []
    for p in ROOT.rglob("*.php"):
        if "tools" in p.parts or not p.is_file():
            continue
        for line, context in log_contexts(p.read_text()):
            if re.search(r"password|passwd|secret|\btotp\b|\$code\b|'code'", context, re.I):
                hits.append(f"{p.relative_to(ROOT)}:{line} {context.strip()[:60]}")

    if hits:
        bad("nothing secret is written to the audit log", "; ".join(hits))
    else:
        ok("nothing secret is written to the audit log")

    # A password must never be stored, only its hash.
    if "password_hash(" in auth and "'hash'" in auth:
        ok("accounts store a hash, not a password")
    else:
        bad("accounts store a hash, not a password")

    if "hash_equals(" in auth:
        ok("comparisons that matter are constant time")
    else:
        bad("comparisons that matter are constant time")


# ------------------------------------------------------------- staying unindexed


def check_unindexed() -> None:
    print("\nthe admin stays out of search results")

    for name in ADMIN_PAGES:
        text = (ROOT / name).read_text()
        if "admin_shell_head(" in text:
            ok(f"{name} renders through the noindexed shell")
        else:
            bad(f"{name} renders through the noindexed shell")

    shell = (ROOT / "lib" / "admin.php").read_text()
    count = len(re.findall(r'name="robots"', shell))

    # admin_head(), admin_refuse() and admin_shell_head() each emit their own.
    if count >= 3:
        ok(f"lib/admin.php marks all {count} of its page shapes noindex")
    else:
        bad("lib/admin.php marks every page shape noindex",
            f"found {count} robots tags, expected at least 3")

    htaccess = (ROOT / ".htaccess").read_text()
    if "X-Robots-Tag" in htaccess and "/admin" in htaccess:
        ok(".htaccess marks /admin noindex as a header too")
    else:
        bad(".htaccess marks /admin noindex as a header too")

    if re.search(r'Cache-Control "no-store[^"]*".*admin', htaccess):
        ok(".htaccess keeps /admin out of shared caches")
    else:
        bad(".htaccess keeps /admin out of shared caches")

    if "t4t-private" in htaccess:
        ok(".htaccess blocks a stray private store")
    else:
        bad(".htaccess blocks a stray private store")


def main() -> None:
    check_nothing_committed()
    check_store_refuses_web_root()
    check_no_bypass()
    check_setup_window_closes()
    check_nothing_leaks()
    check_unindexed()

    for note in notes:
        print(f"\nnote: {note}")

    if problems:
        print(f"\n{len(problems)} problem(s):\n")
        for p in problems:
            print(f"  - {p}")
        print("\nEach of these fails silently in production: the site goes on\n"
              "working and the only difference is who can sign in.")
        sys.exit(1)

    print("\nThe admin's protections are all still in place.")


if __name__ == "__main__":
    main()
