<?php
/**
 * Tech4TIME — the JSON files that stand in for a database.
 *
 * Shared by lib/careers.php and lib/contact.php. Not reachable over HTTP:
 * .htaccess forbids /lib/.
 *
 * WHY A JSON FILE AND NOT A DATABASE
 * A handful of records, edited a few times a year, read on every page view. A
 * file read is faster than a database connection at this size, there is
 * nothing to provision on the host, and the whole dataset can be backed up by
 * downloading one file. The cost is that concurrent writes would clobber each
 * other — which matters for a comment system and does not matter for one
 * person editing a phone number.
 */

declare(strict_types=1);

/**
 * Read and decode a store, or null if it is missing, unreadable or not JSON.
 *
 * Never throws. Each caller turns null into a usable empty structure of its
 * own shape, because a page that renders the wrong thing is still a page a
 * visitor can act on, and a page that renders a PHP error is not.
 */
function store_read(string $file): ?array
{
    if (!is_readable($file)) {
        return null;
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Write a store, atomically, keeping one generation of backup.
 *
 * The write goes to a temp file in the same directory and is then renamed over
 * the target. rename() within a filesystem is atomic, so a visitor loading the
 * page mid-save reads either the old file or the new one, never a half-written
 * one.
 *
 * The caller sets 'updated' if it wants one — this does not, because the value
 * is part of what the caller may want to fingerprint.
 */
function store_write(string $file, array $data): bool
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        return false;
    }

    if (is_file($file)) {
        @copy($file, $file . '.bak');
    }

    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}
