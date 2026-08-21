<?php
/**
 * Tech4TIME — router for the local preview server.
 *
 * DEVELOPMENT ONLY. NEVER DEPLOYED.
 * It lives in tools/, which .htaccess blocks over HTTP, and it only ever runs
 * when it is passed to `php -S` by hand. Nothing on the web server loads it.
 *
 * WHY IT EXISTS
 * Two things the PHP built-in server does not do that Apache does:
 *
 *   1. Basic auth. On the host, cPanel's Directory Privacy protects /admin and
 *      Apache passes the authenticated user to PHP in REMOTE_USER. Nothing
 *      does that locally, so admin/index.php would correctly refuse to load.
 *      This supplies the same variable so the editor can be worked on — it
 *      FAKES the authentication, it does not perform any.
 *
 *   2. DirectoryIndex across both extensions. Apache is configured with
 *      "index.html index.php"; this resolves a directory request the same way
 *      so /pages/careers/ finds its .php and every other page finds its .html.
 *
 * Start it with tools/serve.py rather than by hand.
 */

declare(strict_types=1);

const DEV_USER = 'local-admin';

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$target = $root . rawurldecode($path);

/* Stand in for the Basic auth that protects /admin in production. */
if (str_starts_with($path, '/admin')) {
    $_SERVER['REMOTE_USER'] = DEV_USER;
}

/* Apache's DirectoryIndex, in its order. */
if (is_dir($target)) {
    foreach (['index.php', 'index.html'] as $name) {
        $candidate = rtrim($target, '/') . '/' . $name;
        if (is_file($candidate)) {
            $target = $candidate;
            break;
        }
    }
}

if (is_file($target) && str_ends_with($target, '.php')) {
    require $target;
    return true;
}

/* Anything else: let the built-in server serve the file, or 404 it. */
return false;
