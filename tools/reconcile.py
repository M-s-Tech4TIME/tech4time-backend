#!/usr/bin/env python3
"""
Make the live site agree with this one, when a publish went missing.

Operations tool. NOT deployed to the web server (see tools/README.md).
Belongs to the BACKEND. Run from its repository root.

    python3 tools/reconcile.py            # every document
    python3 tools/reconcile.py careers    # one of them

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

import argparse
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

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


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("document", nargs="?", help="just this one")
    args = ap.parse_args()

    known = documents()

    if args.document and args.document not in known:
        raise SystemExit(f"{args.document!r} is not published. Known: {', '.join(known)}")

    wanted = [args.document] if args.document else known

    print(f"{endpoint()}\n")

    ok = all([reconcile(d) for d in wanted])

    print("\nBoth halves agree." if ok else
          "\nSomething is out of step. Read the lines above before forcing anything.")

    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
