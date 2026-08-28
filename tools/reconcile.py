#!/usr/bin/env python3
"""
Make the live site agree with this one, when a publish went missing.

Operations tool. NOT deployed to the web server (see tools/README.md).
Belongs to the BACKEND.

    python3 tools/reconcile.py                     # from the repository, locally
    python3 ~/reconcile.py ~/admin.tech4time.bd    # uploaded, on the host
    python3 tools/reconcile.py careers             # one document

A full run also re-sends any uploaded picture the live site is missing. Content
and pictures travel separately (ADR 0019), so they go missing separately.

IT MUST RUN ON PYTHON 3.9
That is what the cPanel host has, and this is the only tool here that runs
there rather than on a development machine. Nothing in it may use syntax newer
than 3.9 — no match statements, and annotations only under the __future__
import below. A tool that cannot start is worse than no tool, and the day it
would be discovered is the day content has gone missing.

UPLOADED AND RUN, LIKE admin-cli.php
tools/ is never deployed, so this is not on the host — and the host is the only
place it is useful, because it reads THAT machine's content/ and THAT machine's
private store. Running it from a development clone would publish development
content to whatever it is pointed at.

So it takes the site root as an argument, the same way tools/admin-cli.php
does, and lives in the HOME directory above the deploy target rather than
inside it. Upload it, run it, delete it.

WHY THIS EXISTS
Content reaches the public site by being pushed on save. Most of the time that
works and the editor says so; when it does not, the editor says that too and
offers to send it again. This is for the case where nobody was there to press
it — the push failed, the tab was closed, and the two have quietly disagreed
ever since.

It runs OUT OF BAND. Never during a page render, never on a schedule that
could collide with a save. Somebody runs it, reads what it says, and acts.

HOW IT KNOWS WITHOUT ASKING
There is no status endpoint, on purpose: a second route into the public site is
a second thing to secure and a second thing to keep in step. Instead every
answer from api/publish.php carries the revision that host now holds — the
refusals as well as the acceptance — so an attempt IS the question.

An attempt that is refused as 'not-newer' has changed nothing, which is what
makes it safe to use as a probe.

    never published     this host's document carries no revision, because it
                        was put here by hand rather than saved. The save
                        functions mint one and send it — that IS a first
                        publish, and it is the case this tool exists for on a
                        newly built host.
    accepted            the live site was behind. It is not any more.
    not-newer, equal    the two are in step. Nothing to do.
    not-newer, higher   the LIVE SITE is ahead of this one. Do not force it;
                        something published from somewhere else, or this
                        host's record was restored from a backup. A person
                        has to decide which copy is right.
    anything else       the failure, in the words the editor would use.
"""

# The host runs Python 3.9, which reads annotations at definition time and does
# not know "str | None". This is the one tool here that runs on the host rather
# than on a development machine, so it is the one that has to say so. Without
# it, the failure is a TypeError on import, on the day content is missing.
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path


def locate_root(explicit: str | None) -> Path:
    """The site root: where lib/ is.

    Named the same way admin-cli.php names it, and found the same way — an
    explicit argument first, then the places this file might have been put.
    """
    tried = []

    for candidate in filter(None, [
        explicit,
        str(Path(__file__).resolve().parent.parent),   # tools/, in the repository
        str(Path.home() / "admin.tech4time.bd"),       # cPanel, after the split
        str(Path(__file__).resolve().parent / "admin.tech4time.bd"),
    ]):
        here = Path(candidate).expanduser()
        tried.append(str(here))
        if (here / "lib" / "publish_client.php").is_file():
            return here

    raise SystemExit(
        "Could not find lib/publish_client.php.\n\n"
        "Pass the site root as the first argument:\n"
        "  python3 reconcile.py ~/admin.tech4time.bd\n\n"
        "Looked in:\n  " + "\n  ".join(tried)
    )


ROOT = Path(__file__).resolve().parent.parent   # replaced in main()

PROBE = """
require_once 'lib/publish_client.php';
require_once 'lib/careers.php';
require_once 'lib/contact.php';

$document = $argv[1];
$data = $document === 'careers' ? careers_load() : contact_load();

/* A document that has never been saved carries revision 0, and the receiving
   side refuses anything below 1 — so a host whose content/ was put in place by
   hand could never publish it, which is exactly the case this tool exists for.

   Minting a revision is careers_save()'s and contact_save()'s job and nobody
   else's, so this asks THEM rather than doing it here. They write the record
   and publish it in one step, which is what a first publish is. */
if ((int)($data['revision'] ?? 0) < 1) {
    $ok = $document === 'careers' ? careers_save($data) : contact_save($data);
    $after = $document === 'careers' ? careers_load() : contact_load();

    echo json_encode([
        'mine'    => (int)($after['revision'] ?? 0),
        'first'   => true,
        'result'  => $ok ? (publish_note() ?? ['ok' => false, 'code' => 'no-attempt'])
                         : ['ok' => false, 'code' => 'write-failed',
                            'error' => 'Could not write ' . $document . '.json here.'],
    ]);
    exit;
}

echo json_encode([
    'mine'   => (int)($data['revision'] ?? 0),
    'first'  => false,
    'result' => publish_push($document, $data),
]);
"""


def documents() -> list[str]:
    out = subprocess.run(
        ["php", "-r", "require 'lib/contract.php'; echo json_encode(CONTRACT_DOCUMENTS);"],
        cwd=str(ROOT), capture_output=True, text=True,
    )
    if out.returncode != 0 or not out.stdout.strip():
        raise SystemExit("could not read CONTRACT_DOCUMENTS from lib/contract.php:\n"
                         + (out.stderr or out.stdout)[:400])
    return json.loads(out.stdout)


def endpoint() -> str:
    out = subprocess.run(
        ["php", "-r", "require 'lib/publish_client.php'; echo publish_endpoint();"],
        cwd=str(ROOT), capture_output=True, text=True,
    )
    return out.stdout.strip() or "(unknown)"


def reconcile(document: str) -> bool:
    """Returns True when the two are in step afterwards."""
    out = subprocess.run(
        ["php", "-r", PROBE, "--", document],
        cwd=str(ROOT), capture_output=True, text=True,
    )

    try:
        answer = json.loads(out.stdout.strip())
    except ValueError:
        print(f"  FAIL  {document}: could not run the push\n"
              f"          {(out.stderr or out.stdout)[:300].strip()}")
        return False

    mine = answer["mine"]
    result = answer["result"]

    if result.get("ok") and answer.get("first"):
        print(f"  sent  {document}: never published before — minted revision {mine} "
              f"and sent it")
        return True

    if result.get("ok"):
        print(f"  sent  {document}: the live site was behind, and now holds {mine}")
        return True

    code = result.get("code", "refused")
    theirs = result.get("revision")

    if code == "not-newer" and theirs == mine:
        print(f"  ok    {document}: in step at revision {mine}")
        return True

    if code == "not-newer" and isinstance(theirs, int) and theirs > mine:
        print(f"  FAIL  {document}: the LIVE SITE is ahead — it holds {theirs}, "
              f"this host holds {mine}")
        print( "          Do not overwrite it. Something published from elsewhere, or")
        print( "          this host's record was restored from an older backup.")
        print(f"          Compare the two before deciding which is right.")
        return False

    print(f"  FAIL  {document}: {result.get('error', code)}")
    if isinstance(theirs, int):
        print(f"          the live site holds revision {theirs}; this host holds {mine}")
    return False


ASSET_PROBE = """
require 'lib/company.php';
require 'lib/upload.php';

$sent = 0; $held = 0; $failed = [];

foreach (upload_held() as $name) {
    $bytes = file_get_contents(UPLOAD_DIR . '/' . $name);
    if ($bytes === false) { $failed[$name] = 'could not be read on this host'; continue; }

    $kind = publish_asset_type($bytes);
    if ($kind === null) { $failed[$name] = 'is not a picture this site publishes'; continue; }

    $r = publish_asset($bytes, $kind[1]);
    if (($r['ok'] ?? false) !== true) { $failed[$name] = (string)($r['error'] ?? 'refused'); continue; }

    if ($r['held'] ?? false) { $held++; } else { $sent++; }
}

echo json_encode(['sent' => $sent, 'held' => $held, 'failed' => $failed]);
"""


def reconcile_assets() -> bool:
    """Send every stored picture the live site does not already hold.

    Content and pictures travel separately (ADR 0019), so they can go missing
    separately: a publish that failed halfway leaves the document naming a file
    the other host has not got, and the page draws a broken image with no
    warning anywhere. This is the repair for that half.

    Safe to run whenever. An asset is content-addressed, so re-sending one the
    live site already has is answered 'held' and writes nothing — there is no
    revision to roll back and nothing a replay could undo.
    """
    out = subprocess.run(["php", "-r", ASSET_PROBE],
                         cwd=str(ROOT), capture_output=True, text=True)
    try:
        answer = json.loads(out.stdout.strip())
    except ValueError:
        print("  FAIL  pictures: could not run the push\n"
              "          " + (out.stderr or out.stdout)[:300].strip())
        return False

    sent, held, failed = answer["sent"], answer["held"], answer["failed"]

    if failed:
        for name, why in failed.items():
            print("  FAIL  " + name + ": " + why)
        return False

    if sent:
        print("  sent  pictures: the live site was missing " + str(sent)
              + " of " + str(sent + held))
    else:
        print("  ok    pictures: all " + str(held) + " are already there")
    return True


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("root", nargs="?",
                    help="the site root, where lib/ is. Needed when this file has "
                         "been uploaded rather than run from the repository")
    ap.add_argument("document", nargs="?", help="just this one")
    args = ap.parse_args()

    # "reconcile.py careers" means the document, not a directory. Told apart by
    # asking whether it names a document rather than by counting arguments,
    # because guessing would make "reconcile.py contact" try to cd into it.
    global ROOT
    if args.root and args.document is None and not Path(args.root).expanduser().is_dir():
        args.root, args.document = None, args.root

    ROOT = locate_root(args.root)

    known = documents()

    if args.document and args.document not in known:
        raise SystemExit(f"{args.document!r} is not published. Known: {', '.join(known)}")

    wanted = [args.document] if args.document else known

    print(f"{endpoint()}\n")

    ok = all([reconcile(d) for d in wanted])

    # Only on a full run: asking for one document is asking about that
    # document, and walking every picture would be a surprise.
    if not args.document:
        ok = reconcile_assets() and ok

    print("\nBoth halves agree." if ok else
          "\nSomething is out of step. Read the lines above before forcing anything.")

    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
