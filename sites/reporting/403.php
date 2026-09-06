<?php
declare(strict_types=1);

/**
 * CSE 135 HW5 — Apache-level 403 (e.g. a request into /app/, which is denied by
 * the vhost and by app/.htaccess). The application's own 403s are rendered by
 * Auth::requireSection() and friends with a reason; this one covers the cases
 * that never reach the application.
 */

require_once __DIR__ . '/app/bootstrap.php';

render_error_page(403, 'Forbidden',
    'That path is not served to browsers. It holds application code, not pages.');
