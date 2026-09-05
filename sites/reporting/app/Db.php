<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Single lazily-opened PDO handle, read from the same /etc/cse135/db.ini the
 * collector and the REST API use. Credentials live outside every document root
 * and are never in the repo.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = @parse_ini_file(DB_CONFIG_PATH);
        if (!is_array($cfg) || !isset($cfg['name'], $cfg['user'], $cfg['pass'])) {
            error_log('[cse135/app] cannot read ' . DB_CONFIG_PATH);
            self::fatal();
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306),
            $cfg['name']
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[cse135/app] db connect failed: ' . $e->getMessage());
            self::fatal();
        }

        return self::$pdo;
    }

    /** Convenience: prepare, execute, fetch all. */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Convenience: prepare, execute, fetch one row (or null). */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Never leaks the reason to the browser; the detail is already in the log. */
    private static function fatal(): never
    {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<title>Service unavailable</title></head><body>'
           . '<h1>Service unavailable</h1>'
           . '<p>The reporting database is not reachable right now.</p>'
           . '</body></html>';
        exit;
    }
}
