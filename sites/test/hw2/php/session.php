<?php
/*
 * Server-side session bootstrap for the PHP state demo.
 *
 * PHP stores session contents in a file on the server (see session.save_path,
 * /var/lib/php/sessions on Ubuntu). The browser only ever holds the opaque
 * session ID, which is what makes this a server-side session rather than
 * localStorage.
 */

if (!defined('HW2_LIB')) {
    define('HW2_LIB', true);
}
require_once __DIR__ . '/lib.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/hw2/',
    'secure'   => true,      // the site is HTTPS-only
    'httponly' => true,      // script cannot read it
    'samesite' => 'Lax',     // not sent on cross-site POSTs
]);
session_name('HW2PHPSESS');
session_start();

/** Per-session CSRF token — the state pages are the only ones that mutate. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: text/plain; charset=utf-8');
        echo "CSRF token mismatch.\n";
        exit;
    }
}

/** @return array<string,string> */
function saved_data(): array
{
    return $_SESSION['data'] ?? [];
}
