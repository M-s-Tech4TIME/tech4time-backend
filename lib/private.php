<?php
/**
 * Tech4TIME — the private store.
 *
 * Where the secrets live, and the keys derived from them. Nothing here is ever
 * served over HTTP, ever committed to git, or ever written by a deploy.
 *
 * WHY NOT content/
 * content/ is kept out of the web by .htaccess: RewriteRule ^content/ - [F,L]
 * and a FilesMatch on .json. That is the right protection for site copy — if it
 * ever failed, a stranger would read the office addresses the contact page
 * already shows them.
 *
 * A password hash is not site copy. If that same protection failed — a host
 * that ignores .htaccess, mod_rewrite switched off, an .htaccess replaced by a
 * careless upload — the file would be served as plain text and an offline
 * attack on the hash could begin with nobody knowing it had started. A
 * directory rule is a policy the server chooses to apply. A file outside the
 * document root cannot be requested at all, because no URL maps to it.
 *
 * So the private store sits BESIDE the document root, never inside it, and
 * t4t_private_dir() refuses rather than accept a location it cannot vouch for.
 *
 * WHERE IT IS
 *   host   $T4T_PRIVATE, or /home/USER/t4t-private beside /home/USER/public_html
 *   local  $T4T_PRIVATE, set by tools/serve.py and by the test harnesses
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 */

declare(strict_types=1);

/** The directory name looked for beside the document root when nothing says otherwise. */
const T4T_PRIVATE_NAME = 't4t-private';

/**
 * Everything the store holds. Names are here rather than spelled out at each
 * call site so that one list describes the whole of what we keep.
 */
const T4T_PRIVATE_FILES = [
    'key'      => 'secret.key',    // 32 random bytes; every other key derives from it
    'admins'   => 'admins.json',   // accounts: hash, TOTP secret, recovery codes
    'throttle' => 'throttle.json', // failed-attempt counters
    'resets'   => 'resets.json',   // pending password-reset codes
    'audit'    => 'audit.log',     // append-only JSONL
    'sessions' => 'sessions',      // session.save_path (a directory)
    'setup'    => 'setup-token.txt', // proves server access during first-run setup
];

/* ------------------------------------------------------------------ paths */

/**
 * The document root, as the thing the private store must stay outside of.
 *
 * Empty under the CLI — the tools run from the repository, whose root stands in
 * for the document root there, which is exactly the comparison we want when a
 * test or a probe is deciding whether a path would be reachable in production.
 */
function t4t_document_root(): string
{
    $root = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($root === '') {
        $root = dirname(__DIR__);
    }

    return rtrim(realpath($root) ?: $root, '/');
}

/**
 * The private directory, created on first use.
 *
 * Throws rather than returns a fallback. A caller that cannot find its secrets
 * must stop; the one thing it must never do is carry on with defaults, because
 * the defaults of an authentication system are "no accounts exist", and a login
 * page that finds no accounts is a login page anyone can walk past.
 */
function t4t_private_dir(): string
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $dir = trim((string)(getenv('T4T_PRIVATE') ?: ($_SERVER['T4T_PRIVATE'] ?? '')));

    if ($dir === '') {
        /* The cPanel shape: the document root is /home/USER/public_html, so the
           store belongs at /home/USER/t4t-private — one level up, out of reach. */
        $dir = dirname(t4t_document_root()) . '/' . T4T_PRIVATE_NAME;
    }

    $dir = rtrim($dir, '/');

    /* Refuse before creating. There is no reason to make a directory we are
       about to reject, and a safety check that leaves a new folder inside the
       web root on its way out is doing the opposite of its job. */
    t4t_assert_outside_document_root(t4t_absolute($dir));

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException(
            'The private directory does not exist and could not be created: ' . $dir
        );
    }

    $real = realpath($dir);
    if ($real === false) {
        throw new RuntimeException('The private directory could not be resolved: ' . $dir);
    }

    /* And again once it exists, because realpath() follows symlinks and the
       first check could only look at the name it was given. */
    t4t_assert_outside_document_root($real);

    /* 0700 on every run, not only at creation: an unpacked archive or a panel's
       file manager can widen it later, and this is cheap to reassert. */
    @chmod($real, 0700);

    return $resolved = $real;
}

/**
 * An absolute, tidied path — for something that may not exist yet.
 *
 * realpath() returns false for a directory that has not been created, and the
 * containment check has to run before creating it, so the cleaning up of "."
 * and ".." is done here instead.
 */
function t4t_absolute(string $path): string
{
    if (!str_starts_with($path, '/')) {
        $path = (getcwd() ?: '') . '/' . $path;
    }

    $parts = [];

    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return '/' . implode('/', $parts);
}

/**
 * Refuse a private directory that a browser could ask for.
 *
 * This is the check the whole file exists for. Being inside the document root
 * is not made safe by an .htaccess rule sitting next to it — the rule is what
 * we are declining to depend on.
 */
function t4t_assert_outside_document_root(string $dir): void
{
    $root = t4t_document_root();

    if ($root === '' || $root === '/') {
        return;
    }

    if ($dir === $root || str_starts_with($dir . '/', $root . '/')) {
        throw new RuntimeException(
            'The private directory is inside the document root and would be reachable '
            . 'over HTTP: ' . $dir . ' is within ' . $root . '. Move it beside the '
            . 'document root and point $T4T_PRIVATE at it.'
        );
    }
}

/** One file in the store, by its key in T4T_PRIVATE_FILES. */
function t4t_private_path(string $which): string
{
    if (!isset(T4T_PRIVATE_FILES[$which])) {
        throw new RuntimeException('Unknown private file: ' . $which);
    }

    return t4t_private_dir() . '/' . T4T_PRIVATE_FILES[$which];
}

/**
 * Whether the store is ready to be used — the directory resolves and is
 * writable. Returns a problem to show, or '' when all is well.
 *
 * Separate from t4t_private_dir() because the admin wants to explain a
 * misconfiguration to a person rather than let an exception reach a stack
 * trace, and a probe wants to report on it without dying.
 */
function t4t_private_problem(): string
{
    try {
        $dir = t4t_private_dir();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    if (!is_writable($dir)) {
        return 'The private directory is not writable by PHP: ' . $dir;
    }

    return '';
}

/* ------------------------------------------------------------------- keys */

/**
 * The master key, created once and then never touched again.
 *
 * Regenerating it would invalidate every stored password at a stroke — the
 * pepper would change, so no hash on file would ever verify again — so the
 * creation path is written to lose a race rather than win one: if two requests
 * arrive at an empty store together, whichever file lands first is the one both
 * of them go on to read.
 */
function t4t_master_key(): string
{
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    $path = t4t_private_path('key');

    $raw = @file_get_contents($path);
    if (is_string($raw) && strlen(trim($raw)) >= 64) {
        return $key = trim($raw);
    }

    $fresh = bin2hex(random_bytes(32));

    /* x mode fails if the file already exists, which is the whole point: it
       makes "create only if absent" one atomic step rather than a check and a
       write with a gap between them. */
    $handle = @fopen($path, 'x');
    if ($handle !== false) {
        fwrite($handle, $fresh . "\n");
        fclose($handle);
        @chmod($path, 0600);
        return $key = $fresh;
    }

    /* Somebody else created it between our read and our write. Theirs wins. */
    $raw = @file_get_contents($path);
    if (is_string($raw) && strlen(trim($raw)) >= 64) {
        return $key = trim($raw);
    }

    throw new RuntimeException('Could not read or create the master key at ' . $path);
}

/**
 * A key for one purpose, derived from the master.
 *
 * Separate purposes get separate keys so that a weakness in how one of them is
 * used cannot be carried into another: the key that peppers passwords is not
 * the key that hashes reset codes, and neither is the key that signs a publish
 * request, even though all three come from the same 32 bytes.
 */
function t4t_key(string $purpose): string
{
    return hash_hmac('sha256', $purpose, t4t_master_key(), true);
}

/**
 * A short name for the master key, which identifies it without revealing it.
 *
 * Stored alongside anything derived from the key, so that a value made under a
 * key that is now gone can be recognised as such rather than merely failing to
 * match. The difference matters: "wrong code" sends somebody hunting for the
 * right one, and "these were made under a key this server no longer has" sends
 * them to their backups.
 *
 * An HMAC of a fixed label under the key, truncated. Reversing it is the same
 * problem as reversing the key, and sixteen hex characters is far more than
 * enough to tell two random 32-byte keys apart.
 */
function t4t_key_fingerprint(): string
{
    return substr(bin2hex(t4t_key('key-fingerprint')), 0, 16);
}
