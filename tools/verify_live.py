#!/usr/bin/env python3
"""
Ask a live site whether the deploy actually landed.

Deploy tool. NOT deployed to the web server (see tools/README.md).

    python3 tools/verify_live.py https://tech4time.bd

WHY THIS EXISTS
A deploy can succeed at the transport and still leave a broken site, and the
two failures look nothing alike.

A page that did not arrive is loud: somebody clicks it and gets a 404. A
.htaccess that did not arrive is silent — every page still renders, every
image still loads, and the only difference is that lib/, content/ and the
private store have stopped returning 403. Nothing about the site's appearance
will tell you, and the first person to find out will not be you.

So the 403s below matter more than the 200s. The 200s prove the site is up,
which anyone would notice; the 403s prove it is still closed, which nobody
would.

WHAT IT DOES NOT DO
It does not sign in, and it does not check content. It answers one question —
did the files and the rules that protect them reach this host — and gives it
back as an exit code CI can act on.
"""

import argparse
import ssl
import sys
import urllib.error
import urllib.request

TIMEOUT = 20

# (path, what it must answer, why it matters)
#
# A tuple of acceptable statuses, because the honest answer for a blocked path
# is "not 200" — 403 and 404 are both correct and which one a server picks is
# its business. Pinning one would make this fail on a host that chose the other.
EXPECT = [
    ("/",                         (200,),      "the home page"),
    ("/pages/about/",             (200,),      "a static page"),
    ("/pages/careers/",           (200,),      "PHP renders, and content/ is readable from disk"),
    ("/pages/contact/",           (200,),      "the other PHP page"),
    ("/assets/css/base.css",      (200,),      "assets are served"),
    ("/robots.txt",               (200,),      "crawlers are told what to do"),
    ("/sitemap.xml",              (200,),      "and where to go"),
    ("/no-such-page-here",        (404,),      "a miss is a miss"),

    ("/lib/private.php",          (403, 404),  "the store locator is not source-readable"),
    ("/lib/auth.php",             (403, 404),  "nor is the sign-in"),
    ("/content/careers.json",     (403, 404),  "live content is not fetchable"),
    ("/content/contact.json",     (403, 404),  "nor are the contact details"),
    ("/tools/host-probe.php",     (403, 404),  "tools/ is blocked even if one is left there"),
    ("/t4t-private/secret.key",   (403, 404),  "a store dropped in the web root is refused"),
    ("/.git/HEAD",                (403, 404),  "a directory whose name starts with a dot"),
    ("/.git/config",              (403, 404),  "and every file inside it"),
    ("/references/",              (403, 404),  "working material is not the website"),
    ("/README.md",                (403, 404),  "documentation is not the website"),
    ("/tech4time-website.zip",    (403, 404),  "an archive left in the web root"),
    ("/error_log",                (403, 404),  "the server's own log names paths"),

    ("/admin/",                   (200, 302),  "the admin answers, signed in or not"),
]

# (path, header, what must be in its value)
HEADERS = [
    ("/",        "content-security-policy",   "script-src"),
    ("/",        "x-content-type-options",    "nosniff"),
    ("/",        "referrer-policy",           ""),
    ("/",        "strict-transport-security", "max-age="),
    ("/admin/",  "x-robots-tag",              "noindex"),
]


def fetch(url: str):
    """(status, headers) — an HTTP error is an answer, not an exception."""
    req = urllib.request.Request(url, method="GET", headers={
        "User-Agent": "tech4time-verify-live/1",
        # Asking for a fresh copy: a cached 'Account set up: no' from a probe
        # is how an hour went missing once. Verification that reads a cache is
        # not verification.
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
    ap = argparse.ArgumentParser(description="Check a deployed site over HTTP.")
    ap.add_argument("origin", help="e.g. https://tech4time.bd")
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
            print(f"  FAIL  {path}\n          wanted {wanted}, got {got}\n"
                  f"          {why}")

    print()

    for path, header, needle in HEADERS:
        status, info = fetch(origin + path)
        value = info.get(header, "")

        if value and needle.lower() in value.lower():
            passed += 1
            print(f"  ok    {header}: {value[:60]}")
        else:
            failed.append(f"{path} {header}")
            print(f"  FAIL  {path} is missing {header}"
                  + (f" containing {needle!r}" if needle else "")
                  + (f"\n          got {value[:80]!r}" if value else ""))

    total = passed + len(failed)
    print(f"\n{passed}/{total} checks passed")

    if failed:
        print("\nA 403 that became a 200 is the dangerous direction: the site\n"
              "looks completely normal and lib/, content/ and the private store\n"
              "have stopped being protected. Check that .htaccess arrived.")
        sys.exit(1)


if __name__ == "__main__":
    main()
