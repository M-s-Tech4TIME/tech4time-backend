<?php
/**
 * Tech4TIME backend — the local server's router.
 *
 * Development tool. NOT deployed to the web server (see tools/README.md).
 * Start it with tools/serve.py rather than by hand.
 *
 * WHAT IT REPRODUCES
 * The document root. On the host, admin.tech4time.bd points at public/ — so
 * lib/, sections/ and content/ are not merely blocked, they are outside the
 * tree a URL can reach. This serves public/ and only public/, so a path that
 * escapes it 404s here exactly as it would 404 there.
 *
 * That is the whole reason this file is not two lines: PHP's built-in server
 * would happily serve the repository root, and a development machine on which
 * /../lib/auth.php resolves is a development machine that teaches the wrong
 * lesson.
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

/* Resolve before comparing. A request for /../lib/auth.php arrives as a path
   with .. in it, and only the resolved form can be checked against the root. */
$target = $root . rawurldecode($path);

if (is_dir($target)) {
    $index = rtrim($target, '/') . '/index.php';
    if (is_file($index)) {
        $target = $index;
    }
}

$real = realpath($target);

if ($real === false || !str_starts_with($real, realpath($root) . '/')) {
    http_response_code(404);
    echo "Not found.\n";
    return true;
}

if (str_ends_with($real, '.php')) {
    require $real;
    return true;
}

/* Anything else: let the built-in server serve the file, or 404 it. */
return false;
