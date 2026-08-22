<?php
/**
 * Tech4TIME — router for the local preview server.
 *
 * DEVELOPMENT ONLY. NEVER DEPLOYED.
 * It lives in tools/, which .htaccess blocks over HTTP, and it only ever runs
 * when it is passed to `php -S` by hand. Nothing on the web server loads it.
 *
 * WHY IT EXISTS
 * DirectoryIndex across both extensions. Apache is configured with
 * "index.html index.php"; this resolves a directory request the same way, so
 * /pages/careers/ finds its .php and every other page finds its .html.
 *
 * IT NO LONGER FAKES A SIGN-IN
 * It used to set REMOTE_USER, because /admin was protected by cPanel Directory
 * Privacy and admin/index.php would otherwise have refused to load. The admin
 * has its own accounts now, so there is nothing to stand in for: run
 * /admin/setup.php once to create a local account, then sign in for real.
 *
 * Working on the editor against the real sign-in is the point. A fake one hides
 * exactly the bugs that matter — a session that does not survive a redirect, a
 * token that does not match, a form that posts to the wrong place.
 *
 * WHERE THE LOCAL SECRETS GO
 * lib/private.php puts them beside the document root, which here is the repo,
 * so they land in ../t4t-private — outside the tree, never committed, and the
 * same shape as /home/USER/t4t-private on the host. Set T4T_PRIVATE to put
 * them somewhere else; the test harnesses do, so a test run cannot disturb
 * whatever account you signed in with.
 *
 * Start it with tools/serve.py rather than by hand.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$target = $root . rawurldecode($path);

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
