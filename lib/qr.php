<?php
/**
 * Tech4TIME — a QR encoder, for one purpose.
 *
 * WHY THIS EXISTS AT ALL
 * Pairing an authenticator meant typing a 32-character key into a phone by
 * hand. Every app also accepts a scanned code, and scanning is the way people
 * expect to do it — the typed key is the fallback, not the method. The comment
 * that used to sit at the top of lib/totp.php said a QR code was "several
 * hundred lines for a picture of a string" and deferred it. That was true and
 * it is still several hundred lines; it stopped being a good trade the moment
 * somebody had to pair a phone in a hurry.
 *
 * WHY NOT A LIBRARY
 * ADR 0001: no build step, no package manager. There is nothing to install
 * from, so it is written here or it does not exist.
 *
 * WHY NOT IN JAVASCRIPT
 * The CSP is script-src 'self', so it would have to be a file we ship anyway,
 * and enrolment has to work with JavaScript off like everything else. Rendering
 * on the server makes the code part of the HTML, which is where the typed key
 * already is.
 *
 * WHAT IT DOES AND DOES NOT DO
 * Byte mode, error-correction level M, versions 1 to 10 — up to 213 bytes,
 * where an otpauth:// URI is about 130. It is not a general encoder: no
 * numeric or alphanumeric mode (which would only make the code smaller), no
 * kanji, no versions above 10. If you hand it something longer than 213 bytes
 * it throws rather than silently producing a code that will not scan.
 *
 * It emits SVG, not a raster. Nothing to encode as a data: URI, it scales to
 * whatever the layout gives it, and it prints. The CSP allows img-src data:,
 * so a PNG would have been permitted too — SVG is simply better here.
 *
 * PROVED AGAINST A SECOND IMPLEMENTATION
 * tools/test_qr.py encodes the same strings with libqrencode and compares the
 * two matrices module for module, at a matched mask, then reads our own symbol
 * back and checks it says what went in. A QR code that is subtly wrong still
 * looks exactly like a QR code, so "it renders" proves nothing; the only useful
 * test is another encoder disagreeing.
 *
 * The two do disagree about which mask to choose, and that is allowed. The
 * penalty rule for the 1:1:3:1:1 finder-lookalike is implemented here as ISO
 * 18004 Table 11 describes it and in libqrencode as libqrencode does; every
 * mask yields a valid symbol, and the score only decides which is pleasantest
 * to read. The comparison is therefore made at a mask both were told to use.
 *
 * Reference: ISO/IEC 18004. Table numbers below refer to it.
 */

declare(strict_types=1);

/* Total data codewords per version at level M, and the error-correction block
   structure: [ec codewords per block, blocks in group 1, data codewords per
   block in group 1, blocks in group 2, data codewords per block in group 2].
   Table 9. Group 2 blocks hold exactly one more codeword than group 1. */
const QR_M = [
    1  => [10, 1, 16, 0, 0],
    2  => [16, 1, 28, 0, 0],
    3  => [26, 1, 44, 0, 0],
    4  => [18, 2, 32, 0, 0],
    5  => [24, 2, 43, 0, 0],
    6  => [16, 4, 27, 0, 0],
    7  => [18, 4, 31, 0, 0],
    8  => [22, 2, 38, 2, 39],
    9  => [22, 3, 36, 2, 37],
    10 => [26, 4, 43, 1, 44],
];

/** Centres of the alignment patterns, per version. Table E.1. */
const QR_ALIGN = [
    1  => [],
    2  => [6, 18],
    3  => [6, 22],
    4  => [6, 26],
    5  => [6, 30],
    6  => [6, 34],
    7  => [6, 22, 38],
    8  => [6, 24, 42],
    9  => [6, 26, 46],
    10 => [6, 28, 50],
];

/* ------------------------------------------------------- GF(256) arithmetic */

/**
 * Log and antilog tables for the field the Reed-Solomon coder works in:
 * GF(2^8) with the primitive polynomial 0x11D, as QR specifies.
 *
 * Built once per request rather than written out, because a table of 512
 * numbers in the source is a table nobody can check by reading.
 */
function qr_tables(): array
{
    static $exp = null, $log = null;
    if ($exp !== null) {
        return [$exp, $log];
    }

    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);

    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }
    /* Doubled so a product of two logs can be looked up without a modulo. */
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }

    return [$exp, $log];
}

/** Multiply in GF(256). Zero is special-cased: it has no logarithm. */
function qr_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    [$exp, $log] = qr_tables();

    return $exp[$log[$a] + $log[$b]];
}

/**
 * The generator polynomial for $degree error-correction codewords:
 * (x - a^0)(x - a^1)...(x - a^(degree-1)), coefficients high-order first.
 */
function qr_generator(int $degree): array
{
    [$exp, ] = qr_tables();
    $poly = [1];

    for ($i = 0; $i < $degree; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $coeff) {
            /* Coefficients are high-order first, so index j holds x^(L-1-j).
               Multiplying by x leaves the index where it is; multiplying by
               a^i moves it one along. Swapping these two lines produces a
               polynomial of the right degree with every coefficient wrong,
               which is to say a QR code that looks perfect and scans as
               nothing. */
            $next[$j]     ^= $coeff;
            $next[$j + 1] ^= qr_mul($coeff, $exp[$i]);
        }
        $poly = $next;
    }

    return $poly;
}

/** The remainder of $data * x^degree divided by the generator — the EC block. */
function qr_ec(array $data, int $degree): array
{
    $gen  = qr_generator($degree);
    $rest = array_merge($data, array_fill(0, $degree, 0));

    for ($i = 0; $i < count($data); $i++) {
        $lead = $rest[$i];
        if ($lead === 0) {
            continue;
        }
        foreach ($gen as $j => $coeff) {
            $rest[$i + $j] ^= qr_mul($coeff, $lead);
        }
    }

    return array_slice($rest, count($data));
}

/* ------------------------------------------------------------- the bitstream */

/** Smallest version that holds $len bytes at level M, or 0 if none does. */
function qr_version(int $len): int
{
    foreach (QR_M as $version => [$ec, $g1, $d1, $g2, $d2]) {
        $data = $g1 * $d1 + $g2 * $d2;
        /* 4 bits of mode, then the length: 8 bits below version 10, 16 at it. */
        $header = 4 + ($version < 10 ? 8 : 16);
        if ($data * 8 - $header >= $len * 8) {
            return $version;
        }
    }

    return 0;
}

/** Mode indicator, length, payload, terminator, padding — as one bit string. */
function qr_bitstream(string $text, int $version): array
{
    [$ec, $g1, $d1, $g2, $d2] = QR_M[$version];
    $capacity = ($g1 * $d1 + $g2 * $d2) * 8;

    $bits  = '0100';                                   // byte mode
    $bits .= str_pad(decbin(strlen($text)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach (str_split($text) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }

    /* Terminator: up to four zeros, fewer if the stream is nearly full. */
    $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));

    /* Pad to a codeword boundary, then alternate the two specified pad bytes. */
    if (strlen($bits) % 8 !== 0) {
        $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    }
    $pad = ['11101100', '00010001'];
    for ($i = 0; strlen($bits) < $capacity; $i++) {
        $bits .= $pad[$i % 2];
    }

    $codewords = [];
    foreach (str_split($bits, 8) as $byte) {
        $codewords[] = bindec($byte);
    }

    return $codewords;
}

/**
 * Split into blocks, compute each block's EC, then interleave both — data
 * codewords first, taking one from each block in turn, then the EC the same
 * way. Table 9 and clause 8.6.
 */
function qr_interleave(array $codewords, int $version): array
{
    [$ecLen, $g1, $d1, $g2, $d2] = QR_M[$version];

    $blocks = [];
    $offset = 0;
    for ($i = 0; $i < $g1; $i++) {
        $blocks[] = array_slice($codewords, $offset, $d1);
        $offset += $d1;
    }
    for ($i = 0; $i < $g2; $i++) {
        $blocks[] = array_slice($codewords, $offset, $d2);
        $offset += $d2;
    }

    $ecBlocks = [];
    foreach ($blocks as $block) {
        $ecBlocks[] = qr_ec($block, $ecLen);
    }

    $out     = [];
    $longest = max(array_map('count', $blocks));
    for ($i = 0; $i < $longest; $i++) {
        foreach ($blocks as $block) {
            if (isset($block[$i])) {
                $out[] = $block[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($ecBlocks as $block) {
            $out[] = $block[$i];
        }
    }

    return $out;
}

/* ------------------------------------------------------------- the matrix */

/**
 * A matrix of the fixed patterns, and a parallel map of which cells they
 * occupy. The map is what keeps the data placement from writing over them —
 * the alternative is checking coordinates in the placement loop, which is
 * where implementations get it subtly wrong.
 */
function qr_skeleton(int $version): array
{
    $size = $version * 4 + 17;
    $m    = array_fill(0, $size, array_fill(0, $size, 0));
    $used = array_fill(0, $size, array_fill(0, $size, false));

    /* Finder patterns and their separators, in three corners. */
    foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$c0, $r0]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $r0 + $r;
                $cc = $c0 + $c;
                if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                    continue;
                }
                $on = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                   || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                   || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $m[$rr][$cc]    = $on ? 1 : 0;
                $used[$rr][$cc] = true;
            }
        }
    }

    /* Timing patterns: alternating modules along row 6 and column 6. */
    for ($i = 8; $i < $size - 8; $i++) {
        $bit = ($i % 2 === 0) ? 1 : 0;
        $m[6][$i]    = $bit;
        $used[6][$i] = true;
        $m[$i][6]    = $bit;
        $used[$i][6] = true;
    }

    /* Alignment patterns, at every intersection except the three that would
       collide with a finder. */
    $centres = QR_ALIGN[$version];
    foreach ($centres as $r0) {
        foreach ($centres as $c0) {
            $nearFinder = ($r0 === 6 && $c0 === 6)
                       || ($r0 === 6 && $c0 === $size - 7)
                       || ($r0 === $size - 7 && $c0 === 6);
            if ($nearFinder) {
                continue;
            }
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $on = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                    $m[$r0 + $r][$c0 + $c]    = $on ? 1 : 0;
                    $used[$r0 + $r][$c0 + $c] = true;
                }
            }
        }
    }

    /* The dark module, always set, always here. */
    $m[$size - 8][8]    = 1;
    $used[$size - 8][8] = true;

    /* Reserve the format-information cells; they are written after masking. */
    for ($i = 0; $i <= 8; $i++) {
        if (!$used[8][$i]) {
            $used[8][$i] = true;
        }
        if (!$used[$i][8]) {
            $used[$i][8] = true;
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $used[8][$size - 1 - $i] = true;
        $used[$size - 1 - $i][8] = true;
    }

    /* And the version information, for versions 7 and up. */
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $used[$i][$size - 11 + $j] = true;
                $used[$size - 11 + $j][$i] = true;
            }
        }
    }

    return [$m, $used, $size];
}

/**
 * Walk the two-module-wide columns from bottom right to top left, upward then
 * downward, skipping the timing column, placing one bit per free cell.
 * Clause 8.7.1.
 */
function qr_place(array $m, array $used, int $size, array $codewords): array
{
    $bits = '';
    foreach ($codewords as $cw) {
        $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    }

    $i  = 0;
    $up = true;
    for ($right = $size - 1; $right > 0; $right -= 2) {
        if ($right === 6) {
            $right = 5;   // column 6 is the timing pattern; step over it
        }
        for ($v = 0; $v < $size; $v++) {
            $row = $up ? $size - 1 - $v : $v;
            for ($c = 0; $c < 2; $c++) {
                $col = $right - $c;
                if ($used[$row][$col]) {
                    continue;
                }
                /* Past the end of the stream the remainder bits are zero. */
                $m[$row][$col] = ($i < strlen($bits) && $bits[$i] === '1') ? 1 : 0;
                $i++;
            }
        }
        $up = !$up;
    }

    return $m;
}

/** The eight mask conditions. Table 10. */
function qr_mask_bit(int $mask, int $r, int $c): bool
{
    switch ($mask) {
        case 0: return ($r + $c) % 2 === 0;
        case 1: return $r % 2 === 0;
        case 2: return $c % 3 === 0;
        case 3: return ($r + $c) % 3 === 0;
        case 4: return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
        case 5: return ($r * $c) % 2 + ($r * $c) % 3 === 0;
        case 6: return (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0;
        default: return ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0;
    }
}

/**
 * The four penalty rules, summed. Lower is better; the mask with the lowest
 * total is the one used. Clause 8.8.2.
 */
function qr_penalty(array $m, int $size): int
{
    $score = 0;

    /* Rule 1 — runs of five or more of the same colour, in rows and columns. */
    for ($pass = 0; $pass < 2; $pass++) {
        for ($a = 0; $a < $size; $a++) {
            $run  = 1;
            $prev = -1;
            for ($b = 0; $b < $size; $b++) {
                $cell = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                if ($cell === $prev) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run  = 1;
                    $prev = $cell;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
    }

    /* Rule 2 — every 2x2 block of one colour. */
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                $score += 3;
            }
        }
    }

    /* Rule 3 — the finder-like 1:1:3:1:1 pattern with four light modules on
       either side, in rows and columns. */
    $a = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
    $b = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
    for ($pass = 0; $pass < 2; $pass++) {
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y <= $size - 11; $y++) {
                $matchA = true;
                $matchB = true;
                for ($k = 0; $k < 11; $k++) {
                    $cell = $pass === 0 ? $m[$x][$y + $k] : $m[$y + $k][$x];
                    if ($cell !== $a[$k]) {
                        $matchA = false;
                    }
                    if ($cell !== $b[$k]) {
                        $matchB = false;
                    }
                }
                if ($matchA) {
                    $score += 40;
                }
                if ($matchB) {
                    $score += 40;
                }
            }
        }
    }

    /* Rule 4 — how far the proportion of dark modules strays from half. */
    $dark = 0;
    foreach ($m as $row) {
        $dark += array_sum($row);
    }
    $pct = $dark * 100 / ($size * $size);
    $score += intdiv((int)floor(abs($pct - 50) / 5), 1) * 10;

    return $score;
}

/** BCH(15,5) format information for level M and the chosen mask. Clause 8.9. */
function qr_format_bits(int $mask): int
{
    $data = (0b00 << 3) | $mask;      // 00 is level M
    $rem  = $data << 10;
    for ($i = 4; $i >= 0; $i--) {
        if ($rem & (1 << ($i + 10))) {
            $rem ^= 0x537 << $i;
        }
    }

    return (($data << 10) | $rem) ^ 0x5412;
}

/** BCH(18,6) version information, for versions 7 and up. Clause 8.10. */
function qr_version_bits(int $version): int
{
    $rem = $version << 12;
    for ($i = 5; $i >= 0; $i--) {
        if ($rem & (1 << ($i + 12))) {
            $rem ^= 0x1F25 << $i;
        }
    }

    return ($version << 12) | $rem;
}

/** Write the format bits into both of their two locations. */
function qr_write_format(array $m, int $size, int $mask): array
{
    $bits = qr_format_bits($mask);

    for ($i = 0; $i < 15; $i++) {
        $bit = ($bits >> $i) & 1;

        /* Copy one: down the left of the top-left finder, then right along it. */
        if ($i < 6) {
            $m[$i][8] = $bit;
        } elseif ($i === 6) {
            $m[7][8] = $bit;
        } elseif ($i === 7) {
            $m[8][8] = $bit;
        } elseif ($i === 8) {
            $m[8][7] = $bit;
        } else {
            $m[8][14 - $i] = $bit;
        }

        /* Copy two: along the bottom-left and the top-right. */
        if ($i < 8) {
            $m[8][$size - 1 - $i] = $bit;
        } else {
            $m[$size - 15 + $i][8] = $bit;
        }
    }

    return $m;
}

/** And the version bits, in their two blocks, for versions 7 and up. */
function qr_write_version(array $m, int $size, int $version): array
{
    if ($version < 7) {
        return $m;
    }
    $bits = qr_version_bits($version);

    for ($i = 0; $i < 18; $i++) {
        $bit = ($bits >> $i) & 1;
        $r   = intdiv($i, 3);
        $c   = $i % 3;
        $m[$r][$size - 11 + $c] = $bit;
        $m[$size - 11 + $c][$r] = $bit;
    }

    return $m;
}

/* ------------------------------------------------------------------- public */

/**
 * The finished module matrix: rows of 0 and 1, no quiet zone.
 *
 * @throws RuntimeException if the text is longer than version 10 holds.
 */
function qr_matrix(string $text): array
{
    $version = qr_version(strlen($text));
    if ($version === 0) {
        throw new RuntimeException(
            'Too long for this encoder: ' . strlen($text) . ' bytes, and version 10 '
            . 'at level M holds 213. Nothing here should be near that — if an '
            . 'otpauth:// URI has grown this far, something else is wrong.'
        );
    }

    $codewords = qr_interleave(qr_bitstream($text, $version), $version);
    [$skeleton, $used, $size] = qr_skeleton($version);
    $placed = qr_place($skeleton, $used, $size, $codewords);

    /* Try all eight masks and keep the least-penalised. The mask is applied to
       every cell the fixed patterns do not own. */
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $candidate = $placed;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$used[$r][$c] && qr_mask_bit($mask, $r, $c)) {
                    $candidate[$r][$c] ^= 1;
                }
            }
        }
        $candidate = qr_write_format($candidate, $size, $mask);
        $candidate = qr_write_version($candidate, $size, $version);

        $score = qr_penalty($candidate, $size);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best      = $candidate;
        }
    }

    return $best;
}

/**
 * The same thing as SVG markup, ready to echo into a page.
 *
 * One <path> of rectangles rather than one <rect> per module: a version 6 code
 * is 41x41, and several hundred elements is markup nobody wants to read or
 * send. The quiet zone is part of the viewBox because a code without one does
 * not reliably scan.
 *
 * No style attribute and no <style> block — the CSP forbids both. Colour comes
 * from `fill="currentColor"`, so the code follows the text colour of whatever
 * it sits in and is correct in dark mode without being told about it.
 */
function qr_svg(string $text, string $describedBy = ''): string
{
    $m     = qr_matrix($text);
    $size  = count($m);
    $quiet = 4;
    $span  = $size + $quiet * 2;

    $path = '';
    foreach ($m as $r => $row) {
        foreach ($row as $c => $on) {
            if ($on) {
                $path .= 'M' . ($c + $quiet) . ' ' . ($r + $quiet) . 'h1v1h-1z';
            }
        }
    }

    $described = $describedBy !== ''
        ? ' aria-describedby="' . htmlspecialchars($describedBy, ENT_QUOTES, 'UTF-8') . '"'
        : '';

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $span . ' ' . $span . '"'
         . ' class="qr" role="img"' . $described
         . ' aria-label="QR code for pairing an authenticator app">'
         . '<rect width="' . $span . '" height="' . $span . '" fill="none"/>'
         . '<path d="' . $path . '" fill="currentColor"/>'
         . '</svg>';
}
