<?php
/**
 * Tech4TIME — using a reset code.
 *
 * Two steps, in this order and no other:
 *
 *   1. the six digits emailed to the address on the account
 *   2. the authenticator app, AND the new password
 *
 * Step two is why a breached mailbox does not cost you the website. If the
 * emailed code were sufficient on its own, whoever reads that mailbox would
 * hold the admin password, and the second factor would be protecting nothing at
 * the one moment it matters most. A recovery code is accepted in the app's
 * place, for a phone that has been lost.
 *
 * Between the steps, what has been proven lives in the session — never in a
 * hidden field. A form post should not be able to assert "the emailed code was
 * accepted" on its own say-so.
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

/** How long to finish setting a new password once the emailed code is accepted. */
const RESET_FINISH_TTL = 900;

$error = '';
$note  = '';

if (isset($_GET['sent'])) {
    $note = 'If that account exists, a code is on its way. It lasts ten minutes.';
}

/* What step one proved, if it has been done. */
$proven = $_SESSION['reset'] ?? null;

if (!is_array($proven) || time() - (int)($proven['at'] ?? 0) > RESET_FINISH_TTL) {
    unset($_SESSION['reset']);
    $proven = null;
}

$stage = $proven === null ? 'code' : 'finish';

/* ------------------------------------------------------------------ posted */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_check_csrf();

    $do = (string)($_POST['do'] ?? '');

    if ($do === 'code') {
        $account = reset_verify((string)($_POST['code'] ?? ''));

        if ($account === null) {
            $left  = reset_tries_left();
            $error = $left > 0
                ? 'That code is not right, or it has expired. '
                  . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'That code is not right, or it has expired. Ask for a new one.';
            auth_log('reset-code-failed');
        } else {
            /* Regenerate: the browser is about to hold "this person proved a
               reset code", which is authority it did not have a moment ago. */
            session_regenerate_id(true);

            $_SESSION['reset'] = ['user' => $account['user'], 'at' => time()];
            reset_forget();

            header('Location: reset.php');
            exit;
        }
    }

    if ($do === 'finish' && $proven !== null) {
        $account  = auth_find((string)$proven['user']);
        $second   = (string)($_POST['second'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $again    = (string)($_POST['password2'] ?? '');

        $problem = auth_password_problem($password);

        if ($account === null || $account['disabled']) {
            unset($_SESSION['reset']);
            $error = 'That account is no longer available.';
        } elseif ($problem !== '') {
            $error = $problem;
        } elseif (!hash_equals($password, $again)) {
            $error = 'The two passwords are not the same.';
        } elseif (!auth_second_factor($account, $second)) {
            $error = 'That authenticator code is not right.';
            auth_log('reset-second-factor-failed', ['user' => $account['user']]);
        } elseif (!reset_finish($account, $password)) {
            $error = 'The new password could not be saved. Check that the private '
                   . 'directory is writable and try again.';
        } else {
            unset($_SESSION['reset']);
            session_regenerate_id(true);
            header('Location: login.php?reset=1');
            exit;
        }
    }
}

/* ----------------------------------------------------------------- the page */

if ($stage === 'finish') {
    admin_shell_head(
        'Choose a new password',
        'One more check, then the new password.',
        'user-shield'
    );
} else {
    admin_shell_head('Enter your code', 'The six digits we emailed you.', 'envelope');
}

admin_shell_error($error);
admin_shell_note($error === '' ? $note : '');
?>

<?php if ($stage === 'finish'): ?>

<form class="signin__form" method="post" action="reset.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="do" value="finish">

  <div class="admin__field">
    <label class="admin__label" for="second">Authenticator code</label>
    <input class="admin__input signin__code" id="second" name="second" type="text"
           inputmode="numeric" autocomplete="one-time-code" maxlength="14"
           required autofocus>
    <p class="admin__hint">
      Six digits from your app, or one of your recovery codes.
    </p>
  </div>

  <div class="admin__field">
    <label class="admin__label" for="password">New password</label>
    <input class="admin__input" id="password" name="password" type="password"
           autocomplete="new-password" required minlength="12">
    <p class="admin__hint">
      At least 12 characters. Three or four unrelated words beat one clever word.
    </p>
  </div>

  <div class="admin__field">
    <label class="admin__label" for="password2">New password again</label>
    <input class="admin__input" id="password2" name="password2" type="password"
           autocomplete="new-password" required minlength="12">
  </div>

  <button class="btn btn--primary btn--block" type="submit">Set the password</button>
</form>

<p class="signin__aside">
  Setting it signs out every device that is currently signed in.
</p>

<?php else: ?>

<form class="signin__form" method="post" action="reset.php">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf()) ?>">
  <input type="hidden" name="do" value="code">

  <div class="admin__field">
    <label class="admin__label" for="code">Six-digit code</label>
    <input class="admin__input signin__code" id="code" name="code" type="text"
           inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*"
           maxlength="8" required autofocus>
    <p class="admin__hint">
      Use the same browser you asked from — the code is tied to it.
    </p>
  </div>

  <button class="btn btn--primary btn--block" type="submit">Continue</button>
</form>

<p class="signin__aside">
  <a href="forgot.php">Ask for another code</a> &middot;
  <a href="login.php">Back to signing in</a>
</p>

<?php endif; ?>

<?php
admin_shell_foot(
    '<p>' . admin_icon('info-circle', 'icon icon--sm')
    . ' Each code works once and lasts ten minutes.</p>'
);
