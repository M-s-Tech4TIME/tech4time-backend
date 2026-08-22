<?php
/**
 * Tech4TIME — getting back in after forgetting the password.
 *
 * A one-time code, emailed to the address on the account, good for ten minutes.
 *
 * THIS IS THE EXPOSED PART OF THE ADMIN
 * Every other admin page can hide behind the sign-in. This one cannot: it has
 * to work precisely when nobody can sign in, so it is reachable by anyone who
 * finds the URL. Three things follow from that, and all three are the reason
 * this is a file of its own rather than a few lines in a page.
 *
 *   1. IT NEVER SAYS WHETHER AN ACCOUNT EXISTS. Asking for a code gives the
 *      same answer for a real username and an invented one. Otherwise this page
 *      becomes a way to enumerate the accounts worth attacking.
 *
 *   2. THE CODE ALONE IS NOT ENOUGH. After the emailed code is accepted, the
 *      authenticator app — or a recovery code — is still required before a new
 *      password is taken. A mailbox is a thing that gets breached, and if six
 *      digits sent to it were sufficient, the mailbox would BE the admin
 *      password. The second factor is what stops that.
 *
 *   3. SENDING IS RATIONED. Per account, per address, and overall. The overall
 *      cap is not about this site: cPanel enforces an hourly limit on outbound
 *      mail, and somebody hammering this endpoint could exhaust it, which would
 *      stop the genuine reset from being delivered at the moment it was needed.
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

const RESET_TTL      = 600;   // the code is good for ten minutes
const RESET_ATTEMPTS = 5;     // guesses at it before the request is torn up
const RESET_COOKIE   = 't4treset';

/* Per hour. The global figure is deliberately well under the host's own
   outbound allowance, so this can never be what exhausts it. */
const RESET_PER_ACCOUNT = 3;
const RESET_PER_IP      = 5;
const RESET_GLOBAL      = 20;

/* ------------------------------------------------------------ the records */

function reset_edit(callable $change): mixed
{
    $path = t4t_private_path('resets');

    $result = store_edit($path, static function (array &$data) use ($change): mixed {
        $data['requests'] = is_array($data['requests'] ?? null) ? $data['requests'] : [];
        reset_prune($data['requests']);
        return $change($data['requests']);
    });

    @chmod($path, 0600);

    return $result;
}

function reset_prune(array &$requests): void
{
    $now = time();

    foreach ($requests as $id => $row) {
        if (!is_array($row) || (int)($row['expires'] ?? 0) < $now) {
            unset($requests[$id]);
        }
    }
}

function reset_code_hash(string $code): string
{
    return hash_hmac('sha256', $code, t4t_key('reset-code'));
}

/* --------------------------------------------------------------- asking */

/**
 * Ask for a code.
 *
 * Returns 0 when the request was handled — which is what the caller reports
 * whether or not the account existed — or the seconds to wait when the rate
 * limit has been reached. A rate-limit message is safe to show: it says
 * something about how much this endpoint has been used, not about who has an
 * account here.
 *
 * A note on what this does not defend against: sending mail takes longer than
 * not sending it, so a determined observer could time the difference. Closing
 * that would mean sleeping on the empty path, which turns a probe into a way to
 * tie up PHP workers. The uniform answer is the defence that matters.
 */
function reset_begin(string $who): int
{
    $wait = throttle_quota(throttle_key('reset-ip', throttle_ip()), RESET_PER_IP, 3600);
    if ($wait > 0) {
        return $wait;
    }

    $wait = throttle_quota(throttle_key('reset-all', 'global'), RESET_GLOBAL, 3600);
    if ($wait > 0) {
        return $wait;
    }

    $account = auth_find($who);

    if ($account === null || $account['disabled'] || $account['email'] === '') {
        auth_log('reset-request-unknown', ['who' => substr(trim($who), 0, 60)]);
        return 0;
    }

    /* Counted only once the account is known to exist, so that hitting the
       per-account limit cannot itself be used to discover that it does. */
    if (throttle_quota(throttle_key('reset-user', $account['user']), RESET_PER_ACCOUNT, 3600) > 0) {
        auth_log('reset-request-throttled', ['user' => $account['user']]);
        return 0;
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $id   = bin2hex(random_bytes(16));

    reset_edit(static function (array &$requests) use ($id, $account, $code): null {
        /* One live request per account: asking again replaces the last code
           rather than leaving two of them valid at once. */
        foreach ($requests as $key => $row) {
            if (($row['user'] ?? '') === $account['user']) {
                unset($requests[$key]);
            }
        }

        $requests[$id] = [
            'user'    => $account['user'],
            'hash'    => reset_code_hash($code),
            'at'      => time(),
            'expires' => time() + RESET_TTL,
            'tries'   => 0,
        ];

        return null;
    });

    setcookie(RESET_COOKIE, $id, [
        'expires'  => time() + RESET_TTL,
        'path'     => ADMIN_BASE,
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $sent = mail_send(
        $account['email'],
        'Your Tech4TIME admin reset code',
        reset_message($account, $code)
    );

    auth_log($sent ? 'reset-code-sent' : 'reset-code-failed', ['user' => $account['user']]);

    return 0;
}

function reset_message(array $account, string $code): string
{
    $minutes = (int)(RESET_TTL / 60);

    return "Somebody asked to reset the password for the Tech4TIME admin.\n\n"
        . "    Account:  {$account['user']}\n"
        . "    Code:     {$code}\n\n"
        . "Type it into the page you asked from, in the same browser. It stops\n"
        . "working in {$minutes} minutes, or as soon as it has been used once.\n\n"
        . "You will also be asked for the six digits from your authenticator app.\n"
        . "The code above on its own cannot change your password.\n\n"
        . str_repeat('-', 60) . "\n"
        . 'Requested: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
        . 'From:      ' . throttle_ip() . "\n\n"
        . "If this was not you, nothing has happened and your password is\n"
        . "unchanged. You can ignore this message. If it keeps arriving, whoever\n"
        . "is asking knows the account name, and the password is worth changing.\n";
}

/* -------------------------------------------------------------- checking */

/**
 * Check a typed code and return the account it belongs to.
 *
 * The request is torn up whether or not the code was right — used once on
 * success, and out of guesses after RESET_ATTEMPTS. Nothing here says which of
 * the several possible reasons a null covers.
 */
function reset_verify(string $code): ?array
{
    $id = (string)($_COOKIE[RESET_COOKIE] ?? '');

    if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
        return null;
    }

    $code = preg_replace('/\D/', '', $code) ?? '';

    $user = reset_edit(static function (array &$requests) use ($id, $code): ?string {
        $row = $requests[$id] ?? null;

        if (!is_array($row)) {
            return null;
        }

        if (time() > (int)$row['expires'] || (int)$row['tries'] >= RESET_ATTEMPTS) {
            unset($requests[$id]);
            return null;
        }

        $requests[$id]['tries'] = (int)$row['tries'] + 1;

        if (strlen($code) !== 6 || !hash_equals((string)$row['hash'], reset_code_hash($code))) {
            return null;
        }

        unset($requests[$id]);          // single use

        return (string)$row['user'];
    });

    if (!is_string($user)) {
        return null;
    }

    return auth_find($user);
}

/** How many guesses are left, for telling somebody before they run out. */
function reset_tries_left(): int
{
    $id = (string)($_COOKIE[RESET_COOKIE] ?? '');

    if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
        return 0;
    }

    return (int)reset_edit(static function (array &$requests) use ($id): int {
        $row = $requests[$id] ?? null;

        return is_array($row) ? max(0, RESET_ATTEMPTS - (int)($row['tries'] ?? 0)) : 0;
    });
}

function reset_forget(): void
{
    setcookie(RESET_COOKIE, '', [
        'expires'  => time() - 42000,
        'path'     => ADMIN_BASE,
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* -------------------------------------------------------------- finishing */

/**
 * Set the new password.
 *
 * Only reached once the emailed code AND the second factor have both been
 * accepted. Signs out every session belonging to the account, including any the
 * person doing this does not know about — which is the point of a reset.
 */
function reset_finish(array $account, string $password): bool
{
    $account['hash']             = auth_password_hash($password);
    $account['password_changed'] = gmdate('c');
    auth_invalidate_sessions($account);

    if (!auth_put($account)) {
        return false;
    }

    auth_log('password-reset', ['user' => $account['user']]);

    mail_send(
        $account['email'],
        'Your Tech4TIME admin password was changed',
        "The password for the Tech4TIME admin account \"{$account['user']}\" has just\n"
        . "been changed, and every signed-in session has been ended.\n\n"
        . 'When:  ' . gmdate('Y-m-d H:i:s') . " UTC\n"
        . 'From:  ' . throttle_ip() . "\n\n"
        . "If this was not you, whoever did it also holds your authenticator app\n"
        . "or one of your recovery codes. Sign in, change the password again, and\n"
        . "generate a new authenticator secret from the Account page.\n"
    );

    return true;
}
