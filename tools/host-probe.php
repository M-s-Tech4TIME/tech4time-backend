<?php
/**
 * Tech4TIME — one-off host probe.
 *
 * NOT PART OF THE SITE. This lives in tools/ and is never deployed as part of a
 * normal upload. You upload it by hand, load it once, read the answer, and
 * DELETE IT. See docs/20-deployment/first-deploy.md.
 *
 * WHY
 * Two things cannot be tested anywhere but the host they will run on, and both
 * of them fail quietly rather than loudly:
 *
 *   mail()      — a checkbox on shared hosting. Without it the contact form
 *                 silently stops delivering, and a forgotten admin password
 *                 becomes unrecoverable by email.
 *   argon2id    — the password hash the admin prefers. Where PHP was built
 *                 without it, lib/auth.php falls back to bcrypt on its own, but
 *                 you should know which one you got rather than assume.
 *
 * It also checks the things the admin refuses to start without, so a
 * misconfiguration shows up here — with an explanation — rather than as a 503
 * on the editor at the moment somebody needs it.
 *
 * SAFETY
 * The recipient is hard-coded. This cannot be pointed at another address, so
 * the worst anyone who finds it can do is put mail in your own inbox — and the
 * token below stops that too. It reads no secrets and prints none: it reports
 * whether the private store is in the right place, never what is in it.
 *
 * HOW TO USE
 *   1. Change PROBE_TOKEN to anything unguessable. It will not run until you do.
 *   2. Upload to public_html/ alongside index.html — NOT into tools/, which
 *      .htaccess forbids over HTTP. Leaving it there does nothing; it only runs
 *      if you deliberately move it to the web root.
 *   3. Visit https://tech4time.bd/host-probe.php?token=whatever-you-chose
 *   4. Read the report, check the inbox.
 *   5. DELETE IT FROM THE SERVER.
 */

declare(strict_types=1);

/* Change this. The probe refuses to run while it reads CHANGE-ME. */
const PROBE_TOKEN = 'CHANGE-ME';

/* The same addresses the real handler uses. Hard-coded on purpose: nothing
   here reads a recipient from the request. */
const MAIL_TO   = 'info@tech4time.bd';
const MAIL_FROM = 'no-reply@tech4time.bd';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (PROBE_TOKEN === 'CHANGE-ME') {
    http_response_code(500);
    exit("Set PROBE_TOKEN to something unguessable before uploading this.\n");
}

/* hash_equals rather than !== so the comparison does not leak the token one
   character at a time through timing. */
if (!hash_equals(PROBE_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit("Not found\n");
}

$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$mail_ok  = function_exists('mail') && !in_array('mail', $disabled, true);

echo "Tech4TIME host probe\n";
echo str_repeat('=', 60), "\n\n";

/* ------------------------------------------------------------------ PHP */

echo "PHP\n";
printf("  Version            %s\n", PHP_VERSION);
printf("  Server             %s\n", $_SERVER['SERVER_SOFTWARE'] ?? 'unknown');
printf("  HTTPS              %s\n",
    (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        ? 'yes' : 'NO — the admin will refuse to run');
printf("  mbstring           %s\n", extension_loaded('mbstring')
    ? 'yes' : 'no (fine — nothing here needs it)');
printf("  dom                %s\n", extension_loaded('dom')
    ? 'yes' : 'no (fine — the sanitiser is hand-written)');
echo "\n";

/* ------------------------------------------------------- signing in */

echo "SIGNING IN\n";

$argon = defined('PASSWORD_ARGON2ID');
printf("  argon2id           %s\n", $argon
    ? 'yes — this is what will be used'
    : 'no — bcrypt at cost 12 will be used instead, which is fine');

if ($argon) {
    /* Time it. A hash that takes no time is a hash worth little, and one that
       takes half a second makes signing in feel broken. Somewhere around a
       tenth of a second is the point of the exercise. */
    $started = microtime(true);
    @password_hash('probe', PASSWORD_ARGON2ID,
        ['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1]);
    printf("  one hash takes     %d ms\n", (int)round((microtime(true) - $started) * 1000));
}

printf("  random_bytes       %s\n", function_exists('random_bytes') ? 'yes' : 'NO — cannot run');
printf("  sessions           %s\n", extension_loaded('session') ? 'yes' : 'NO — cannot run');
echo "\n";

/* -------------------------------------------------- the private store */

echo "THE PRIVATE STORE\n";
echo "  This must sit BESIDE the document root, not inside it.\n";

$docroot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$guess   = ($docroot !== '' ? dirname($docroot) : '/home/USER') . '/t4t-private';
$env     = trim((string)(getenv('T4T_PRIVATE') ?: ''));
$where   = $env !== '' ? $env : $guess;

printf("  Document root      %s\n", $docroot !== '' ? $docroot : '(unknown)');
printf("  T4T_PRIVATE        %s\n", $env !== '' ? $env : '(not set — the default is used)');
printf("  Looking in         %s\n", $where);

if (!is_dir($where)) {
    printf("  Exists             no — it will be created on first use\n");
} else {
    printf("  Exists             yes\n");
    printf("  Writable by PHP    %s\n", is_writable($where) ? 'yes' : 'NO — saves will fail');
    printf("  Permissions        %04o%s\n", fileperms($where) & 0777,
        (fileperms($where) & 0777) === 0700 ? '' : '  (0700 is expected)');
    printf("  Account set up     %s\n",
        is_file($where . '/admins.json') ? 'yes' : 'no — visit /admin/setup.php');
}

$inside = $docroot !== '' && str_starts_with(rtrim($where, '/') . '/', $docroot . '/');
printf("  Inside the web root %s\n", $inside
    ? 'YES — the admin will refuse to run. Move it up one level.'
    : 'no — good');
echo "\n";

/* ------------------------------------------------------------------ mail */

echo "MAIL\n";
printf("  sendmail_path      %s\n", ini_get('sendmail_path') ?: '(not set)');
printf("  mail() available   %s\n", $mail_ok ? 'yes' : 'NO — disabled on this host');
echo "\n";

if (!$mail_ok) {
    http_response_code(500);
    echo "  mail() is disabled here, so the contact form cannot send and a\n";
    echo "  forgotten admin password cannot be recovered by email. Ask the host\n";
    echo "  to enable it, or switch lib/mailer.php to authenticated SMTP.\n";
    echo "\n", str_repeat('=', 60), "\n";
    echo "DELETE THIS FILE FROM THE SERVER NOW.\n";
    exit;
}

/* Built the same way lib/mailer.php builds it, including the envelope sender,
   so a failure here is a failure there. */
$stamp = gmdate('Y-m-d H:i:s');
$body  = "This is a test from tools/host-probe.php.\n\n"
       . "If you are reading it in the " . MAIL_TO . " inbox, mail() works on\n"
       . "this host: the contact form will deliver, and so will an admin\n"
       . "password reset code.\n\n"
       . str_repeat('-', 44) . "\n"
       . "Sent:    {$stamp} UTC\n"
       . 'From IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n\n"
       . "Now delete host-probe.php from the server.\n";

$headers = implode("\r\n", [
    'From: Tech4TIME <' . MAIL_FROM . '>',
    'Reply-To: ' . MAIL_TO,
    'Content-Type: text/plain; charset=utf-8',
    'MIME-Version: 1.0',
]);

$sent = @mail(MAIL_TO, 'Tech4TIME host probe ' . $stamp, $body, $headers, '-f' . MAIL_FROM);
$note = '';

if (!$sent) {
    /* Some hosts refuse -f rather than ignoring it. lib/mailer.php retries the
       same way, so try it here to find out which case this is. */
    $sent = @mail(MAIL_TO, 'Tech4TIME host probe ' . $stamp, $body, $headers);
    $note = $sent ? "  (accepted only WITHOUT the -f envelope sender)\n" : '';
}

echo "RESULT\n";

if ($sent) {
    printf("  mail() accepted the message for %s\n", MAIL_TO);
    echo $note;
    echo "\n  That means the local mailer took it — NOT that it arrived.\n";
    echo "  Check the inbox now. If it is not there within a minute or two,\n";
    echo "  look at cPanel > Track Delivery, which will say where it stopped.\n";
    echo "\n  Then check that the address you will use for admin password\n";
    echo "  recovery exists as a real mailbox in cPanel, and that you can\n";
    echo "  open it. A reset code goes there and nowhere else.\n";
} else {
    http_response_code(500);
    $last = error_get_last();
    echo "  mail() REFUSED the message.\n";
    if ($last) {
        printf("  PHP said: %s\n", $last['message']);
    }
    echo "\n  Check that the domain is not over its hourly sending limit, and\n";
    echo "  that " . MAIL_FROM . " exists as a real account in cPanel.\n";
}

echo "\n", str_repeat('=', 60), "\n";
echo "DELETE THIS FILE FROM THE SERVER NOW.\n";
