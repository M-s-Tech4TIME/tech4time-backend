#!/usr/bin/env python3
"""
Prove the backend can put content on the public site, and that nothing else can.

Development tool. NOT deployed to the web server (see tools/README.md).
Run from the repo root:  python3 tools/test_publish.py
Requires the PHP CLI:    sudo apt install php-cli

WHY THIS EXISTS
After the split, api/publish.php is the ONLY route by which content reaches the
live site, and it is the only endpoint on the public site that writes anything
at all. Everything the two repositories do separately meets here.

So this drives the real endpoint over real HTTP with real signatures, and then
tries every way of getting past it that does not involve holding the key:

    no signature            a stranger who found the URL
    a signature from
      another key           the two stores have parted
    a tampered body         the payload changed in flight
    an old timestamp        a request captured and kept
    a replayed request      a request captured and sent again inside the window
    a lower revision        a stale retry arriving after a newer save
    a different contract    the two repositories are out of step
    a script tag            the backend is compromised and sending markup

The last two are the ones a signature does not answer, which is why they are
checked separately: a compromised backend signs perfectly well.

Every test runs against a COPY of the real data files, which are restored
afterwards whether the run passes or fails.
"""

import hashlib
import hmac
import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ENDPOINT = "/api/publish.php"

CAREERS = ROOT / "content" / "careers.json"
CONTACT = ROOT / "content" / "contact.json"

MARK = "PUBLISHMARK"


# ------------------------------------------------------------------ results


class Results:
    def __init__(self) -> None:
        self.passed = 0
        self.failed: list[str] = []

    def check(self, case: str, ok: bool, detail: str = "") -> bool:
        if ok:
            self.passed += 1
            print(f"  ok    {case}")
        else:
            self.failed.append(case)
            print(f"  FAIL  {case}" + (f"\n          {detail}" if detail else ""))
        return ok

    def report(self) -> int:
        total = self.passed + len(self.failed)
        print(f"\n{self.passed}/{total} checks passed")
        if self.failed:
            print("\nfailed:")
            for case in self.failed:
                print(f"  - {case}")
        return 1 if self.failed else 0


# ------------------------------------------------------------------- wiring


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def fingerprint(key: bytes) -> str:
    return hmac.new(key, b"publish-key-fingerprint", hashlib.sha256).hexdigest()[:16]


def sign(key: bytes, body: bytes, timestamp: int) -> str:
    mac = hmac.new(key, f"{timestamp}.".encode() + body, hashlib.sha256).hexdigest()
    return f"{fingerprint(key)}:{mac}"


def envelope(document: str, data: dict, version: int = 1) -> dict:
    return {
        "contract_version": version,
        "document": document,
        "revision": int(data.get("revision", 0)),
        "published": "2026-08-26T00:00:00+00:00",
        "data": data,
    }


def post(base: str, body: bytes, headers: dict) -> tuple[int, dict]:
    req = urllib.request.Request(base + ENDPOINT, data=body, method="POST")
    req.add_header("Content-Type", "application/json")
    for name, value in headers.items():
        req.add_header(name, value)
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, {"raw": raw[:300]}


def publish(base: str, key: bytes, document: str, data: dict,
            version: int = 1, at: int | None = None,
            tamper: bytes | None = None) -> tuple[int, dict]:
    body = json.dumps(envelope(document, data, version),
                      separators=(",", ":"), ensure_ascii=False).encode()
    stamp = int(time.time()) if at is None else at
    header = sign(key, body, stamp)
    return post(base, tamper if tamper is not None else body,
                {"X-T4T-Timestamp": str(stamp), "X-T4T-Signature": header})


def get(base: str, path: str) -> tuple[int, str]:
    try:
        with urllib.request.urlopen(base + path, timeout=15) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


# -------------------------------------------------------------------- cases


def job(revision: int, about: str = f"<p>{MARK}-about</p>") -> dict:
    return {
        "cv_form_url": "https://example.com/cv",
        "updated": "2026-08-26T00:00:00+00:00",
        "revision": revision,
        "jobs": [{
            "id": f"{MARK.lower()}-role",
            "title": f"{MARK} Engineer",
            "employment_type": "Full-Time",
            "work_arrangement": "On-site",
            "location": "Dhaka",
            "salary": "",
            "posted": "2026-08-01",
            "closes": "",
            "status": "open",
            "apply_url": "https://example.com/apply",
            "about": about,
            "responsibilities": "",
            "requirements": "",
            "must_have": "",
            "nice_to_have": "",
            "certifications": "",
            "offers": "",
        }],
    }


def run(base: str, key: bytes, private: Path, r: Results) -> None:
    other = bytes.fromhex("11" * 32)

    print("\nthe happy path")

    status, answer = publish(base, key, "careers", job(1))
    r.check("a signed document is accepted", status == 200 and answer.get("ok") is True,
            f"{status} {answer}")
    r.check("and the answer says which revision now stands",
            answer.get("revision") == 1, str(answer))
    r.check("and reports what the site's footers say",
            isinstance(answer.get("footer_synced"), str) and answer["footer_synced"] != "",
            "footer_synced should carry lib/footer-fingerprint.php")

    stored = json.loads(CAREERS.read_text())
    r.check("the replica on disk is what was sent",
            stored["jobs"][0]["title"] == f"{MARK} Engineer", str(stored)[:200])
    r.check("and carries the revision it was sent with",
            stored.get("revision") == 1, str(stored.get("revision")))

    status, page = get(base, "/pages/careers/")
    r.check("and a visitor sees it", f"{MARK} Engineer" in page,
            f"status {status}")

    print("\nnothing else gets in")

    body = json.dumps(envelope("careers", job(2)), separators=(",", ":")).encode()
    status, answer = post(base, body, {})
    r.check("an unsigned request is refused",
            status == 401 and answer.get("code") == "no-signature", f"{status} {answer}")
    r.check("and is told nothing about what is here",
            "revision" not in answer, str(answer))

    status, answer = publish(base, other, "careers", job(2))
    r.check("a signature from another key is refused",
            status == 401 and answer.get("code") == "unknown-key", f"{status} {answer}")
    r.check("and says the KEY is wrong, not the signature",
            "publish key" in answer.get("error", ""), str(answer))

    good = json.dumps(envelope("careers", job(2)), separators=(",", ":")).encode()
    status, answer = publish(base, key, "careers", job(2),
                             tamper=good.replace(b"Engineer", b"Engineer2"))
    r.check("a body changed after signing is refused",
            status == 401 and answer.get("code") == "bad-signature", f"{status} {answer}")

    status, answer = publish(base, key, "careers", job(2), at=int(time.time()) - 3600)
    r.check("an hour-old request is refused",
            status == 401 and answer.get("code") == "stale-timestamp", f"{status} {answer}")

    status, answer = publish(base, key, "careers", job(2), at=int(time.time()) + 3600)
    r.check("and so is one from an hour in the future",
            status == 401 and answer.get("code") == "stale-timestamp", f"{status} {answer}")

    print("\nreplays and rollbacks")

    # Byte-identical to the one that succeeded, signed now so the window cannot
    # be what stops it. Only the revision can.
    status, answer = publish(base, key, "careers", job(1))
    r.check("a replay of an accepted request changes nothing",
            status == 409 and answer.get("code") == "not-newer", f"{status} {answer}")
    r.check("and the answer says what revision stands",
            answer.get("revision") == 1, str(answer))

    status, answer = publish(base, key, "careers", job(5, f"<p>{MARK}-five</p>"))
    r.check("a newer revision is accepted", status == 200, f"{status} {answer}")

    status, answer = publish(base, key, "careers", job(4, "<p>rolled back</p>"))
    r.check("an older revision arriving late is refused",
            status == 409 and answer.get("code") == "not-newer", f"{status} {answer}")

    stored = json.loads(CAREERS.read_text())
    r.check("and the site still holds the newer one",
            stored["revision"] == 5 and f"{MARK}-five" in stored["jobs"][0]["about"],
            str(stored.get("revision")))

    print("\nthe shape has to match")

    status, answer = publish(base, key, "careers", job(6), version=2)
    r.check("a document in a shape this side does not implement is refused",
            status == 422 and answer.get("code") == "contract-mismatch",
            f"{status} {answer}")
    r.check("and says the two halves are out of step",
            "out of step" in answer.get("error", ""), str(answer))

    status, answer = publish(base, key, "pricing", job(6))
    r.check("a document nobody publishes is refused",
            status == 400 and answer.get("code") == "unknown-document",
            f"{status} {answer}")

    mismatched = job(7)
    body = json.dumps(
        {"contract_version": 1, "document": "careers", "revision": 99,
         "published": "2026-08-26T00:00:00+00:00", "data": mismatched},
        separators=(",", ":")).encode()
    stamp = int(time.time())
    status, answer = post(base, body, {"X-T4T-Timestamp": str(stamp),
                                       "X-T4T-Signature": sign(key, body, stamp)})
    r.check("an envelope disagreeing with its own document is refused",
            status == 400 and answer.get("code") == "revision-mismatch",
            f"{status} {answer}")

    print("\na signature is not a promise that the content is safe")

    status, answer = publish(base, key, "careers",
                             job(8, '<p onclick="steal()">hi</p><script>steal()</script>'))
    r.check("markup from a signed sender is still accepted", status == 200,
            f"{status} {answer}")

    stored = json.loads(CAREERS.read_text())
    about = stored["jobs"][0]["about"]
    r.check("but the script is gone by the time it is written",
            "<script" not in about and "onclick" not in about, about)
    r.check("and the words survive", "hi" in about, about)

    _, page = get(base, "/pages/careers/")
    r.check("so the visitor is served no script either",
            "steal()" not in page)

    print("\nthe method and the size")

    status, page = get(base, ENDPOINT)
    r.check("GET is refused", status == 405, str(status))

    huge = job(9)
    huge["jobs"][0]["about"] = "<p>" + ("x" * 1_200_000) + "</p>"
    status, answer = publish(base, key, "careers", huge)
    r.check("a payload past the cap is refused",
            status == 413 and answer.get("code") == "too-large", f"{status} {answer}")

    print("\nthe contact document travels the same road")

    contact = json.loads(CONTACT.read_text())
    contact["revision"] = 3
    contact["hero"]["title"] = f"{MARK} Contact"
    status, answer = publish(base, key, "contact", contact)
    r.check("the contact page publishes", status == 200 and answer.get("ok") is True,
            f"{status} {answer}")

    _, page = get(base, "/pages/contact/")
    r.check("and a visitor sees the change", f"{MARK} Contact" in page)

    print("\nthe backend's own client")

    out = subprocess.run(
        ["php", "-r",
         "require 'lib/publish_client.php'; require 'lib/careers.php';"
         "$d = careers_load(); $d['revision'] = 20;"
         "$d['jobs'][0]['title'] = 'CLIENT-" + MARK + "';"
         "echo json_encode(publish_push('careers', $d));"],
        cwd=str(ROOT), capture_output=True, text=True,
        env=dict(os.environ, T4T_PRIVATE=str(private),
                 T4T_PUBLISH_URL=base + ENDPOINT),
    )
    try:
        result = json.loads(out.stdout.strip() or "{}")
    except ValueError:
        result = {"raw": (out.stdout + out.stderr)[:300]}

    r.check("publish_push() reaches the endpoint and is accepted",
            result.get("ok") is True, str(result) + (out.stderr[:200] if out.stderr else ""))
    r.check("and hands the editor the revision that now stands",
            result.get("revision") == 20, str(result))
    r.check("and the footer fingerprint it was told",
            len(str(result.get("footer_synced", ""))) == 64, str(result))

    out = subprocess.run(
        ["php", "-r",
         "require 'lib/publish_client.php'; require 'lib/careers.php';"
         "$d = careers_load(); $d['revision'] = 2;"
         "echo json_encode(publish_push('careers', $d));"],
        cwd=str(ROOT), capture_output=True, text=True,
        env=dict(os.environ, T4T_PRIVATE=str(private),
                 T4T_PUBLISH_URL=base + ENDPOINT),
    )
    result = json.loads(out.stdout.strip() or "{}")
    r.check("a stale push comes back as a refusal the editor can show",
            result.get("ok") is False and result.get("code") == "not-newer",
            str(result))
    r.check("with a sentence rather than a code",
            "already holds" in str(result.get("error", "")), str(result))

    out = subprocess.run(
        ["php", "-r",
         "require 'lib/publish_client.php'; require 'lib/careers.php';"
         "$d = careers_load(); $d['revision'] = 30;"
         "echo json_encode(publish_push('careers', $d));"],
        cwd=str(ROOT), capture_output=True, text=True,
        env=dict(os.environ, T4T_PRIVATE=str(private),
                 T4T_PUBLISH_URL="http://127.0.0.1:1/api/publish.php"),
    )
    result = json.loads(out.stdout.strip() or "{}")
    r.check("an unreachable site is reported, not thrown",
            result.get("ok") is False and result.get("code") == "unreachable",
            str(result))


# --------------------------------------------------------------------- main


def main() -> None:
    if not shutil.which("php"):
        print("php not found — skipping. sudo apt install php-cli")
        return

    port = free_port()
    base = f"http://127.0.0.1:{port}"

    with tempfile.TemporaryDirectory() as tmp:
        private = Path(tmp) / "t4t-private"
        private.mkdir(mode=0o700)

        key_hex = "9f" * 32
        (private / "publish.key").write_text(key_hex + "\n")
        key = bytes.fromhex(key_hex)

        careers_backup = CAREERS.read_text() if CAREERS.is_file() else None
        contact_backup = CONTACT.read_text() if CONTACT.is_file() else None

        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT),
             str(ROOT / "tools" / "dev-router.php")],
            cwd=str(ROOT),
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            env=dict(os.environ, T4T_PRIVATE=str(private)),
            preexec_fn=os.setsid,
        )

        r = Results()
        try:
            for _ in range(80):
                try:
                    urllib.request.urlopen(base + "/", timeout=1).read()
                    break
                except urllib.error.HTTPError:
                    break
                except OSError:
                    time.sleep(0.05)
            else:
                raise SystemExit("the test server never came up")

            run(base, key, private, r)
        finally:
            os.killpg(os.getpgid(server.pid), signal.SIGTERM)
            server.wait(timeout=10)

            for path, backup in ((CAREERS, careers_backup), (CONTACT, contact_backup)):
                if backup is not None:
                    path.write_text(backup)
                bak = path.with_suffix(".json.bak")
                if bak.is_file():
                    bak.unlink()
            print("\ncontent/ restored")

        sys.exit(r.report())


if __name__ == "__main__":
    main()
