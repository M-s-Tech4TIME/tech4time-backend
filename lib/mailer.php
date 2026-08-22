<?php
/**
 * Tech4TIME — sending mail.
 *
 * One place where mail leaves this site. It was the contact form's private
 * business until the admin needed to send a password-reset code, and two copies
 * of header handling is one copy too many when getting it wrong means somebody
 * else can add headers of their own.
 *
 * ON THIS HOST
 * MX for tech4time.bd points at the web server itself, so mail to the domain
 * never leaves the box. SPF authorises that server and cPanel signs outbound
 * mail with the "default" DKIM selector. A reset code sent to an address at the
 * domain is therefore a local delivery, which is the most reliable path there
 * is — and the reason to keep the recovery address at the domain rather than at
 * a free mailbox somewhere.
 *
 * WHAT mail() RETURNING TRUE MEANS
 * That the local mailer accepted the message. Not that it arrived. Nothing here
 * can tell the difference, so callers treat false as certain failure and true
 * as merely probable success.
 *
 * Not reachable over HTTP: .htaccess forbids /lib/.
 */

declare(strict_types=1);

/* The From: address must be at the site's own domain or the message fails SPF
   and is filed as spam. Whoever should receive a reply goes in Reply-To. */
const MAIL_FROM_ADDRESS = 'no-reply@tech4time.bd';
const MAIL_FROM_NAME    = 'Tech4TIME';

/**
 * Why mail cannot be sent, in words, or '' when it can.
 *
 * mail() is a checkbox on shared hosting and disable_functions is where it goes
 * when unticked. Calling it there is a fatal error, not a warning.
 */
function mail_problem(): string
{
    if (!function_exists('mail')) {
        return 'PHP on this host has no mail() function.';
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    if (in_array('mail', $disabled, true)) {
        return 'mail() is switched off on this host (disable_functions). Ask the host '
             . 'to enable it, or point the mailer at authenticated SMTP.';
    }

    return '';
}

/**
 * Flatten anything going into a header.
 *
 * A newline in a header value lets whoever supplied it start a header of their
 * own — a Bcc, say — so the newlines go before the value is ever placed.
 */
function mail_header_safe(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
}

/**
 * Send one plain-text message.
 *
 * $options: reply_to, from_name.
 */
function mail_send(string $to, string $subject, string $body, array $options = []): bool
{
    if (mail_problem() !== '') {
        return false;
    }

    $to = mail_header_safe($to);

    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $from_name = mail_header_safe((string)($options['from_name'] ?? MAIL_FROM_NAME));

    /* No X-Mailer header. The usual "PHP/8.x" value announces that a script sent
       this rather than a person, which several filters score against, and it
       tells a stranger the PHP version the host is running. It buys nothing. */
    $headers = [
        'From: ' . $from_name . ' <' . MAIL_FROM_ADDRESS . '>',
        'Content-Type: text/plain; charset=utf-8',
        'MIME-Version: 1.0',
    ];

    $reply_to = mail_header_safe((string)($options['reply_to'] ?? ''));
    if ($reply_to !== '' && filter_var($reply_to, FILTER_VALIDATE_EMAIL) !== false) {
        $headers[] = 'Reply-To: ' . $reply_to;
    }

    /* -f sets the envelope sender, which is what SPF and DMARC are actually
       checked against — the From: header is not. Without it the envelope is
       whatever the unix user happens to be, which does not align with the
       domain and costs the message reputation at any external inbox. */
    $sent = @mail(
        $to,
        mail_header_safe($subject),
        $body,
        implode("\r\n", $headers),
        '-f' . MAIL_FROM_ADDRESS
    );

    /* Some hosts refuse the -f parameter outright rather than ignoring it,
       because setting an envelope sender needs the PHP user to be trusted by
       the MTA. Delivering without alignment beats not delivering at all. */
    if (!$sent) {
        $sent = @mail($to, mail_header_safe($subject), $body, implode("\r\n", $headers));
    }

    return (bool)$sent;
}
