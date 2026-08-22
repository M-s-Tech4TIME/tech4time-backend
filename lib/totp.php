<?php
/**
 * Tech4TIME — the six digits from an authenticator app.
 *
 * TOTP, RFC 6238. The admin's phone and this server share one secret and both
 * derive the same number from it and the clock, so proving you hold the phone
 * needs no message between them — no SMS, no email, no third-party service, and
 * nothing to be unavailable at the moment you need to sign in.
 *
 * WHY THIS IS WRITTEN BY HAND
 * The same reason lib/html.php is: there is nothing to install on this host and
 * no build step to install it with. The algorithm is small — an HMAC, a
 * truncation and a modulo — and the whole of it is checked against the test
 * vectors published in the RFC by tools/test_admin_auth.py, which is the only
 * reason to trust an implementation like this one.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * No QR code. Encoding one is several hundred lines for a picture of a string
 * every authenticator app will also accept typed in, so enrolment shows the
 * setup key instead. Worth revisiting; not worth blocking on.
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 */

declare(strict_types=1);

/** RFC 4648 base32. The alphabet avoids 0/1/8 so it can be read aloud. */
const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

const TOTP_STEP   = 30;   // seconds per code, and what every app assumes
const TOTP_DIGITS = 6;
const TOTP_DRIFT  = 1;    // steps either side, for a phone clock that is slightly out

/* ----------------------------------------------------------------- base32 */

function totp_base32_encode(string $bytes): string
{
    $out    = '';
    $buffer = 0;
    $bits   = 0;

    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bits  += 8;

        while ($bits >= 5) {
            $bits -= 5;
            $out  .= TOTP_ALPHABET[($buffer >> $bits) & 31];
        }
    }

    if ($bits > 0) {
        $out .= TOTP_ALPHABET[($buffer << (5 - $bits)) & 31];
    }

    return $out;
}

/**
 * Decode, ignoring anything that is not an alphabet character.
 *
 * That tolerance is the point: the key is shown in groups of four for typing,
 * and somebody pasting it back will bring the spaces with it. Lower case is
 * accepted for the same reason.
 */
function totp_base32_decode(string $text): string
{
    $text   = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text) ?? '');
    $out    = '';
    $buffer = 0;
    $bits   = 0;

    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $value = strpos(TOTP_ALPHABET, $text[$i]);
        if ($value === false) {
            continue;
        }

        $buffer = ($buffer << 5) | $value;
        $bits  += 5;

        if ($bits >= 8) {
            $bits -= 8;
            $out  .= chr(($buffer >> $bits) & 255);
        }
    }

    return $out;
}

/* ----------------------------------------------------------------- codes */

/** A fresh secret. 20 bytes is what RFC 4226 recommends for SHA-1. */
function totp_secret(int $bytes = 20): string
{
    return totp_base32_encode(random_bytes($bytes));
}

/** The code for one counter — the step number, not the clock. */
function totp_code(string $secret, int $counter, int $digits = TOTP_DIGITS): string
{
    $key = totp_base32_decode($secret);

    /* J: unsigned 64-bit, big-endian. The counter goes on the wire in network
       byte order, and every implementation agrees on that or none interoperate. */
    $hash = hash_hmac('sha1', pack('J', $counter), $key, true);

    /* Dynamic truncation, RFC 4226 §5.3: the low nibble of the last byte says
       where to read four bytes from, and the top bit of those is masked off so
       the result is the same on machines that would read it as signed. */
    $offset = ord($hash[19]) & 0x0F;
    $number = ((ord($hash[$offset])     & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            |  (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string)($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Which step we are in now. */
function totp_counter(?int $time = null): int
{
    return intdiv($time ?? time(), TOTP_STEP);
}

/**
 * Check a typed code, allowing for a phone whose clock is a little out.
 *
 * Returns the counter it matched, or null. The counter matters to the caller:
 * storing the last one accepted and refusing anything at or below it is what
 * stops a code being replayed inside the thirty seconds it stays valid.
 *
 * hash_equals rather than === so the comparison does not leak the code one
 * digit at a time through timing.
 */
function totp_verify(string $secret, string $code, ?int $after = null, ?int $time = null): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';

    if (strlen($code) !== TOTP_DIGITS || totp_base32_decode($secret) === '') {
        return null;
    }

    $now = totp_counter($time);

    for ($drift = -TOTP_DRIFT; $drift <= TOTP_DRIFT; $drift++) {
        $counter = $now + $drift;

        if ($after !== null && $counter <= $after) {
            continue;      // already used, or older than one already used
        }

        if (hash_equals(totp_code($secret, $counter), $code)) {
            return $counter;
        }
    }

    return null;
}

/* -------------------------------------------------------------- enrolment */

/** The otpauth:// URI an authenticator app understands. */
function totp_uri(string $issuer, string $account, string $secret): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);

    return 'otpauth://totp/' . $label . '?' . http_build_query([
        'secret'    => $secret,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => TOTP_DIGITS,
        'period'    => TOTP_STEP,
    ], '', '&', PHP_QUERY_RFC3986);
}

/** The key in groups of four, because it is going to be typed by a person. */
function totp_format(string $secret): string
{
    return trim(chunk_split($secret, 4, ' '));
}
