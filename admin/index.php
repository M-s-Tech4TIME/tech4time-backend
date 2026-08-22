<?php
/**
 * Tech4TIME — the admin.
 *
 * One entry point for every part of the site that can be edited without a
 * redeploy. Each part is a file in admin/sections/, listed in ADMIN_SECTIONS
 * in lib/admin.php, and reached at /admin/?s=<name>.
 *
 * There is no database. Each section reads and writes one JSON file under
 * content/, and the public page renders straight from that same file — so the
 * editor and the page cannot disagree about what the page contains. Change the
 * shape of a page and the model, the form and the renderer move together;
 * tools/check_content_model.py fails if one of them is left behind.
 *
 * PROTECTING THIS DIRECTORY
 * cPanel: Directory Privacy -> /admin -> add a user with a password. The check
 * in admin_require_auth() refuses to run without it. See lib/admin.php.
 *
 * DO NOT commit an .htaccess into this directory: cPanel writes its own here
 * when Directory Privacy is switched on, and uploading over it removes the
 * password.
 */

declare(strict_types=1);

define('T4T_ADMIN', true);

require __DIR__ . '/../lib/admin.php';

$user = admin_require_auth();
admin_start_session();

$section = admin_section();

/* Every section file expects this to exist, adds to it if a save failed, and
   prints the body of the page between admin_head() and admin_foot(). */
$errors = [];

require __DIR__ . '/sections/' . $section . '.php';
