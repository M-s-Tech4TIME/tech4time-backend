#!/usr/bin/env python3
"""
Ask the live admin whether the deploy actually landed.

Deploy tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/verify_live.py https://admin.tech4time.bd

WHY THIS EXISTS
A deploy can succeed at the transport and still leave a broken or exposed site,
and the two failures look nothing alike. A missing page is loud: somebody
clicks it and gets a 404. A document root pointed one level too high is silent
— the editor still works, every asset still loads, and the only difference is
that lib/auth.php is now a text file anybody can read.

THE 404s ARE THE POINT, AND THEY MUST BE 404s
On this host lib/, sections/ and content/ are OUTSIDE the document root, so a
request for them does not reach a rule that refuses — it reaches nothing at
all. That is ADR 0018, and it is why the expected answer is 404 and not 403.

A 403 here would be a finding, not a pass. It would mean those directories are
inside the document root after all and something is choosing to block them,
which is exactly the weaker arrangement this repository is shaped to avoid.
So they are asserted as 404 alone.

WHAT IT DOES NOT DO
It does not sign in. It answers one question — did the files and the shape that
protects them reach this host — and gives it back as an exit code CI can act on.
"""

import argparse
import ssl
import sys
import urllib.error
import urllib.request

TIMEOUT = 20

# (path, what it must answer, why it matters)
EXPECT = [
    ("/login.php",            (200,),      "the sign-in is served"),
    ("/",                     (200, 302),  "the front door answers, signed in or not"),
    ("/assets/css/admin.css", (200,),      "assets are served"),
    ("/assets/icons/sprite.svg", (200,),   "and the sprite the editor draws from"),
    ("/robots.txt",           (200,),      "crawlers are told to go away"),
    ("/no-such-page-here",    (404,),      "a miss is a miss"),

    # 404 exactly. See the note above: a 403 would mean these are inside the
    # document root and merely blocked, which is the arrangement ADR 0018
    # exists to replace.
    ("/lib/auth.php",         (404,),      "lib/ is OUTSIDE the document root, not blocked inside it"),
    ("/lib/private.php",      (404,),      "likewise the store locator"),
    ("/lib/publish.php",      (404,),      "likewise the publish key's reader"),
    ("/sections/careers.php", (404,),      "sections/ is outside it too"),
    ("/content/careers.json", (404,),      "and the system of record"),
    ("/tools/admin-cli.php",  (404,),      "tools/ is not deployed at all"),

    ("/t4t-private-admin/secret.key", (403, 404), "a store dropped in the web root is refused"),
    ("/.git/HEAD",            (403, 404),  "a directory whose name starts with a dot"),
    ("/.env",                 (403, 404),  "and every file inside one"),
    ("/README.md",            (403, 404),  "documentation is not the application"),
]

# (path, header, what must be in its value)
HEADERS = [
    ("/login.php", "content-security-policy",   "script-src"),
    ("/login.php", "x-content-type-options",    "nosniff"),
    ("/login.php", "strict-transport-security", "max-age="),
    # A BLANKET rule. The public site's is scoped to ^/admin(/|$), and on this
    # host the URI is "/" — so a copied rule would match nothing and fail
    # silently. Checked on two different paths for exactly that reason.
    ("/login.php", "x-robots-tag",              "noindex"),
    ("/",          "x-robots-tag",              "noindex"),
    ("/login.php", "cache-control",             "no-store"),
]


def fetch(url: str):
    """(status, headers) — an HTTP error is an answer, not an exception."""
    req = urllib.request.Request(url, method="GET", headers={
        "User-Agent": "tech4time-verify-live/1",
        "Cache-Control": "no-cache",
        "Pragma": "no-cache",
    })
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
            return r.status, {k.lower(): v for k, v in r.headers.items()}
    except urllib.error.HTTPError as e:
        return e.code, {k.lower(): v for k, v in e.headers.items()}
    except (urllib.error.URLError, ssl.SSLError, TimeoutError, OSError) as e:
        return None, {"error": str(e)}


def main() -> None:
    ap = argparse.ArgumentParser(description="Check a deployed admin over HTTP.")
    ap.add_argument("origin", help="e.g. https://admin.tech4time.bd")
    args = ap.parse_args()

    origin = args.origin.rstrip("/")
    print(f"{origin}\n{len(EXPECT)} paths, {len(HEADERS)} headers\n")

    failed = []
    passed = 0

    for path, allowed, why in EXPECT:
        status, info = fetch(origin + path)

        if status in allowed:
            passed += 1
            print(f"  ok    {status}  {path}")
        else:
            failed.append(path)
            wanted = " or ".join(str(a) for a in allowed)
            got = status if status is not None else info.get("error", "no answer")
            extra = ""
            if status == 403 and allowed == (404,):
                extra = ("\n          403 means this IS inside the document root and "
                         "something is blocking it.\n          Point the subdomain at "
                         "admin.tech4time.bd/public/ — see ADR 0018.")
            print(f"  FAIL  {path}\n          wanted {wanted}, got {got}\n"
                  f"          {why}{extra}")

    print()

    for path, header, needle in HEADERS:
        status, info = fetch(origin + path)
        value = info.get(header, "")

        if needle.lower() in value.lower():
            passed += 1
            print(f"  ok    {header}: {value[:60]}")
        else:
            failed.append(f"{path} {header}")
            print(f"  FAIL  {path} is missing {header}"
                  + (f" containing {needle!r}" if needle else "")
                  + f"\n          got: {value[:80] or '(absent)'}")

    total = len(EXPECT) + len(HEADERS)
    print(f"\n{passed}/{total} checks passed")

    if failed:
        print("\nfailed:")
        for name in failed:
            print(f"  - {name}")
        print("\nThe deploy reached the server; what it left there is not right.")
        sys.exit(1)

    print("\nThe admin is served, and nothing beside it is.")


if __name__ == "__main__":
    main()
