<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Synchroniser-token CSRF protection for every state-changing form.
 *
 * One token per session rather than one per form: simpler, and sufficient here
 * because the token is never placed in a URL (only in POST bodies), so it cannot
 * leak through Referer or browser history.
 */
final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    /** Hidden input to drop inside any <form method="post">. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::KEY . '" value="'
             . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function valid(): bool
    {
        $sent = $_POST[self::KEY] ?? '';
        $have = $_SESSION[self::KEY] ?? '';
        // hash_equals: constant time, so a token cannot be recovered byte by byte
        // from response-time differences.
        return is_string($sent) && is_string($have) && $have !== ''
            && hash_equals($have, $sent);
    }

    /** Guard for the top of any POST handler. */
    public static function require(): void
    {
        if (!self::valid()) {
            http_response_code(400);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
               . '<title>Bad request</title></head><body>'
               . '<h1>Bad request</h1>'
               . '<p>That form has expired. Please go back and try again.</p>'
               . '</body></html>';
            exit;
        }
    }
}
