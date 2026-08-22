<?php
/**
 * Tech4TIME — the admin, front door.
 *
 * Checks that somebody is signed in, then hands over to the section they asked
 * for. Everything the sections rely on is set up in lib/admin.php.
 *
 * WHO MAY BE HERE
 * admin_require_auth() either returns the signed-in account or does not return
 * at all: it redirects to login.php, sends first-run visitors to setup.php, or
 * refuses outright when the private store is missing or the connection is not
 * encrypted. Nothing below runs for a stranger.
 *
 * This directory used to be protected by cPanel Directory Privacy instead, and
 * the old warning about it applies to nothing now — but while both exist, DO
 * NOT commit an .htaccess into this directory: cPanel writes its own here when
 * Directory Privacy is switched on, and uploading over it removes the password.
 */

declare(strict_types=1);

define('T4T_ADMIN', true);

require __DIR__ . '/../lib/admin.php';

$account = admin_require_auth();

/* The sections want a name to print in the bar; the account page wants the
   whole record. Both are in scope for the file included below. */
$user = $account['name'] !== '' ? $account['name'] : $account['user'];

$section = admin_section();

/* Every section file expects this to exist, adds to it if a save failed, and
   prints the body of the page between admin_head() and admin_foot(). */
$errors = [];

require __DIR__ . '/sections/' . $section . '.php';
