<?php
declare(strict_types=1);

/**
 * CSE 135 HW4 — application bootstrap.
 *
 * Every page under the reporting web root includes this first. It establishes the
 * include guard, configures and starts the session, and loads the small set of
 * classes the app is built from.
 */

// Include guard for everything in this directory. A direct HTTP request to
// /app/Auth.php never defines this, so the file exits instead of executing.
define('CSE135_APP', true);

const DB_CONFIG_PATH = '/etc/cse135/db.ini';

/*
 * Session cookie policy.
 *
 * `domain` is left empty ON PURPOSE, which makes the cookie host-only —
 * reporting.ucsdwrestlingclub.com and nowhere else.
 *
 * This is the opposite of the analytics `sid` cookie, which is deliberately scoped
 * to .ucsdwrestlingclub.com so the collector vhost can see it. Widening the auth
 * session the same way would send an authenticated admin's session id to the test
 * site and the collector on every request — including to log.php, which writes
 * what it receives into a database. Analytics identity is domain-wide by design;
 * authentication identity must not be.
 */
/*
 * Skipped under CLI, where there is no browser to hold a cookie and
 * session_set_cookie_params() would warn. This is what lets the verification
 * harness in src/tools/ load the real application code — the same Metric classes
 * the dashboard runs, not a reimplementation of them — and assert on its output.
 */
if (PHP_SAPI !== 'cli') {
session_name('cse135_reporting');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Csrf.php';
// Before Auth: its guard methods call redirect() and render_error_page().
require_once __DIR__ . '/View/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/MetricRegistry.php';
