#!/usr/bin/env python3
"""
Exercise lib/store.php — the JSON files that stand in for a database.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_store.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
Everything the site keeps goes through this file: job posts, contact details
and every administrator account. It had no test of its own, which is how the
distinction it now draws came to be missing in the first place.

The one that matters most is the backup rule. store_write() keeps one
generation beside the file, and a store that will not parse reads as empty —
so a damaged file shows an empty editor, and the first save is the one that
would copy the damage over the only intact copy. The moment somebody needs the
backup is the moment the old code destroyed it.

Runs entirely on temporary files. Nothing here touches content/.
"""

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
STORE = ROOT / "lib" / "store.php"


class Results:
    def __init__(self):
        self.passed = 0
        self.failed = []

    def check(self, case, ok, detail=""):
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))

    def section(self, name):
        print(f"\n{name}")


def jobs_in(path: Path):
    """
    The 'jobs' list in a file, or None if it does not parse.

    Read defensively on purpose: every check here is about what happens to a
    file that has been damaged, so the damaged case is the one the test must
    survive reading. A traceback abandons the run; None fails one check and
    lets the rest report.
    """
    try:
        return json.loads(path.read_text())["jobs"]
    except (OSError, ValueError, KeyError, TypeError):
        return None


def php(body: str) -> str:
    """Run a snippet with lib/store.php loaded, and give back what it printed."""
    code = f"<?php require '{STORE}'; {body}"
    done = subprocess.run(
        ["php", "-r", code[6:]], capture_output=True, text=True, timeout=30
    )
    if done.returncode != 0:
        return "PHP-ERROR: " + (done.stderr.strip() or "no output")
    return done.stdout.strip()


def test_state(r: Results, work: Path):
    r.section("telling apart why a store could not be read")

    good = work / "good.json"
    good.write_text(json.dumps({"a": 1}))

    cases = [
        ("a file that was never there", work / "absent.json", None, "missing"),
        ("a file holding valid json", good, None, "ok"),
        ("a file of zero bytes", work / "empty.json", "", "corrupt"),
        ("a truncated file", work / "cut.json", '{"a": 1', "corrupt"),
        ("a file that is not json", work / "html.json", "<html>404</html>", "corrupt"),
        ("json that is not an object", work / "scalar.json", "42", "corrupt"),
    ]

    for label, path, content, want in cases:
        if content is not None:
            path.write_text(content)
        got = php(f"echo store_state('{path}');")
        r.check(f"{label} is '{want}'", got == want, f"got '{got}'")

    r.check("and store_read() still answers null for every unusable one",
            all(php(f"var_export(store_read('{p}'));") == "NULL"
                for _, p, _, want in cases if want != "ok"))


def test_backup_guard(r: Results, work: Path):
    r.section("the backup is never overwritten by a damaged file")

    live = work / "careers.json"
    backup = Path(str(live) + ".bak")
    good = json.dumps({"jobs": ["one", "two", "three"]}, indent=2)

    # A normal save: the previous version becomes the backup, as before.
    live.write_text(good)
    php(f"store_write('{live}', ['jobs' => ['four']]);")
    r.check("a good file still becomes the backup on the next save",
            jobs_in(backup) == ["one", "two", "three"],
            backup.read_text() if backup.is_file() else "no backup")

    # The real case: the live file is damaged, the backup still holds the data.
    live.write_text(good)
    php(f"store_write('{live}', ['jobs' => ['five']]);")     # backup := good
    live.write_text('{"jobs": [ttt')                          # then it corrupts

    r.check("(the backup holds the good copy before the damaging save)",
            jobs_in(backup) == ["one", "two", "three"])

    php(f"store_write('{live}', ['jobs' => ['six']]);")

    r.check("saving over a damaged file leaves the backup intact",
            jobs_in(backup) == ["one", "two", "three"],
            backup.read_text() if backup.is_file() else "no backup")
    r.check("and the save itself still went through", jobs_in(live) == ["six"])

    # With no usable backup there is nothing to protect, so keep the old
    # behaviour rather than refusing to record anything at all.
    lone = work / "lone.json"
    lone_bak = Path(str(lone) + ".bak")
    lone.write_text("{{{ not json")
    php(f"store_write('{lone}', ['x' => 1]);")
    r.check("with no good backup to lose, a damaged file is still copied",
            lone_bak.is_file() and lone_bak.read_text().startswith("{{{"),
            lone_bak.read_text() if lone_bak.is_file() else "no backup")


def test_write_and_edit(r: Results, work: Path):
    r.section("writing and editing")

    f = work / "w.json"
    r.check("store_write reports success", php(f"var_export(store_write('{f}', ['n' => 1]));") == "true")
    r.check("and what it wrote reads back", json.loads(f.read_text())["n"] == 1)
    r.check("no temp file is left behind",
            not list(work.glob("w.json.*.tmp")), str(list(work.glob("*.tmp"))))

    got = php(f"store_edit('{f}', function (array &$d) {{ $d['n'] = $d['n'] + 1; return $d['n']; }}); echo file_get_contents('{f}');")
    r.check("store_edit changes the file under a lock", json.loads(got)["n"] == 2, got)


def main() -> None:
    if not shutil.which("php"):
        raise SystemExit("php not found. This test needs the PHP CLI:\n"
                         "  sudo apt install php-cli")

    work = Path(tempfile.mkdtemp(prefix="t4t-store-"))
    r = Results()

    try:
        test_state(r, work)
        test_backup_guard(r, work)
        test_write_and_edit(r, work)
    finally:
        shutil.rmtree(work, ignore_errors=True)

    total = r.passed + len(r.failed)
    print(f"\n{r.passed}/{total} checks passed")

    if r.failed:
        print("\nfailed:")
        for name in r.failed:
            print(f"  - {name}")
        sys.exit(1)


if __name__ == "__main__":
    main()
