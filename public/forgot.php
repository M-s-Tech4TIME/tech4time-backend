<?php
/**
 * Tech4TIME — asking for a reset code.
 *
 * THE ONE ADMIN PAGE A STRANGER CAN REACH
 * Every other page here can hide behind the sign-in. This one has to work at
 * the moment nobody can sign in, so anybody who finds the URL can load it. What
 * follows from that is in lib/reset.php, which does the work: the answer is the
 * same whether or not the account exists, the code goes only to the address on
 * file, and asking is rationed per account, per address and overall.
 *
 * The last of those is not about this site. cPanel caps outbound mail per hour,
 * and somebody hammering this page could use the allowance up — which would
 * stop the genuine reset from being delivered at the moment it was wanted.
 */

declare(strict_types=1);

define('T4T_ADMIN', true);

require __DIR__ . '/../lib/admin.php';
require __DIR__ . '/../lib/reset.php';

admin_start_session();

if (!auth_has_accounts()) {
    header('Location: setup.php');
    exit;
}

/* Somebody signed in does not need this; they have the account page. */
if (auth_session_user() !== null) {
    header('Location: ' . ADMIN_BASE . '?s=account');
    exit;
}

$error = '';

/* Worth saying plainly rather than pretending to send: it gives away nothing
   about who has an account, and being told "check your email" when no email can
   be sent is how somebody ends up locked out believing they are not. */
$mail_trouble = mail_problem();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mail_trouble === '') {
    auth_check_csrf();

    $who = trim((string)($_POST['who'] ?? ''));

    if ($who === '') {
        $error = 'Enter your username or the email address on the account.';
    } else {
        $wait = reset_begin($who);

        if ($wait > 0) {
            $error = 'This has been asked for too many times. Try again in '
                   . throttle_wait_text($wait) . '.';
        } else {
            header('Location: reset.php?sent=1');
            exit;
        }
    }
}

admin_shell_head(
    'Forgotten password',
    'We will email a one-time code to the address on the account.',
    'question-circle'
);

admin_shell_error($error);
?>

<?php if ($mail_trouble !== ''): ?>

<div class="admin__notice admin__notice--warn signin__notice">
  <p><strong>This server cannot send email at the moment.</strong></p>
  <p><?= h($mail_trouble) ?></p>
  <p>A password can still be reset from the server itself — whoever maintains
     the site uploads <code>tools/admin-cli.php</code> and runs
     <code>php ~/admin-cli.php passwd</code>. A recovery code will also sign
     you in, if you kept them.</p>
</div>

<?php else: ?>

<form class="signin__form" method="post" action="forgot.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">

  <div class="admin__field">
    <label class="admin__label" for="who">Username or email</label>
    <input class="admin__input" id="who" name="who" type="text"
           autocomplete="username" required autofocus>
    <p class="admin__hint">
      The code goes to the address stored on the account, whatever is typed here.
    </p>
  </div>

  <button class="btn btn--primary btn--block" type="submit">Email me a code</button>
</form>

<?php endif; ?>

<p class="signin__aside">
  <a href="login.php">Back to signing in</a>
</p>

<?php
admin_shell_foot(
    '<p>' . admin_icon('info-circle', 'icon icon--sm')
    . ' The code on its own cannot change a password — the authenticator app is '
    . 'still needed, so a breached mailbox is not enough to take the account.</p>'
);
