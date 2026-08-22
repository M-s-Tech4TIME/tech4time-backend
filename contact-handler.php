<?php
/**
 * Tech4TIME — contact form handler.
 *
 * The only server-side code on the site. Everything else is static; this exists
 * because a contact form has to post somewhere.
 *
 * It answers both ways the form can arrive:
 *   - fetch() from forms.js, which sends Accept: application/json and expects
 *     {ok, message} or {ok:false, error} back;
 *   - a plain POST from a browser with no JavaScript, which gets a rendered
 *     confirmation page back, built from the site's own stylesheets.
 *
 * The validation below deliberately repeats what forms.js already checks. That
 * is not duplication for its own sake: client-side validation is a convenience
 * for the visitor and provides no protection at all, since anything can post
 * here directly.
 *
 * SPAM. The honeypot stops crawlers that fill in every field. It does nothing
 * against a bot written for this form specifically, so there is also a rate
 * limit — a handful an hour from one address, which no visitor will notice.
 * Beyond that the answer is a real challenge, not more regular expressions.
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ config */

const MAIL_TO      = 'info@tech4time.bd';
const MAIL_SUBJECT = 'Website enquiry';

/* The From: address must be at the site's own domain or the message will fail
   SPF and be filed as spam. The visitor's address goes in Reply-To instead, so
   hitting reply still reaches them. */
const MAIL_FROM = 'no-reply@tech4time.bd';

/* -------------------------------------------------------------- safety net */

/* A blank 500 tells the visitor nothing and leaves us nothing to look at.
   Shared hosting is where unexplained fatals happen — an extension switched
   off in cPanel, a memory limit, a PHP version bump — so whatever goes wrong,
   the visitor still ends up with an address they can write to. */
register_shutdown_function(static function (): void {
    $fatal = error_get_last();
    $hard = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    if ($fatal && ($fatal['type'] & $hard) && !headers_sent()) {
        respond(false, 'We could not send your message just now. Please email '
            . MAIL_TO . ' directly.', 500);
    }
});

/* ------------------------------------------------------------------ helpers */

/**
 * Count characters, with or without mbstring.
 *
 * mbstring is not guaranteed on shared hosting — it is a checkbox in cPanel's
 * PHP extension list — and calling mb_strlen() where it is switched off is a
 * fatal error, not a warning. The limits below are about how much someone
 * typed, so they count characters rather than bytes: measured in bytes, a
 * message in Bangla would be cut off at roughly a third of its real length.
 */
function chars(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    /* Continuation bytes (10xxxxxx) are the second and later bytes of a
       multi-byte character, so removing them leaves one byte per character. */
    return strlen(preg_replace('/[\x80-\xBF]/', '', $value));
}

function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return stripos($accept, 'application/json') !== false;
}

/**
 * Answer in whichever form the request asked for, and stop.
 *
 * The no-JavaScript path renders a page rather than redirecting back to the
 * form with a query string on it. The form's page is static HTML, so it has no
 * way to read that query string and say anything about it — the visitor would
 * land back on an unchanged form with no idea whether it worked.
 */
function respond(bool $ok, string $message, int $status = 200): void
{
    http_response_code($status);

    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok
            ? ['ok' => true, 'message' => $message]
            : ['ok' => false, 'error' => $message]);
        exit;
    }

    $title = $ok ? 'Message sent' : 'Message not sent';
    $safe  = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    /* Built from the site's own stylesheets and classes so it looks like the
       rest of the site. No inline styles: the CSP is style-src 'self'. */
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<title>{$title} | Tech4TIME</title>
<link rel="icon" href="/assets/images/favicon/favicon.ico" sizes="any">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/layout.css">
<link rel="stylesheet" href="/assets/css/components.css">
<script src="/assets/js/theme-init.js"></script>
</head>
<body class="page">
<main class="page__main" id="main">
  <section class="page-hero">
    <div class="container page-hero__inner">
      <h1 class="page-hero__title">{$title}</h1>
      <p class="page-hero__subtitle">{$safe}</p>
    </div>
  </section>
  <section class="cta-band">
    <div class="container cta-band__inner">
      <a class="btn btn--primary btn--lg" href="/pages/contact/">Back to Contact</a>
    </div>
  </section>
</main>
</body>
</html>
HTML;
    exit;
}

function field(string $name): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) {
        return '';
    }
    /* Strip control characters, which is how header injection gets in.

       No /u modifier on purpose. Every byte in that class is an ASCII control
       byte, and UTF-8 never uses those inside a multi-byte character, so a
       byte-wise strip cannot damage valid text. With /u, malformed input makes
       preg_replace return null instead — and the field would silently come
       back empty, which reads as "they left it blank". */
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value));
}

/* ------------------------------------------------------------- method check */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(false, 'This endpoint only accepts form submissions.', 405);
}

/* ------------------------------------------------------------------ honeypot */

/* A field positioned off-screen and marked aria-hidden. Nobody using the site
   can see it or tab to it, so anything in it came from something automated.
   Answer as though it worked: telling a bot it failed only helps it. */
if (field('company') !== '') {
    respond(true, 'Thank you — your message has been sent.');
}

/* ---------------------------------------------------------------- how often */

/* The honeypot catches crawlers that fill in every field. It does nothing at
   all against something written for this form, and the note at the top of this
   file has always said the answer to that is a rate limit. Here it is: a
   handful an hour from one address, which no visitor will ever notice and
   which makes bulk submission pointless.
 *
 * IT FAILS OPEN, DELIBERATELY. The counter lives in the private store, which
 * is also where the admin's passwords live — and if that directory is ever
 * unreadable, the right outcome is a contact form that still works rather than
 * a company that cannot be contacted. This is spam control, not a security
 * boundary; the boundary is that nothing here is trusted anyway. A store that
 * cannot be reached shows up in tools/host-probe.php, where it is actionable.
 */
try {
    require_once __DIR__ . '/lib/throttle.php';

    $wait = throttle_quota(throttle_key('contact', throttle_ip()), 5, 3600);

    if ($wait > 0) {
        respond(false,
            'That is several messages in a short time. Please try again in '
            . throttle_wait_text($wait) . ', or email ' . MAIL_TO . ' directly.',
            429);
    }
} catch (Throwable) {
    /* Counting is unavailable. Carry on: see above. */
}

/* ---------------------------------------------------------------- the fields */

$name    = field('name');
$phone   = field('phone');
$email   = field('email');
$subject = field('subject');
$message = field('message');
$privacy = ($_POST['privacy'] ?? '') !== '';

$errors = [];

if ($name === '') {
    $errors[] = 'Name is required';
} elseif (chars($name) > 100) {
    $errors[] = 'Name must be less than 100 characters';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required';
} elseif (chars($email) > 254) {
    $errors[] = 'Email address is too long';
}

if ($phone === '') {
    $errors[] = 'Phone number is required';
} elseif (!preg_match('/^\+?[0-9 ().-]{7,20}$/', $phone)) {
    $errors[] = 'Phone number is not valid';
}

if ($subject === '') {
    $errors[] = 'Type of service is required';
} elseif (chars($subject) > 120) {
    $errors[] = 'Type of service must be less than 120 characters';
}

if (chars($message) < 10) {
    $errors[] = 'Message must be at least 10 characters';
} elseif (chars($message) > 5000) {
    $errors[] = 'Message must be less than 5000 characters';
}

if (!$privacy) {
    $errors[] = 'Please confirm you have read the privacy policy';
}

/* The mail goes out declared as charset=utf-8, so anything that is not valid
   UTF-8 would arrive as mojibake. Every browser posts UTF-8 from this page —
   the form's own charset says so — which makes malformed input a sign that the
   request did not come from the form. */
foreach ([$name, $phone, $email, $subject, $message] as $value) {
    if ($value !== '' && preg_match('//u', $value) !== 1) {
        $errors[] = 'Your message contains characters we could not read';
        break;
    }
}

if ($errors) {
    respond(false, implode('. ', $errors) . '.', 422);
}

/* --------------------------------------------------------------- send it on */

/* Newlines in a header value let an attacker append headers of their own, so
   anything that reaches one is collapsed first. */
$safe_subject = str_replace(["\r", "\n"], ' ', $subject);
$safe_email   = str_replace(["\r", "\n"], '', $email);

$body = "New enquiry from the Tech4TIME website\n"
      . str_repeat('-', 44) . "\n\n"
      . "Name:            {$name}\n"
      . "Email:           {$email}\n"
      . "Phone:           {$phone}\n"
      . "Type of service: {$safe_subject}\n\n"
      . "Message:\n{$message}\n\n"
      . str_repeat('-', 44) . "\n"
      . 'Received: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
      . 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";

/* No X-Mailer header. The usual "PHP/8.x" value announces that a script sent
   this rather than a person, which several filters score against — and it
   tells a stranger the PHP version the host is running. It buys nothing. */
$headers = [
    'From: Tech4TIME Website <' . MAIL_FROM . '>',
    'Reply-To: ' . $safe_email,
    'Content-Type: text/plain; charset=utf-8',
    'MIME-Version: 1.0',
];

$sent = @mail(
    MAIL_TO,
    MAIL_SUBJECT . ': ' . $safe_subject,
    $body,
    implode("\r\n", $headers)
);

if (!$sent) {
    /* mail() returning false means the local mailer would not accept it. The
       visitor cannot act on that, so give them the address instead. */
    respond(false, 'We could not send your message just now. Please email ' . MAIL_TO . ' directly.', 500);
}

respond(true, 'Thank you — your message has been sent. We will reply within one working day.');
