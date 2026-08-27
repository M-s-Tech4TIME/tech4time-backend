#!/usr/bin/env python3
"""
Prove lib/qr.php against a second implementation, and against itself.

Run from the repo root:  python3 tools/test_qr.py

WHY IT COMPARES RATHER THAN INSPECTS
A QR code that is subtly wrong still looks exactly like a QR code. A wrong
mask, one transposed Reed-Solomon coefficient, a format field off by a bit —
every one of those renders a convincing square of noise that no phone will
read, and no amount of looking at it says so. The only test worth having is a
different encoder producing a different answer.

That encoder is libqrencode (`qrencode`), which is not a dependency of anything
we ship: it runs here, in development, and never on the server. If it is not
installed this exits 0 with a notice, the same way the browser tests do.

    sudo apt install qrencode

WHY IT DOES NOT COMPARE THE MASK
The two disagree about which of the eight masks to use, and that disagreement
is allowed. The mask is chosen by a penalty score, and libqrencode's third
rule — the 1:1:3:1:1 finder-lookalike — does not evaluate the way ISO 18004
Table 11 describes; this is long-known and does not make either code invalid.
Every mask produces a correct, scannable symbol; the score only decides which
is the pleasantest to read.

So the comparison is made *at a matched mask*, which is the part that must
agree: the same data, the same error correction, the same interleaving, the
same placement, the same format and version bits. Then, separately, the symbol
this encoder actually emits is decoded back and checked to say what went in —
which is what proves the mask it picked for itself is written down correctly.
"""

import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# The same block structure lib/qr.php holds, needed here only to undo it.
QR_M = {
    1: (10, 1, 16, 0, 0),  2: (16, 1, 28, 0, 0),  3: (26, 1, 44, 0, 0),
    4: (18, 2, 32, 0, 0),  5: (24, 2, 43, 0, 0),  6: (16, 4, 27, 0, 0),
    7: (18, 4, 31, 0, 0),  8: (22, 2, 38, 2, 39), 9: (22, 3, 36, 2, 37),
    10: (26, 4, 43, 1, 44),
}

# The real thing, then the edges: one byte, lengths that land exactly on a
# version boundary, and one that forces the 16-bit length field at version 10.
CASES = [
    "otpauth://totp/Tech4TIME:admin%40tech4time.bd?secret=JBSWY3DPEHPK3PXP"
    "&issuer=Tech4TIME&algorithm=SHA1&digits=6&period=30",
    "a",
    "https://admin.tech4time.bd/",
    "0" * 14,     # exactly version 1 at level M
    "0" * 15,     # one past it
    "x" * 106,    # exactly version 6
    "y" * 122,    # exactly version 7 — the first with version information
    "z" * 180,    # exactly version 9
    "w" * 213,    # exactly version 10, and the 16-bit length field
    "Tech4TIME — orchestrating technology with time. Åéü",
]


def php(code: str, stdin: str = "") -> str:
    r = subprocess.run(["php", "-r", code], input=stdin.encode(),
                       capture_output=True)
    if r.returncode != 0:
        raise SystemExit("lib/qr.php failed:\n" + r.stderr.decode()[:800])
    return r.stdout.decode()


def ours(text: str, mask: int | None = None) -> list[list[int]]:
    """Our matrix. With `mask` given, that mask is forced instead of chosen."""
    if mask is None:
        code = f'''require "{ROOT}/lib/qr.php";
            foreach (qr_matrix(file_get_contents("php://stdin")) as $r)
                echo implode("", $r), "\\n";'''
    else:
        code = f'''require "{ROOT}/lib/qr.php";
            $t = file_get_contents("php://stdin");
            $v = qr_version(strlen($t));
            $cw = qr_interleave(qr_bitstream($t, $v), $v);
            [$sk, $u, $s] = qr_skeleton($v);
            $c = qr_place($sk, $u, $s, $cw);
            for ($r = 0; $r < $s; $r++)
                for ($x = 0; $x < $s; $x++)
                    if (!$u[$r][$x] && qr_mask_bit({mask}, $r, $x)) $c[$r][$x] ^= 1;
            $c = qr_write_format($c, $s, {mask});
            $c = qr_write_version($c, $s, $v);
            foreach ($c as $r) echo implode("", $r), "\\n";'''
    return [[int(ch) for ch in line]
            for line in php(code, text).strip().split("\n")]


def theirs(text: str) -> list[list[int]]:
    """The same matrix from libqrencode. -8 keeps it in byte mode, -l M matches
    our error-correction level, -m 0 drops the quiet zone we add ourselves."""
    r = subprocess.run(
        ["qrencode", "-8", "-l", "M", "-m", "0", "-t", "ASCII", "-o", "-"],
        input=text.encode(), capture_output=True)
    if r.returncode != 0:
        raise SystemExit("qrencode failed:\n" + r.stderr.decode()[:800])

    rows = []
    for line in r.stdout.decode().split("\n"):
        if not line.strip():
            continue
        # ASCII output is two characters per module: '  ' light, '##' dark.
        pairs = [line[i:i + 2] for i in range(0, len(line), 2)]
        rows.append([0 if p.strip() == "" else 1 for p in pairs])
    return rows


# --------------------------------------------------------------- reading back

def function_map(size: int, version: int) -> list[list[bool]]:
    """Which cells the fixed patterns own — the same map lib/qr.php builds, so
    that reading a symbol back walks the data in the same order it was laid."""
    used = [[False] * size for _ in range(size)]
    for c0, r0 in [(0, 0), (size - 7, 0), (0, size - 7)]:
        for r in range(-1, 8):
            for c in range(-1, 8):
                if 0 <= r0 + r < size and 0 <= c0 + c < size:
                    used[r0 + r][c0 + c] = True
    for i in range(size):
        used[6][i] = True
        used[i][6] = True

    centres = {1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30],
               6: [6, 34], 7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46],
               10: [6, 28, 50]}[version]
    for r0 in centres:
        for c0 in centres:
            if (r0 == 6 and c0 == 6) or (r0 == 6 and c0 == size - 7) \
               or (r0 == size - 7 and c0 == 6):
                continue
            for r in range(-2, 3):
                for c in range(-2, 3):
                    used[r0 + r][c0 + c] = True

    used[size - 8][8] = True
    for i in range(9):
        used[8][i] = True
        used[i][8] = True
    for i in range(8):
        used[8][size - 1 - i] = True
        used[size - 1 - i][8] = True
    if version >= 7:
        for i in range(6):
            for j in range(3):
                used[i][size - 11 + j] = True
                used[size - 11 + j][i] = True
    return used


def mask_bit(mask: int, r: int, c: int) -> bool:
    return [(r + c) % 2 == 0, r % 2 == 0, c % 3 == 0, (r + c) % 3 == 0,
            (r // 2 + c // 3) % 2 == 0, (r * c) % 2 + (r * c) % 3 == 0,
            ((r * c) % 2 + (r * c) % 3) % 2 == 0,
            ((r + c) % 2 + (r * c) % 3) % 2 == 0][mask]


def read_mask(m: list[list[int]]) -> tuple[int, int]:
    """The error-correction level and mask, out of the second format copy."""
    size = len(m)
    bits = 0
    for i in range(8):
        bits |= m[8][size - 1 - i] << i
    for i in range(8, 15):
        bits |= m[size - 15 + i][8] << i
    v = bits ^ 0x5412          # the format field is stored already masked
    return (v >> 13) & 0b11, (v >> 10) & 0b111


def decode(m: list[list[int]], version: int) -> str:
    """Walk the symbol back out: unmask, read the zigzag, take the header off.
    Error correction is not applied — nothing here is damaged, and a decoder
    that repaired its own encoder's mistakes would prove nothing."""
    size = len(m)
    _, mask = read_mask(m)
    used = function_map(size, version)

    bits = []
    up, right = True, size - 1
    while right > 0:
        if right == 6:
            right = 5
        for v in range(size):
            row = size - 1 - v if up else v
            for c in range(2):
                col = right - c
                if used[row][col]:
                    continue
                b = m[row][col]
                if mask_bit(mask, row, col):
                    b ^= 1
                bits.append(b)
        up = not up
        right -= 2

    stream = "".join(str(b) for b in bits)
    codewords = [int(stream[i:i + 8], 2) for i in range(0, len(stream) - 7, 8)]

    # De-interleave. From version 4 up the data is split into blocks and the
    # codewords are written one per block in turn, so reading the stream
    # straight through gives the right bytes in the wrong order — which looks
    # like a decoder bug and is in fact the format working as specified.
    ec, g1, d1, g2, d2 = QR_M[version]
    sizes = [d1] * g1 + [d2] * g2
    blocks: list[list[int]] = [[] for _ in sizes]
    i = 0
    for n in range(max(sizes)):
        for b, size in enumerate(sizes):
            if n < size:
                blocks[b].append(codewords[i])
                i += 1
    data = [cw for block in blocks for cw in block]

    head = "".join(f"{cw:08b}" for cw in data)
    if head[:4] != "0100":
        raise ValueError(f"mode is {head[:4]}, not byte mode")
    nbits = 8 if version < 10 else 16
    length = int(head[4:4 + nbits], 2)
    start = 4 + nbits
    payload = head[start:start + length * 8]
    return bytes(int(payload[i:i + 8], 2)
                 for i in range(0, len(payload), 8)).decode("utf-8")


def svg_matrix(svg: str, size: int) -> list[list[int]]:
    """Read the drawn modules back out of the SVG path.

    Between a correct matrix and a correct picture of it sits a coordinate
    system, and an off-by-one in the quiet-zone offset would move every module
    one place while still producing something that looks exactly like a QR
    code. So the path is parsed rather than trusted."""
    m = [[0] * size for _ in range(size)]
    d = re.search(r'<path d="([^"]*)"', svg)
    if not d:
        raise ValueError("no path in the SVG")

    quiet = 4
    for x, y in re.findall(r"M(\d+) (\d+)h1v1h-1z", d.group(1)):
        c, r = int(x) - quiet, int(y) - quiet
        if not (0 <= r < size and 0 <= c < size):
            raise ValueError(f"module drawn outside the symbol at {r},{c}")
        m[r][c] = 1
    return m


def main() -> int:
    if not shutil.which("php"):
        print("This test needs the PHP CLI.")
        return 0
    if not shutil.which("qrencode"):
        print("qrencode is not installed, so there is nothing to compare against.")
        print("  sudo apt install qrencode")
        return 0

    print("lib/qr.php  —  byte mode, level M\n")
    print("  A  every module matches libqrencode, at a matched mask")
    print("  B  the symbol we actually emit decodes back to what went in")
    print("  C  the SVG draws that symbol, and nothing the CSP forbids\n")

    failures = 0
    for text in CASES:
        label = (text[:44] + "…") if len(text) > 47 else text

        ref = theirs(text)
        _, ref_mask = read_mask(ref)
        mine_matched = ours(text, ref_mask)

        size = len(ref)
        version = (size - 17) // 4

        if len(mine_matched) != size:
            print(f"  FAIL  {label}")
            print(f"        version differs: {len(mine_matched)} vs {size}")
            failures += 1
            continue

        bad = [(r, c) for r in range(size) for c in range(size)
               if mine_matched[r][c] != ref[r][c]]
        if bad:
            print(f"  FAIL  {label}")
            print(f"        A: {len(bad)} of {size * size} modules differ at mask "
                  f"{ref_mask}, first at row {bad[0][0]}, column {bad[0][1]}")
            failures += 1
            continue

        mine = ours(text)
        _, own_mask = read_mask(mine)
        try:
            back = decode(mine, version)
        except Exception as e:
            print(f"  FAIL  {label}")
            print(f"        B: could not read it back — {e}")
            failures += 1
            continue

        if back != text:
            print(f"  FAIL  {label}")
            print(f"        B: decoded to {back[:60]!r}")
            failures += 1
            continue

        svg = php(f'require "{ROOT}/lib/qr.php"; '
                  f'echo qr_svg(file_get_contents("php://stdin"));', text)
        drawn = svg_matrix(svg, size)
        if drawn != mine:
            wrong = sum(1 for r in range(size) for c in range(size)
                        if drawn[r][c] != mine[r][c])
            print(f"  FAIL  {label}")
            print(f"        C: the SVG draws {wrong} modules that the matrix does not")
            failures += 1
            continue
        if "style=" in svg or "<script" in svg.lower():
            print(f"  FAIL  {label}")
            print("        C: the SVG carries something style-src/script-src 'self' refuses")
            failures += 1
            continue

        print(f"  ok    v{version:<2} {size:>2}x{size:<2} {len(text):>4} bytes  "
              f"mask {own_mask} (theirs {ref_mask})   {label}")

    # The refusal matters as much as the happy path: silently emitting an
    # unscannable code would be the worst outcome available.
    over = subprocess.run(
        ["php", "-r", f'require "{ROOT}/lib/qr.php"; qr_matrix(str_repeat("a", 214));'],
        capture_output=True)
    if over.returncode == 0:
        print("\n  FAIL  214 bytes was accepted; version 10 at level M holds 213")
        failures += 1
    else:
        print("\n  ok    214 bytes is refused rather than silently truncated")

    print()
    if failures:
        print(f"{failures} of {len(CASES) + 1} failed.")
        return 1

    print(f"All {len(CASES) + 1} checks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
