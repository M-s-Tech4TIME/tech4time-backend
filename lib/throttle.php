<?php
/**
 * Tech4TIME — counting attempts, so guessing costs something.
 *
 * A password that takes one guess to try takes no time at all to attack. This
 * puts a price on the guess: a handful are free, and after that the door stays
 * shut for a while whether or not the next guess happens to be right.
 *
 * WHY NOT store_write()
 * lib/store.php reads a file and writes it in two separate steps, which is
 * correct for a person saving a form — nobody else is editing the contact page
 * in the same half-second. A counter is the opposite case: the requests racing
 * each other ARE the thing being counted, and two failures landing together
 * would each read "3", each write "4", and one of them would vanish. That is
 * not a rounding error, it is the attacker's best move. So this holds an
 * exclusive lock across the read and the write, which store_write() does not.
 *
 * WHY THE KEYS ARE HASHED
 * The file would otherwise be a list of the usernames and addresses somebody
 * tried, sitting on disk. It is only ever compared against, never read back, so
 * there is nothing to lose by keeping it opaque.
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 */

declare(strict_types=1);

require_once __DIR__ . '/private.php';
require_once __DIR__ . '/store.php';

/** Beyond the limit, each further attempt lengthens the wait, up to this. */
const THROTTLE_MAX_BLOCK = 3600;

/**
 * The caller's address.
 *
 * REMOTE_ADDR only. X-Forwarded-For is a request header like any other — an
 * attacker sets it to whatever they like, and a throttle keyed on it is a
 * throttle that resets itself on demand. cPanel serves this site directly, so
 * REMOTE_ADDR is the real client; if a CDN is ever put in front, this is the
 * one function that has to learn about it.
 */
function throttle_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** An opaque, stable name for a counter. */
function throttle_key(string $scope, string $value): string
{
    return substr(hash_hmac('sha256', $scope . ':' . strtolower(trim($value)), t4t_key('throttle')), 0, 32);
}

/* ------------------------------------------------------------- the counter */

/**
 * Read, change and write the counter file under one exclusive lock.
 *
 * The locking itself lives in store_edit(); this adds the pruning and the file
 * mode, so every counter in this file gets both without remembering to.
 */
function throttle_edit(callable $change): mixed
{
    $path = t4t_private_path('throttle');

    /* Fail closed. store_edit() throws when it cannot open or lock the file, and
       that exception is left to reach the caller: answering "not blocked"
       because the disk is full would turn a disk problem into an open door. */
    $result = store_edit($path, static function (array &$table) use ($change): mixed {
        throttle_prune($table);
        return $change($table);
    });

    @chmod($path, 0600);

    return $result;
}

/** Drop entries nothing is waiting on, so the file does not grow forever. */
function throttle_prune(array &$table): void
{
    $now = time();

    foreach ($table as $key => $row) {
        $until = (int)($row['until'] ?? 0);
        $last  = (int)($row['last'] ?? 0);

        if ($until < $now && $last < $now - THROTTLE_MAX_BLOCK) {
            unset($table[$key]);
        }
    }
}

/* -------------------------------------------------------------- the rules */

/**
 * How long a caller must wait before this action is allowed again.
 *
 * 0 means go ahead. Anything else is seconds, and the caller should refuse
 * without doing the work — in particular without verifying a password, so that
 * a locked-out account does not become a way to test whether a guess was right.
 */
function throttle_retry_after(string $key): int
{
    return (int)throttle_edit(static function (array &$table) use ($key): int {
        return max(0, (int)($table[$key]['until'] ?? 0) - time());
    });
}

/**
 * Record one failure and return the wait it has earned.
 *
 * The wait grows with each failure past the allowance: the first few cost
 * nothing, and by the tenth the caller is waiting minutes. Somebody who has
 * mistyped their own password is barely inconvenienced; somebody working
 * through a list is stopped.
 */
function throttle_fail(string $key, int $allow = 5, int $step = 30): int
{
    return (int)throttle_edit(static function (array &$table) use ($key, $allow, $step): int {
        $now  = time();
        $hits = (int)($table[$key]['hits'] ?? 0) + 1;

        $over  = max(0, $hits - $allow);
        $block = $over === 0 ? 0 : (int)min(THROTTLE_MAX_BLOCK, $step * (2 ** ($over - 1)));

        $table[$key] = [
            'hits'  => $hits,
            'last'  => $now,
            'until' => $block > 0 ? $now + $block : 0,
        ];

        return $block;
    });
}

/** Forget a counter — called on a successful sign-in. */
function throttle_clear(string $key): void
{
    throttle_edit(static function (array &$table) use ($key): null {
        unset($table[$key]);
        return null;
    });
}

/**
 * A plain quota: this many of something in this window, whatever the outcome.
 *
 * Used where the cost is in the doing rather than in the guessing — sending a
 * reset code, submitting the contact form. Returns the seconds to wait, or 0 if
 * the caller may proceed; proceeding is counted.
 */
function throttle_quota(string $key, int $limit, int $window): int
{
    return (int)throttle_edit(static function (array &$table) use ($key, $limit, $window): int {
        $now   = time();
        $row   = $table[$key] ?? [];
        $start = (int)($row['start'] ?? 0);
        $hits  = (int)($row['hits'] ?? 0);

        if ($start < $now - $window) {
            $start = $now;
            $hits  = 0;
        }

        if ($hits >= $limit) {
            return max(1, $start + $window - $now);
        }

        $table[$key] = ['hits' => $hits + 1, 'start' => $start, 'last' => $now, 'until' => 0];

        return 0;
    });
}

/** "4 minutes", for telling somebody how long they are waiting. */
function throttle_wait_text(int $seconds): string
{
    if ($seconds <= 60) {
        return 'a minute';
    }

    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' minutes';
    }

    $hours = (int)ceil($minutes / 60);
    return $hours === 1 ? 'an hour' : $hours . ' hours';
}
