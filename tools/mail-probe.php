<?php
/**
 * Tech4TIME — one-off host mail probe.
 *
 * NOT PART OF THE SITE. This lives in tools/ and is never deployed as part of a
 * normal upload. You upload it by hand, load it once, read the answer, and
 * DELETE IT. See tools/README.md.
 *
 * WHY
 * mail() cannot be tested anywhere but the host it will run on. Everything
 * else about contact-handler.php is covered by tools/test_contact_handler.py;
 * this closes the last gap, in isolation, before the real site goes up — so a
 * mail problem shows up as one failed probe rather than a silent contact form.
 *
 * It reports what the host actually provides (PHP version, whether mail() is
 * disabled, whether mbstring is there) and then sends one message built exactly
 * the way the real handler builds its own.
 *
 * SAFETY
 * The recipient is hard-coded. This cannot be pointed at another address, so
 * the worst anyone who finds it can do is put mail in your own inbox — and the
 * token below stops that too.
 *
 * HOW TO USE
 *   1. Change PROBE_TOKEN to anything unguessable. It will not run until you do.
 *   2. Upload to public_html/ alongside index.html — NOT into tools/, which
 *      .htaccess forbids over HTTP. Leaving it there does nothing; it only runs
 *      if you deliberately move it to the web root.
 *   3. Visit https://tech4time.bd/mail-probe.php?token=whatever-you-chose
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

echo "Tech4TIME mail probe\n";
echo str_repeat('=', 52), "\n\n";

echo "HOST\n";
printf("  PHP version        %s\n", PHP_VERSION);
printf("  Server             %s\n", $_SERVER['SERVER_SOFTWARE'] ?? 'unknown');
printf("  sendmail_path      %s\n", ini_get('sendmail_path') ?: '(not set)');
printf("  mail() available   %s\n", $mail_ok ? 'yes' : 'NO — disabled on this host');
printf("  mbstring           %s\n", extension_loaded('mbstring')
    ? 'yes' : 'no (fine — the handler does not need it)');
echo "\n";

if (!$mail_ok) {
    http_response_code(500);
    echo "mail() is disabled here, so contact-handler.php cannot send.\n";
    echo "Ask the host to enable it, or switch the handler to SMTP auth.\n";
    echo "\nDELETE THIS FILE FROM THE SERVER.\n";
    exit;
}

/* Built the same way the real handler builds it, so a failure here is a
   failure there. */
$stamp = gmdate('Y-m-d H:i:s');
$body  = "This is a test from tools/mail-probe.php.\n\n"
       . "If you are reading it in the info@tech4time.bd inbox, mail() works\n"
       . "on this host and the website contact form will deliver.\n\n"
       . str_repeat('-', 44) . "\n"
       . "Sent:   {$stamp} UTC\n"
       . 'From IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n\n"
       . "Now delete mail-probe.php from the server.\n";

$headers = implode("\r\n", [
    'From: Tech4TIME Website <' . MAIL_FROM . '>',
    'Reply-To: ' . MAIL_TO,
    'Content-Type: text/plain; charset=utf-8',
    'MIME-Version: 1.0',
]);

$sent = @mail(MAIL_TO, 'Tech4TIME mail probe ' . $stamp, $body, $headers);

echo "RESULT\n";
if ($sent) {
    printf("  mail() accepted the message for %s\n\n", MAIL_TO);
    echo "  That means the local mailer took it — NOT that it arrived.\n";
    echo "  Check the inbox now. If it is not there within a minute or two,\n";
    echo "  look at cPanel > Track Delivery, which will say where it stopped.\n";
} else {
    http_response_code(500);
    $last = error_get_last();
    printf("  mail() REFUSED the message.\n");
    if ($last) {
        printf("  PHP said: %s\n", $last['message']);
    }
    echo "\n  Check that the domain is not over its hourly sending limit, and\n";
    echo "  that " . MAIL_FROM . " exists as a real account in cPanel.\n";
}

echo "\n", str_repeat('=', 52), "\n";
echo "DELETE THIS FILE FROM THE SERVER NOW.\n";
