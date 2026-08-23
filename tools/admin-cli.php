<?php
/**
 * Tech4TIME — the admin, from the command line.
 *
 * NOT PART OF THE SITE. Like tools/host-probe.php, this is never deployed with
 * a normal upload. See docs/30-operations/secrets-recovery.md.
 *
 * WHY IT EXISTS
 * Every way back into the admin depends on something: the password on
 * remembering it, the authenticator on having the phone, the recovery codes on
 * having kept them, and the emailed reset on mail() working and on being able
 * to open the mailbox. Each of those can be true and the others false, and the
 * day they are all false is the day the website cannot be edited by anybody.
 *
 * This is the floor under all of them. It needs no password and no code,
 * because it needs something better: the ability to run a command on the
 * server. Anyone who has that already has the accounts file itself.
 *
 * HOW TO USE IT ON THE HOST
 *   1. Upload this one file to your HOME directory — /home/USER/, the level
 *      ABOVE public_html. Not into public_html: nothing here should ever be
 *      reachable over HTTP, and outside the document root it cannot be.
 *   2. ssh in, then:  php ~/admin-cli.php list
 *   3. Do what you came for, then DELETE IT.
 *
 * Locally it runs where it sits:  php tools/admin-cli.php list
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

/* ------------------------------------------------------------ finding the site */

/**
 * Where lib/ is.
 *
 * Three cases, in order: run from tools/ inside the repository; told where to
 * look; or uploaded to the home directory on a cPanel account, where the site
 * is in public_html beside it.
 */
function locate_lib(array $argv): string
{
    $tries = [
        dirname(__DIR__),                                  // tools/ in the repo
        $argv[1] ?? '',                                    // told explicitly
        (getenv('HOME') ?: '') . '/public_html',           // cPanel
        __DIR__ . '/public_html',                          // uploaded beside it
    ];

    foreach ($tries as $root) {
        if ($root !== '' && is_file($root . '/lib/auth.php')) {
            return rtrim($root, '/');
        }
    }

    fwrite(STDERR,
        "Could not find lib/auth.php.\n\n"
        . "Pass the site root as the first argument:\n"
        . "  php admin-cli.php ~/public_html list\n");
    exit(1);
}

$root = locate_lib($argv);

/* The private store is found the way the website finds it: beside the document
   root. On cPanel that is /home/USER/t4t-private. Set T4T_PRIVATE to override,
   exactly as the website would. */
if (!getenv('T4T_PRIVATE')) {
    $_SERVER['DOCUMENT_ROOT'] = $root;
}

require $root . '/lib/auth.php';
require $root . '/lib/reset.php';

/* ---------------------------------------------------------------- utilities */

function say(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function fail(string $line): never
{
    fwrite(STDERR, $line . "\n");
    exit(1);
}

/** Read a line without echoing it, where the terminal allows that. */
function ask_secret(string $prompt): string
{
    fwrite(STDOUT, $prompt);

    $stty = @shell_exec('stty -g 2>/dev/null');
    $hidden = is_string($stty) && trim($stty) !== '';

    if ($hidden) {
        @shell_exec('stty -echo 2>/dev/null');
    } else {
        fwrite(STDOUT, "\n  (this terminal will show what you type)\n  ");
    }

    $value = rtrim((string)fgets(STDIN), "\r\n");

    if ($hidden) {
        @shell_exec('stty ' . escapeshellarg(trim($stty)) . ' 2>/dev/null');
        fwrite(STDOUT, "\n");
    }

    return $value;
}

function need_account(array $args): array
{
    $who = $args[0] ?? '';

    if ($who === '') {
        $all = auth_accounts();
        if (count($all) === 1) {
            return auth_defaults($all[0]);
        }
        fail("Which account? Try:  admin-cli.php list");
    }

    $account = auth_find($who);

    if ($account === null) {
        fail("No account called \"{$who}\".");
    }

    return $account;
}

/* ----------------------------------------------------------------- commands */

function cmd_list(): void
{
    $accounts = auth_accounts();

    if ($accounts === []) {
        say('No accounts yet. Open /admin/setup.php in a browser to make one.');
        return;
    }

    say(sprintf('  %-16s %-28s %-8s %-7s %s',
        'USER', 'EMAIL', '2FA', 'CODES', 'LAST SIGN-IN'));

    $anyDead = false;

    foreach ($accounts as $row) {
        $a     = auth_defaults($row);
        $codes = auth_recovery_state($a);

        /* Not a count of what is stored. Recovery codes are hashed under a key
           derived from secret.key, so if that file was lost and remade, all ten
           are permanently unverifiable — and a count of the entries reports ten
           of them right up until somebody tries one. */
        if ($codes['dead'] > 0) {
            $anyDead = true;
            $shown = sprintf('%d DEAD', $codes['dead']);
        } elseif ($codes['unmarked'] > 0) {
            $shown = sprintf('%d ?', $codes['unmarked'] + $codes['live']);
        } else {
            $shown = (string)$codes['live'];
        }

        say(sprintf('  %-16s %-28s %-8s %-7s %s',
            $a['user'],
            $a['email'],
            $a['totp'] !== '' ? 'paired' : 'NONE',
            $shown,
            $a['last_login'] !== '' ? $a['last_login'] : 'never'
        ) . ($a['disabled'] ? '  [disabled]' : ''));
    }

    if ($anyDead) {
        say('');
        say('  Codes marked DEAD were made under a different secret.key and can');
        say('  never be verified again. The password cannot be either, for the');
        say('  same reason.');
        say('');
        say('  Restore the original ~/t4t-private/secret.key from backup if you');
        say('  still have it. If it is gone for good, set a new password and');
        say('  issue new codes, both of which are made under the key you have now:');
        say('');
        say('      php ~/admin-cli.php passwd');
        say('      php ~/admin-cli.php codes');
    }
}

function cmd_passwd(array $args): void
{
    $account = need_account($args);

    say("Setting a new password for \"{$account['user']}\".");

    $one = ask_secret('  New password: ');
    $two = ask_secret('  Again:        ');

    $problem = auth_password_problem($one);

    if ($problem !== '') {
        fail('  ' . $problem);
    }
    if (!hash_equals($one, $two)) {
        fail('  Those are not the same.');
    }

    $account['hash']             = auth_password_hash($one);
    $account['password_changed'] = gmdate('c');
    auth_invalidate_sessions($account);

    if (!auth_put($account)) {
        fail('  Could not write to the accounts file. Check its permissions.');
    }

    auth_log('password-set-from-cli', ['user' => $account['user']]);

    say('  Done. Every signed-in session has been ended.');

    if ($account['totp'] !== '') {
        say('  The authenticator app is unchanged — you will still be asked for a code.');
    }
}

function cmd_unlock(array $args): void
{
    /* Simply removing the file: every counter in it is a wait somebody is
       serving, and the only reason to run this is to end all of them. */
    $path = t4t_private_path('throttle');

    if (!is_file($path)) {
        say('  Nothing is locked out.');
        return;
    }

    if (!@unlink($path)) {
        fail('  Could not clear the counters at ' . $path);
    }

    auth_log('throttle-cleared-from-cli');
    say('  Cleared. Sign-in attempts start from zero again.');
}

function cmd_codes(array $args): void
{
    $account = need_account($args);

    [$plain, $hashes] = auth_recovery_make();
    $account['recovery'] = $hashes;

    if (!auth_put($account)) {
        fail('  Could not write to the accounts file.');
    }

    auth_log('recovery-codes-issued-from-cli', ['user' => $account['user']]);

    say("New recovery codes for \"{$account['user']}\". The old ones no longer work.");
    say('Each signs you in once, in place of the authenticator app.');
    say('');

    foreach ($plain as $code) {
        say('    ' . $code);
    }

    say('');
    say('This is the only time they are shown.');
}

function cmd_totp_clear(array $args): void
{
    $account = need_account($args);

    if ($account['totp'] === '') {
        say("  \"{$account['user']}\" has no authenticator app paired.");
        return;
    }

    $account['totp']      = '';
    $account['totp_last'] = 0;
    auth_invalidate_sessions($account);

    if (!auth_put($account)) {
        fail('  Could not write to the accounts file.');
    }

    auth_log('totp-cleared-from-cli', ['user' => $account['user']]);

    say("  Unpaired. \"{$account['user']}\" can now sign in with the password");
    say('  alone, and will be asked to pair a new app on the Account page.');
    say('  Do that immediately: until then the password is the only protection.');
}

function cmd_log(array $args): void
{
    $limit = (int)($args[0] ?? 25);

    foreach (array_reverse(auth_recent(max(1, $limit))) as $row) {
        say(sprintf('  %s  %-26s %-16s %s',
            $row['at'] ?? '',
            $row['event'] ?? '',
            $row['user'] ?? ($row['who'] ?? ''),
            $row['ip'] ?? ''));
    }
}

function cmd_where(): void
{
    say('  Site root       ' . $GLOBALS['root']);

    try {
        say('  Private store   ' . t4t_private_dir());
        say('  Accounts file   ' . t4t_private_path('admins')
            . (is_file(t4t_private_path('admins')) ? '' : '   (does not exist yet)'));
    } catch (RuntimeException $e) {
        say('  Private store   PROBLEM: ' . $e->getMessage());
    }

    say('  Password hash   ' . (auth_algo() === PASSWORD_BCRYPT ? 'bcrypt' : 'argon2id'));
    say('  Mail            ' . (mail_problem() === '' ? 'available' : mail_problem()));
}

/* --------------------------------------------------------------------- main */

$args = array_values(array_slice($argv, 1));

/* The site root may have been passed as the first argument; drop it if so. */
if (isset($args[0]) && is_file(rtrim($args[0], '/') . '/lib/auth.php')) {
    array_shift($args);
}

$command = array_shift($args) ?? 'help';

try {
    match ($command) {
        'list'       => cmd_list(),
        'passwd'     => cmd_passwd($args),
        'unlock'     => cmd_unlock($args),
        'codes'      => cmd_codes($args),
        'totp-clear' => cmd_totp_clear($args),
        'log'        => cmd_log($args),
        'where'      => cmd_where(),
        default      => say(
            "Tech4TIME admin, from the command line.\n"
            . "\n"
            . "  list                 who has an account, and what they can sign in with\n"
            . "  passwd [user]        set a new password; ends every signed-in session\n"
            . "  codes  [user]        issue ten new recovery codes and print them once\n"
            . "  totp-clear [user]    unpair the authenticator so a new phone can be paired\n"
            . "  unlock               clear the lockout after too many failed attempts\n"
            . "  log [n]              the last n entries from the audit log\n"
            . "  where                which files this is working on\n"
            . "\n"
            . "With one account, [user] can be left out.\n"
            . "\n"
            . "On the host, upload this to your HOME directory — above public_html —\n"
            . "run it, and delete it.\n"
        ),
    };
} catch (RuntimeException $e) {
    fail('  ' . $e->getMessage());
}
