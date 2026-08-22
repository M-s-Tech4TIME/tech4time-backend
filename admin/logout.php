<?php
/**
 * Tech4TIME — signing out.
 *
 * POST only, with a token. A link that ends a session can be fired by any
 * <img src> on any page the browser loads, which turns signing people out into
 * something any website can do to them.
 */

declare(strict_types=1);

define('T4T_ADMIN', true);

require __DIR__ . '/../lib/admin.php';

admin_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Location: ' . ADMIN_BASE);
    exit;
}

admin_check_csrf();
auth_logout();

header('Location: ' . ADMIN_BASE . 'login.php?signed-out=1');
exit;
