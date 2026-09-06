<?php
declare(strict_types=1);

/**
 * CSE 135 HW5 — the reporting vhost's 404.
 *
 * Wired by `ErrorDocument 404 /404.php` in the Apache vhost and by the dev
 * router. Uses the same standalone error page as the 403s, so a wrong URL on the
 * dashboard looks like part of the dashboard rather than Apache's default.
 */

require_once __DIR__ . '/app/bootstrap.php';

render_error_page(404, 'Page not found',
    'There is nothing at that address on the analytics platform. Check the link, '
    . 'or start again from the dashboard.');
