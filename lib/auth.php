<?php
/**
 * Tech4TIME — who may edit the website.
 *
 * Accounts, passwords, the second factor, and the session that remembers a
 * successful sign-in. No database: the accounts file is JSON in the private
 * store, the same way the site's content is JSON in content/.
 *
 * WHAT REPLACED WHAT
 * The admin used to be protected by cPanel Directory Privacy, and PHP never
 * checked a credential at all — admin_require_auth() only looked at whether
 * Apache had filled in REMOTE_USER. That was a real lock, but it was Apache's
 * lock: no logout, no lockout, no record of who signed in, no second factor,
 * and a browser dialogue instead of a page. This is the application's own.
 *
 * HOW A PASSWORD IS STORED
 *
 *     pre    = hash_hmac('sha256', $password, $pepper)   // $pepper from secret.key
 *     stored = password_hash($pre, argon2id)             // fresh random salt inside
 *
 * password_hash() generates a NEW RANDOM SALT for every password and writes it
 * into the hash string it returns, which is why there is no separate salt to
 * keep — the string on disk already contains the algorithm, its cost, the salt
 * and the digest. Verifying reads the salt back out of that same string, so the
 * password typed at the login page is hashed exactly the way the stored one
 * was, and the two digests are compared in constant time. The plain password is
 * never written anywhere and never leaves the request that carried it.
 *
 * The pepper is the part a stolen file does not include. Salting defeats a
 * rainbow table and makes each password cost its own attack; peppering means
 * that attack cannot start at all without secret.key, which lives in a
 * different file that a leak of admins.json does not carry with it.
 *
 * password_needs_rehash() upgrades the stored form on the next successful
 * sign-in whenever these settings change, so raising the cost later costs
 * nobody a password reset.
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 */

declare(strict_types=1);

require_once __DIR__ . '/private.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/throttle.php';
require_once __DIR__ . '/totp.php';

/** Where the admin lives. One constant, so the subdomain move is one edit. */
const ADMIN_BASE = '/admin/';

const AUTH_COOKIE   = 't4tadm';
const AUTH_IDLE     = 3600;    // seconds of inactivity before a session lapses
const AUTH_ABSOLUTE = 43200;   // 12 hours, however active
const AUTH_ALLOW    = 5;       // failures before a wait is imposed
const AUTH_RECOVERY = 10;      // recovery codes issued at enrolment

/**
 * Argon2id parameters.
 *
 * PHP's own defaults ask for 64 MB per hash. That is a fine number on a server
 * you own and an unkind one on shared hosting, where the whole PHP process may
 * be capped not far above it. 32 MB with three passes is still well above the
 * 19 MB OWASP names as a floor, and it leaves room for the request to do its
 * actual work.
 */
const AUTH_ARGON = ['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1];
const AUTH_BCRYPT = ['cost' => 12];

/* --------------------------------------------------------------- accounts */

function auth_accounts(): array
{
    $data = store_read(t4t_private_path('admins'));
    $list = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];

    return array_values(array_filter($list, 'is_array'));
}

function auth_save_accounts(array $accounts): bool
{
    $ok = store_write(t4t_private_path('admins'), [
        'updated'  => gmdate('c'),
        'accounts' => array_values($accounts),
    ]);

    if ($ok) {
        @chmod(t4t_private_path('admins'), 0600);
        /* store_write() keeps one .bak beside the file. For site copy that is a
           safety net; for this file it is a second copy of a password hash, so
           it is locked down to match rather than left at the umask. */
        @chmod(t4t_private_path('admins') . '.bak', 0600);
    }

    return $ok;
}

/** Whether anybody can sign in at all. */
function auth_has_accounts(): bool
{
    return auth_accounts() !== [];
}

/** Fill in whatever an older or hand-edited record leaves out. */
function auth_defaults(array $account): array
{
    return $account + [
        'user'             => '',
        'name'             => '',
        'email'            => '',
        'hash'             => '',
        'totp'             => '',
        'totp_last'        => 0,
        'recovery'         => [],
        'token_version'    => 1,
        'disabled'         => false,
        'created'          => '',
        'password_changed' => '',
        'last_login'       => '',
    ];
}

/** By username or by email address, either of which may be typed at the login. */
function auth_find(string $who): ?array
{
    $who = strtolower(trim($who));

    if ($who === '') {
        return null;
    }

    foreach (auth_accounts() as $account) {
        $account = auth_defaults($account);

        if (strtolower($account['user']) === $who || strtolower($account['email']) === $who) {
            return $account;
        }
    }

    return null;
}

/** Add or replace one account, matched on its username. */
function auth_put(array $account): bool
{
    $account  = auth_defaults($account);
    $accounts = auth_accounts();
    $found    = false;

    foreach ($accounts as $i => $existing) {
        if (strtolower((string)($existing['user'] ?? '')) === strtolower($account['user'])) {
            $accounts[$i] = $account;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $accounts[] = $account;
    }

    return auth_save_accounts($accounts);
}

/* -------------------------------------------------------------- passwords */

/** argon2id where the host has it; bcrypt at cost 12 where it does not. */
function auth_algo(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

function auth_algo_options(): array
{
    return auth_algo() === PASSWORD_BCRYPT ? AUTH_BCRYPT : AUTH_ARGON;
}

/**
 * The secret-keyed pre-hash.
 *
 * Hex rather than raw bytes on purpose: bcrypt stops at the first NUL byte and
 * truncates at 72, and a hex digest is 64 printable characters, so it can never
 * hit either. argon2id has neither limit, but the same input should not change
 * shape depending on which algorithm the host turned out to have.
 */
function auth_pepper(string $password): string
{
    return hash_hmac('sha256', $password, t4t_key('password-pepper'));
}

function auth_password_hash(string $password): string
{
    return password_hash(auth_pepper($password), auth_algo(), auth_algo_options());
}

function auth_password_verify(string $password, string $hash): bool
{
    if ($hash === '') {
        return false;
    }

    return password_verify(auth_pepper($password), $hash);
}

function auth_password_needs_rehash(string $hash): bool
{
    return password_needs_rehash($hash, auth_algo(), auth_algo_options());
}

/**
 * Burn the same time on an account that does not exist as on one that does.
 *
 * Without this, "no such user" returns in a fraction of the time "wrong
 * password" takes, and the difference is a way to find out which usernames are
 * real without ever guessing a password.
 */
function auth_password_dummy(string $password): void
{
    static $hash = null;

    $hash ??= auth_password_hash('a password nobody has ' . bin2hex(random_bytes(8)));

    password_verify(auth_pepper($password), $hash);
}

/** What we insist on. Length does far more work here than character classes. */
function auth_password_problem(string $password): string
{
    $length = strlen($password);

    if ($length < 12) {
        return 'Use at least 12 characters. Length is what makes a password hard to guess.';
    }

    if ($length > 200) {
        return 'That is longer than 200 characters.';
    }

    if (trim($password) === '') {
        return 'That is only spaces.';
    }

    foreach (['password', '12345678', 'tech4time', 'qwerty', 'admin'] as $obvious) {
        if (stripos($password, $obvious) !== false) {
            return 'That contains "' . $obvious . '", which is among the first things guessed.';
        }
    }

    return '';
}

/* -------------------------------------------------------- recovery codes */

/** Ten codes, returned in plain once and stored only as hashes. */
function auth_recovery_make(int $count = AUTH_RECOVERY): array
{
    $plain = [];
    $hash  = [];

    for ($i = 0; $i < $count; $i++) {
        $code    = strtoupper(bin2hex(random_bytes(5)));
        $code    = substr($code, 0, 5) . '-' . substr($code, 5);
        $plain[] = $code;
        $hash[]  = auth_recovery_hash($code);
    }

    return [$plain, $hash];
}

/** A recovery code reduced to what is compared. */
function auth_recovery_clean(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

/**
 * A stored recovery code: the key it was made under, then the digest.
 *
 * The fingerprint is carried on the value itself rather than recorded once per
 * account, because there are seven places that write a secret and a stamp
 * applied at each of them is a stamp somebody will forget at the eighth. Here
 * it cannot be forgotten — anything that produces a stored code produces the
 * marker with it, and anything that reads one can see which key it belongs to.
 *
 * This is what makes a dead code recognisable. Recovery codes are hashed under
 * a key derived from secret.key, so losing that file makes all ten permanently
 * unverifiable — while the account still lists ten of them and every count of
 * them still said ten.
 */
function auth_recovery_hash(string $code): string
{
    return t4t_key_fingerprint() . ':'
         . hash_hmac('sha256', auth_recovery_clean($code), t4t_key('recovery'));
}

/** Whether one stored value is this code, under a key we still have. */
function auth_recovery_matches(string $stored, string $code): bool
{
    $digest = hash_hmac('sha256', auth_recovery_clean($code), t4t_key('recovery'));

    /* Written before codes carried their key. It can only have been made under
       the key we hold, because any other one would not produce this digest
       either — so comparing the digest alone is exactly as safe as it was. */
    if (!str_contains($stored, ':')) {
        return hash_equals($stored, $digest);
    }

    return hash_equals($stored, t4t_key_fingerprint() . ':' . $digest);
}

/**
 * How many of an account's codes stand a chance, and how many cannot.
 *
 * Counting the entries says ten whatever has happened to the key. This says
 * what a person actually wants to know.
 *
 * @return array{live:int, dead:int, unmarked:int}
 */
function auth_recovery_state(array $account): array
{
    $mark  = t4t_key_fingerprint() . ':';
    $state = ['live' => 0, 'dead' => 0, 'unmarked' => 0];

    foreach ((array)($account['recovery'] ?? []) as $stored) {
        if (!is_string($stored) || $stored === '') {
            continue;
        }

        if (!str_contains($stored, ':')) {
            /* Predates the marker. Nothing here can tell whether it still
               verifies, and guessing either way would be a claim we cannot
               support — so it is reported as its own thing. */
            $state['unmarked']++;
        } elseif (str_starts_with($stored, $mark)) {
            $state['live']++;
        } else {
            $state['dead']++;
        }
    }

    return $state;
}

/**
 * Spend one recovery code. Removes it from the account on success, because a
 * code that works twice is a password with extra steps.
 */
function auth_recovery_use(array &$account, string $code): bool
{
    $left = [];
    $used = false;

    foreach ((array)($account['recovery'] ?? []) as $stored) {
        if (!$used && is_string($stored) && auth_recovery_matches($stored, $code)) {
            $used = true;
            continue;
        }
        $left[] = $stored;
    }

    if ($used) {
        $account['recovery'] = $left;
    }

    return $used;
}

/* --------------------------------------------------------------- sessions */

function auth_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));

    return ($https !== '' && $https !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Development on the built-in server, where there is no certificate to have. */
function auth_is_local(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = explode(':', $host)[0];

    return in_array($host, ['localhost', '127.0.0.1', '::1', ''], true);
}

/**
 * Whether the request came from this machine.
 *
 * REMOTE_ADDR, not the Host header. auth_is_local() reads a header the client
 * chooses, which is fine for deciding whether to insist on HTTPS in
 * development and would be a hole anywhere a security decision rests on it: a
 * stranger can put "Host: localhost" on a request to the live server. The peer
 * address is the one thing in a request the sender cannot make up.
 */
function auth_is_loopback(): bool
{
    return in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
}

/**
 * What the browser is, roughly, so a stolen cookie replayed from somewhere else
 * is more likely to be noticed. Not the IP address: a phone changes that
 * walking down a street, and a session that drops on every cell handover
 * teaches people to expect being logged out for no reason.
 */
function auth_fingerprint(): string
{
    return hash_hmac(
        'sha256',
        (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        t4t_key('session-fingerprint')
    );
}

/** Start the session with cookie settings worth having. */
function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessions = t4t_private_dir() . '/' . T4T_PRIVATE_FILES['sessions'];
    if (!is_dir($sessions)) {
        @mkdir($sessions, 0700, true);
    }

    if (is_dir($sessions) && is_writable($sessions)) {
        session_save_path($sessions);
    }

    /* strict mode makes PHP refuse a session id it did not issue, which is what
       closes session fixation: an attacker cannot plant an id in the browser and
       wait for it to be signed in. */
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)AUTH_ABSOLUTE);

    session_name(AUTH_COOKIE);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    auth_sweep_sessions($sessions);
}

/**
 * Delete session files nothing can still be using.
 *
 * PHP's own collector is switched off by default on Debian and its derivatives,
 * which normally does not matter because a cron job cleans the shared directory
 * instead. This directory is ours, so nothing else will.
 */
function auth_sweep_sessions(string $dir): void
{
    if (!is_dir($dir) || random_int(1, 100) !== 1) {
        return;
    }

    $cutoff = time() - AUTH_ABSOLUTE;

    foreach ((array)glob($dir . '/sess_*') as $file) {
        if (is_string($file) && @filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/**
 * The signed-in account, or null.
 *
 * Every reason a session might no longer be good is checked here rather than
 * spread across the pages: it has run its course, it has been idle, the browser
 * has changed, the account has gone or been disabled, or the password has been
 * changed since — which bumps token_version and so signs out every other device
 * at once.
 */
function auth_session_user(): ?array
{
    $auth = $_SESSION['auth'] ?? null;

    if (!is_array($auth) || ($auth['user'] ?? '') === '') {
        return null;
    }

    $now = time();

    if ($now - (int)($auth['at'] ?? 0) > AUTH_ABSOLUTE) {
        return auth_end_session('expired');
    }

    if ($now - (int)($auth['seen'] ?? 0) > AUTH_IDLE) {
        return auth_end_session('idle');
    }

    if (!hash_equals((string)($auth['fp'] ?? ''), auth_fingerprint())) {
        return auth_end_session('fingerprint');
    }

    $account = auth_find((string)$auth['user']);

    if ($account === null || $account['disabled']) {
        return auth_end_session('gone');
    }

    if ((int)$account['token_version'] !== (int)($auth['ver'] ?? 0)) {
        return auth_end_session('superseded');
    }

    $_SESSION['auth']['seen'] = $now;

    return $account;
}

/** Drop the session's authority, keeping the session itself for the CSRF token. */
function auth_end_session(string $why): null
{
    unset($_SESSION['auth']);
    $_SESSION['ended'] = $why;

    return null;
}

/** Record a sign-in. Called only once a password AND a second factor are proven. */
function auth_login(array $account): void
{
    /* A new id at the moment authority is granted, so nothing that knew the old
       one is holding a signed-in session. */
    session_regenerate_id(true);

    $_SESSION['auth'] = [
        'user' => $account['user'],
        'ver'  => (int)$account['token_version'],
        'at'   => time(),
        'seen' => time(),
        'fp'   => auth_fingerprint(),
    ];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    $account['last_login'] = gmdate('c');
    auth_put($account);

    auth_log('login', ['user' => $account['user']]);
}

function auth_logout(): void
{
    $user = (string)($_SESSION['auth']['user'] ?? '');

    if ($user !== '') {
        auth_log('logout', ['user' => $user]);
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Sign out every session belonging to an account, including this one's peers.
 *
 * Bumping the version is what does it: each session recorded the version it saw
 * at sign-in, and auth_session_user() drops any that no longer matches.
 */
function auth_invalidate_sessions(array &$account): void
{
    $account['token_version'] = (int)$account['token_version'] + 1;
}

/* ------------------------------------------------------------------- CSRF */

function auth_csrf(): string
{
    return (string)($_SESSION['csrf'] ?? '');
}

/**
 * Proving who you are is not proving you meant to click this. Without a token,
 * a page on another site could post here using the browser's live session.
 */
function auth_check_csrf(): void
{
    if (!hash_equals(auth_csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expired. Go back, reload the page and try again.');
    }
}

/* ------------------------------------------------------------------- log */

/**
 * Append one line to the audit log.
 *
 * NEVER pass a password, a reset code or a TOTP secret in $context. What
 * belongs here is who, what and whether it worked — enough to see an attack in
 * progress, and nothing that would help one.
 */
function auth_log(string $event, array $context = []): void
{
    try {
        $path = t4t_private_path('audit');
    } catch (RuntimeException) {
        return;
    }

    if (@filesize($path) > 1048576) {
        @rename($path, $path . '.1');
    }

    $line = json_encode([
        'at'    => gmdate('c'),
        'event' => $event,
        'ip'    => throttle_ip(),
        'ua'    => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
    ] + $context, JSON_UNESCAPED_SLASHES);

    if ($line !== false) {
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        @chmod($path, 0600);
    }
}

/** The most recent entries, newest first, for the account page to show. */
function auth_recent(int $limit = 20, string $user = ''): array
{
    try {
        $raw = @file_get_contents(t4t_private_path('audit'));
    } catch (RuntimeException) {
        return [];
    }

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $out = [];

    foreach (array_reverse(explode("\n", trim($raw))) as $line) {
        $row = json_decode($line, true);

        if (!is_array($row)) {
            continue;
        }

        if ($user !== '' && strtolower((string)($row['user'] ?? '')) !== strtolower($user)) {
            continue;
        }

        $out[] = $row;

        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/* ------------------------------------------------------------ first run */

/** Random bytes behind a setup token — six, shown as twelve hex characters. */
const AUTH_SETUP_BYTES = 6;

/** What those bytes are once written: the length any stored token must have. */
const AUTH_SETUP_CHARS = AUTH_SETUP_BYTES * 2;

/**
 * The token that lets somebody create the first account.
 *
 * Between deploying this and creating an account there is a window in which
 * setup.php would take anyone who found it — and whoever creates the first
 * account owns the website. Waiting to be first is not a defence.
 *
 * So the page will not proceed until the operator repeats a value that only
 * exists on the server's own disk, in a directory no URL maps to. Reading it
 * takes SSH, cPanel's File Manager or the Terminal — which is to say, it takes
 * the access somebody setting up this site has and a stranger does not.
 *
 * Created by the page that asks for it, so the operator can read the file at
 * the moment the procedure tells them to, and so the window is closed by the
 * code rather than by a step somebody has to remember. Deleted the moment an
 * account exists.
 *
 * Recognised again by its length, derived from AUTH_SETUP_BYTES rather than
 * repeated: the two disagreeing is not a visible failure, it is a setup page
 * that quietly can never be completed.
 */
function auth_setup_token(): string
{
    $path = t4t_private_path('setup');

    /* Recognising our own file has to be measured against what we actually
       write. A guard with its own idea of the length silently rejects every
       token it ever stored, mints a fresh one on each call, and compares the
       operator against a value they were never shown — setup that can never
       succeed, and no error anywhere to say why. */
    $raw = @file_get_contents($path);
    if (is_string($raw) && strlen(auth_setup_token_clean($raw)) === AUTH_SETUP_CHARS) {
        return trim($raw);
    }

    /* Setup is over; nothing may mint a new one. The guard lives here, at the
       only place that can create the file, rather than at the call site that
       happened to be wrong — admin/setup.php called this on its recovery-codes
       render, seconds after auth_setup_done() had deleted the token, and put it
       straight back. A condition on one caller fixes that caller. A condition
       here means the file cannot come back whoever asks. */
    if (auth_has_accounts()) {
        return '';
    }

    $hex   = strtoupper(bin2hex(random_bytes(AUTH_SETUP_BYTES)));
    $token = substr($hex, 0, 4) . '-' . substr($hex, 4, 4) . '-' . substr($hex, 8);

    @file_put_contents($path, $token . "\n", LOCK_EX);
    @chmod($path, 0600);

    return $token;
}

/**
 * A setup token reduced to what is compared: its characters, not the dashes
 * that make it readable or the newline the file ends with.
 *
 * Shared by the writer and the checker so that "is this a usable token?" has
 * one answer, given in one place, rather than two that can drift apart.
 */
function auth_setup_token_clean(string $value): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
}

function auth_setup_token_check(string $given): bool
{
    /* Refused outright once an account exists, before any comparison happens.
       Without this, auth_setup_token() answering '' for a store that is past
       setup would meet an empty $given in hash_equals() and agree with it —
       turning "no token" into a valid token. The caller is not supposed to
       reach here in that state; that is exactly why it must not depend on it. */
    if (auth_has_accounts()) {
        return false;
    }

    return hash_equals(
        auth_setup_token_clean(auth_setup_token()),
        auth_setup_token_clean($given)
    );
}

/** Setup is over. Remove the token rather than leave a live one lying about. */
function auth_setup_done(): void
{
    @unlink(t4t_private_path('setup'));
}

/* --------------------------------------------------------- configuration */

/**
 * Whatever would stop this from being safe to run, in words a person can act
 * on. Empty when all is well.
 *
 * The admin refuses to load while this returns anything — the same principle
 * the Directory Privacy check followed, pointed at what now matters. An editor
 * that quietly works without a password is worse than one that visibly does not
 * work at all.
 */
function auth_problem(): string
{
    $problem = t4t_private_problem();

    if ($problem !== '') {
        return $problem;
    }

    /* A damaged account file must never be allowed to look like a fresh
       install. auth_has_accounts() would say "no accounts" for both, the admin
       would offer setup, and the first save would copy the damaged file over
       its own .bak — destroying what may be the last intact copy in the act of
       following the screen's own suggestion. Nothing here is recoverable by
       carrying on, so it stops. */
    $accounts = t4t_private_path('admins');
    $state    = store_state($accounts);

    if ($state === 'corrupt' || $state === 'unreadable') {
        return 'The account file is present but cannot be read: ' . $accounts
             . ' — it is ' . $state . '. Refusing to continue, because from here '
             . 'a damaged file is indistinguishable from a site nobody has set '
             . 'up yet, and going through setup would copy it over '
             . basename($accounts) . '.bak, which may be the only intact copy '
             . 'left. Restore that .bak over the file before reloading this page.';
    }

    if (!auth_is_https() && !auth_is_local()) {
        return 'This page is not being served over HTTPS. A password and a session '
             . 'cookie sent over plain http can be read in transit.';
    }

    return '';
}

/* --------------------------------------------------------------- signing in */

/**
 * Step one: the password.
 *
 * Returns the account on success, or null. Deliberately says nothing about
 * WHICH half was wrong — an unknown username and a wrong password are the same
 * answer, and take the same time, or the login page becomes a way to find out
 * which accounts exist.
 *
 * The second factor is checked separately, by the login page, once this has
 * passed. It is not merged in here because the two are asked for on different
 * screens and counted against different limits.
 */
function auth_attempt(string $who, string $password): ?array
{
    $account = auth_find($who);

    if ($account === null || $account['disabled'] || $account['hash'] === '') {
        auth_password_dummy($password);
        return null;
    }

    if (!auth_password_verify($password, $account['hash'])) {
        return null;
    }

    /* Right password, and the stored form is out of date — rehash it now, while
       the only copy of the plain password that will ever exist is in hand. */
    if (auth_password_needs_rehash($account['hash'])) {
        $account['hash'] = auth_password_hash($password);
        auth_put($account);
    }

    return $account;
}

/**
 * Step two: the six digits, or a recovery code in their place.
 *
 * Saves the account when something is spent — the TOTP counter, so the same six
 * digits cannot be used twice inside the thirty seconds they are valid for, or
 * the recovery code, so it cannot be used ever again.
 *
 * $account IS TAKEN BY REFERENCE, AND THAT IS LOAD-BEARING.
 * It used to be by value, and the bug that produced is worth recording: this
 * function spent the code on its own copy and wrote that copy out, and then the
 * caller — auth_login(), a line later — wrote ITS copy over the top to stamp
 * last_login. The caller's copy still had the spent recovery code in it and the
 * old TOTP counter, so both were quietly restored. Recovery codes worked for
 * ever, and a captured six digits could be replayed. Nothing failed; the file
 * simply went back to how it had been.
 */
function auth_second_factor(array &$account, string $code): bool
{
    if ($account['totp'] === '') {
        return true;      // not enrolled yet; the account page will insist
    }

    $counter = totp_verify($account['totp'], $code, (int)$account['totp_last']);

    if ($counter !== null) {
        $account['totp_last'] = $counter;
        auth_put($account);
        return true;
    }

    if (auth_recovery_use($account, $code)) {
        auth_put($account);
        auth_log('recovery-code-used', [
            'user' => $account['user'],
            'left' => count($account['recovery']),
        ]);
        return true;
    }

    return false;
}
