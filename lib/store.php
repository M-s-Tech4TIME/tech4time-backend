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
 * Why a store could not be read, told apart.
 *
 * store_read() answers null for a file that was never there and for one that
 * is damaged. That is the right shape for site copy: both mean "fall back to
 * the defaults", the page still renders, and a visitor can still act on it.
 *
 * It is the wrong shape for the account file, where the two mean opposite
 * things. Absent means nobody has set this site up yet, and offering setup is
 * correct. Damaged means every credential is in a file that will not parse —
 * and offering setup there is how the last good copy gets destroyed, because
 * the first save copies the damaged file over its own .bak.
 *
 * Told apart, the caller that must refuse can refuse.
 *
 * @return string 'ok', 'missing', 'unreadable' or 'corrupt'
 */
function store_state(string $file): string
{
    if (!file_exists($file)) {
        return 'missing';
    }

    if (!is_readable($file)) {
        return 'unreadable';
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        return 'unreadable';
    }

    /* Anything that does not decode to an array is damaged, and that includes
       the empty file json_decode() also answers null for. A store with nothing
       in it is still "{}" on disk — zero bytes never came from store_write(),
       it came from a write that did not finish. */
    return is_array(json_decode($raw, true)) ? 'ok' : 'corrupt';
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
 *
 * The backup is kept one generation deep, and a damaged file is never allowed
 * to become that generation — see the comment on the copy below.
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
        $backup = $file . '.bak';

        /* Never let a damaged file become the backup, because the backup is
           what a damaged file gets recovered FROM. Copying over it turns a
           rescuable mistake into permanent loss at exactly the moment somebody
           is about to need it — and the moment is not hypothetical: a store
           that will not parse reads as empty, so the editor shows an empty
           page and the first save is the one that overwrites the copy holding
           everything. A file that does not parse is not a generation worth
           keeping. */
        if (store_state($file) === 'ok' || store_state($backup) !== 'ok') {
            @copy($file, $backup);
        }
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

/**
 * Read, change and write one file under a single exclusive lock.
 *
 * store_read() then store_write() is two steps with a gap between them, which
 * is correct for a person saving a form — nobody else is editing the contact
 * page in the same half-second. It is wrong for anything the requests racing
 * each other are themselves changing: two failed logins landing together would
 * each read "3", each write "4", and one of them would vanish. That is not a
 * rounding error, it is the attacker's best move.
 *
 * $change receives the decoded data by reference and returns whatever the
 * caller wants back. Everything using this gets its locking from one place,
 * where it is either right or wrong exactly once.
 *
 * Throws rather than returning a default: a counter that cannot be written has
 * to stop the caller, because carrying on means carrying on uncounted.
 */
function store_edit(string $file, callable $change): mixed
{
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not open ' . $file . ' for editing');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock ' . $file);
        }

        $raw  = stream_get_contents($handle) ?: '';
        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : [];

        $return = $change($data);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json !== false) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json . "\n");
            fflush($handle);
        }

        flock($handle, LOCK_UN);

        return $return;
    } finally {
        fclose($handle);
    }
}
