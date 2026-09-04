<?php
declare(strict_types=1);

/**
 * CSE 135 HW3 Part 4 — ingestion endpoint.
 *
 * collector.js POSTs here (as /log, rewritten to /log.php by the vhost) with a
 * JSON body declaring one of three types: static, performance, activity.
 *
 * The body arrives as text/plain, not application/json. That is deliberate on the
 * client side: sendBeacon with a CORS-safelisted content type is a no-cors request,
 * so it needs no preflight and no CORS response headers. We parse it as JSON
 * regardless of what the Content-Type claims.
 */

const ALLOWED_ORIGINS = [
    'https://test.ucsdwrestlingclub.com',
    'https://ucsdwrestlingclub.com',
];
const CONFIG_PATH = '/etc/cse135/db.ini';
const MAX_BODY    = 1048576; // 1 MB
const VALID_TYPES = ['static', 'performance', 'activity'];

/* --------------------------------------------------- last-resort reporting -- */

/*
 * Without these, a fatal (a missing extension, say) returns a bare 500 with
 * nothing identifiable in the error log, which is painful to diagnose remotely.
 */
set_exception_handler(static function (Throwable $e): void {
    error_log('[cse135/log] uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if (($err['type'] & $fatal) !== 0) {
        error_log('[cse135/log] fatal: ' . $err['message']
            . ' at ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            http_response_code(500);
        }
    }
});

/* ------------------------------------------------------------------- CORS -- */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Vary: Origin');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Only reached by the fetch() fallback, never by sendBeacon.
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    exit;
}

/* --------------------------------------------------------------- helpers -- */

function fail(int $code, string $why): never
{
    error_log('[cse135/log] ' . $why);
    http_response_code($code);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = @parse_ini_file(CONFIG_PATH);
    if (!is_array($cfg) || !isset($cfg['name'], $cfg['user'], $cfg['pass'])) {
        fail(500, 'cannot read ' . CONFIG_PATH);
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'] ?? '127.0.0.1',
        (int) ($cfg['port'] ?? 3306),
        $cfg['name']
    );
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // real prepared statements, not client-side interpolation
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        fail(500, 'db connect failed: ' . $e->getMessage());
    }
    return $pdo;
}

/**
 * Truncate to a column width without splitting a multibyte character.
 *
 * mbstring is a separate package on Ubuntu (php-mbstring) and is not always
 * installed, so fall back to a byte-wise trim that still refuses to leave a
 * half-written UTF-8 sequence behind. Takes mixed because a malformed payload
 * should be ignored, not fatal.
 */
function clip(mixed $s, int $max): ?string
{
    if ($s === null || !is_scalar($s)) {
        return null;
    }
    $s = (string) $s;
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    if (strlen($s) <= $max) {
        return $s;
    }
    $cut = substr($s, 0, $max);
    // drop a trailing partial multibyte sequence
    while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0xC0) === 0x80) {
        $cut = substr($cut, 0, -1);
    }
    return rtrim($cut, "\xC0..\xFF");
}

function asBool(mixed $v): ?int
{
    return $v === null ? null : (int) (bool) $v;
}

function asInt(mixed $v): ?int
{
    return is_numeric($v) ? (int) $v : null;
}

function jsonCol(mixed $v): ?string
{
    if ($v === null) {
        return null;
    }
    $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return $s === false ? null : $s;
}

/* ------------------------------------------------------------ read + parse -- */

$raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
if ($raw === false || $raw === '') {
    fail(400, 'empty body');
}
if (strlen($raw) > MAX_BODY) {
    fail(413, 'body over ' . MAX_BODY . ' bytes');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    fail(400, 'body is not JSON: ' . json_last_error_msg());
}

$type = (string) ($payload['type'] ?? '');
if (!in_array($type, VALID_TYPES, true)) {
    fail(400, 'unknown type ' . var_export($type, true));
}

// The payload's sid is what the page actually saw. The cookie is the fallback,
// and is normally identical since mod_usertrack set both.
$sid = trim((string) ($payload['sid'] ?? ''));
if ($sid === '') {
    $sid = trim((string) ($_COOKIE['sid'] ?? ''));
}
if ($sid === '') {
    fail(400, 'no session id');
}
$sid = clip($sid, 64);

$pvid      = clip((string) ($payload['pvid'] ?? ''), 64) ?: null;
$page      = clip((string) ($payload['page'] ?? ''), 512) ?: null;
$sentAt    = asInt($payload['sentAt'] ?? null);
$sidSource = clip((string) ($payload['sidSource'] ?? ''), 32) ?: null;
$data      = is_array($payload['data'] ?? null) ? $payload['data'] : [];

$now = gmdate('Y-m-d H:i:s');
$ip  = clip((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 45) ?: null;
$ua  = clip((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 512) ?: null;

/* -------------------------------------------------------------- persist --- */

$pdo = db();

try {
    $pdo->beginTransaction();

    // The session row must exist before anything referencing it. first_seen and
    // entry_page are only set on the first payload of the session; every later
    // one just moves last_seen forward.
    $pdo->prepare(
        'INSERT INTO sessions
             (session_id, sid_source, first_seen, last_seen, entry_page, client_ip,
              user_agent, payload_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             last_seen     = VALUES(last_seen),
             payload_count = payload_count + 1,
             sid_source    = COALESCE(sessions.sid_source, VALUES(sid_source))'
    )->execute([$sid, $sidSource, $now, $now, $page, $ip, $ua]);

    if ($type === 'static') {
        $conn = is_array($data['connection'] ?? null) ? $data['connection'] : [];
        $pdo->prepare(
            'INSERT INTO `static`
                 (session_id, pageview_id, page, user_agent, language,
                  cookies_enabled, js_enabled, images_enabled, css_enabled,
                  screen_width, screen_height, window_width, window_height,
                  connection_type, raw, client_sent_at, server_ts)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $sid,
            $pvid,
            $page,
            clip($data['userAgent'] ?? null, 4000),
            clip($data['language'] ?? null, 64),
            asBool($data['cookies']['roundTrip'] ?? null),
            asBool($data['javascript'] ?? null),
            asBool($data['images']['supported'] ?? null),
            // CSS counts as working only if the browser applied our own <style>
            // AND a linked sheet produced rules.
            asBool((($data['css']['inline'] ?? false) && ($data['css']['external'] ?? false))),
            asInt($data['screen']['width'] ?? null),
            asInt($data['screen']['height'] ?? null),
            asInt($data['window']['innerWidth'] ?? null),
            asInt($data['window']['innerHeight'] ?? null),
            clip($conn['effectiveType'] ?? null, 32),
            jsonCol($data),
            $sentAt,
            $now,
        ]);
    } elseif ($type === 'performance') {
        $pdo->prepare(
            'INSERT INTO performance
                 (session_id, pageview_id, page, load_start_ms, load_end_ms,
                  load_start_epoch, load_end_epoch, total_load_ms, timing_source,
                  nav_timing, client_sent_at, server_ts)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $sid,
            $pvid,
            $page,
            is_numeric($data['loadStartMs'] ?? null) ? (float) $data['loadStartMs'] : null,
            is_numeric($data['loadEndMs'] ?? null) ? (float) $data['loadEndMs'] : null,
            asInt($data['loadStartEpoch'] ?? null),
            asInt($data['loadEndEpoch'] ?? null),
            asInt($data['totalLoadMs'] ?? null),
            clip($data['source'] ?? null, 48),
            jsonCol($data['navigationTiming'] ?? $data['legacyTiming'] ?? null),
            $sentAt,
            $now,
        ]);
    } else { // activity — a batch of events in one payload
        $events = is_array($data['events'] ?? null) ? $data['events'] : [];
        if ($events === []) {
            $pdo->commit();
            http_response_code(204);
            exit;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO activity
                 (session_id, pageview_id, page, event_type, occurred_at,
                  pos_x, pos_y, scroll_x, scroll_y, mouse_button, key_name,
                  idle_duration_ms, error_message, detail, client_sent_at, server_ts)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $d = is_array($ev['detail'] ?? null) ? $ev['detail'] : [];
            $stmt->execute([
                $sid,
                $pvid,
                clip((string) ($ev['page'] ?? $page ?? ''), 512) ?: null,
                clip((string) ($ev['event'] ?? 'unknown'), 32),
                asInt($ev['at'] ?? null),
                asInt($d['x'] ?? null),
                asInt($d['y'] ?? null),
                asInt($d['scrollX'] ?? null),
                asInt($d['scrollY'] ?? null),
                asInt($d['button'] ?? null),
                clip($d['key'] ?? null, 32),
                asInt($d['durationMs'] ?? null),
                clip($d['message'] ?? null, 1000),
                jsonCol($d),
                $sentAt,
                $now,
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Never leak SQL or config detail to the caller; it goes to the error log.
    fail(500, $type . ' insert failed: ' . $e->getMessage());
}

// Nothing to say, and the beacon ignores the body anyway.
http_response_code(204);
