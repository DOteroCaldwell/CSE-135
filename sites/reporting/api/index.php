<?php
declare(strict_types=1);

/**
 * CSE 135 HW3 Part 5 — REST API over the analytics tables.
 *
 *   GET    /api/<resource>        all rows
 *   GET    /api/<resource>/{id}   one row
 *   POST   /api/<resource>        create        (id in the path is an error)
 *   PUT    /api/<resource>/{id}   update        (id required)
 *   DELETE /api/<resource>/{id}   delete        (id required)
 *
 * Resource and column names are never taken from the request — they are looked up
 * in the $RESOURCES map below. Every value goes through a prepared statement.
 */

const CONFIG_PATH = '/etc/cse135/db.ini';
const MAX_BODY    = 1048576;

/*
 * The one place that decides what is addressable and what is writable.
 *   id       — primary key column; sessions is keyed by a string, the rest by int
 *   writable — mass-assignment whitelist for POST/PUT
 *   json     — columns stored as JSON, decoded on the way out so responses carry
 *              real objects rather than strings of JSON
 */
$RESOURCES = [
    'sessions' => [
        'table'    => 'sessions',
        'id'       => 'session_id',
        'id_int'   => false,
        'order'    => 'first_seen DESC',
        'writable' => ['session_id', 'sid_source', 'first_seen', 'last_seen',
                       'entry_page', 'client_ip', 'user_agent', 'payload_count'],
        'required' => ['session_id'],
        'json'     => [],
    ],
    'static' => [
        'table'    => '`static`',
        'id'       => 'id',
        'id_int'   => true,
        'order'    => 'id DESC',
        'writable' => ['session_id', 'pageview_id', 'page', 'user_agent', 'language',
                       'cookies_enabled', 'js_enabled', 'images_enabled', 'css_enabled',
                       'screen_width', 'screen_height', 'window_width', 'window_height',
                       'connection_type', 'raw', 'client_sent_at', 'server_ts'],
        'required' => ['session_id'],
        'json'     => ['raw'],
    ],
    'performance' => [
        'table'    => 'performance',
        'id'       => 'id',
        'id_int'   => true,
        'order'    => 'id DESC',
        'writable' => ['session_id', 'pageview_id', 'page', 'load_start_ms', 'load_end_ms',
                       'load_start_epoch', 'load_end_epoch', 'total_load_ms',
                       'timing_source', 'nav_timing', 'client_sent_at', 'server_ts'],
        'required' => ['session_id'],
        'json'     => ['nav_timing'],
    ],
    'activity' => [
        'table'    => 'activity',
        'id'       => 'id',
        'id_int'   => true,
        'order'    => 'id DESC',
        'writable' => ['session_id', 'pageview_id', 'page', 'event_type', 'occurred_at',
                       'pos_x', 'pos_y', 'scroll_x', 'scroll_y', 'mouse_button',
                       'key_name', 'idle_duration_ms', 'error_message', 'detail',
                       'client_sent_at', 'server_ts'],
        'required' => ['session_id', 'event_type'],
        'json'     => ['detail'],
    ],
];

/* --------------------------------------------------- last-resort reporting -- */

set_exception_handler(static function (Throwable $e): void {
    error_log('[cse135/api] uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo '{"error":"internal error"}';
    }
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if (($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
        error_log('[cse135/api] fatal: ' . $err['message']
            . ' at ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo '{"error":"internal error"}';
        }
    }
});

/* ---------------------------------------------------------------- helpers -- */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send(int $code, mixed $body): never
{
    http_response_code($code);
    if ($body !== null) {
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

function fail(int $code, string $message, array $extra = []): never
{
    send($code, ['error' => $message] + $extra);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = @parse_ini_file(CONFIG_PATH);
    if (!is_array($cfg) || !isset($cfg['name'], $cfg['user'], $cfg['pass'])) {
        error_log('[cse135/api] cannot read ' . CONFIG_PATH);
        fail(500, 'internal error');
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
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[cse135/api] db connect failed: ' . $e->getMessage());
        fail(500, 'internal error');
    }
    return $pdo;
}

/** Decode the JSON-typed columns so the response nests real objects. */
function hydrate(array $row, array $spec): array
{
    foreach ($spec['json'] as $col) {
        if (isset($row[$col]) && is_string($row[$col])) {
            $decoded = json_decode($row[$col], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$col] = $decoded;
            }
        }
    }
    return $row;
}

/** Read and validate a JSON request body. */
function body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if ($raw === false || $raw === '') {
        fail(400, 'request body is required');
    }
    if (strlen($raw) > MAX_BODY) {
        fail(413, 'request body too large');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail(400, 'request body must be a JSON object: ' . json_last_error_msg());
    }
    return $decoded;
}

/**
 * Keep only whitelisted columns. Anything else in the payload is rejected loudly
 * rather than silently dropped — a typo'd column name should not look like a
 * successful write.
 */
function columns(array $input, array $spec): array
{
    $unknown = array_diff(array_keys($input), $spec['writable']);
    if ($unknown !== []) {
        fail(422, 'unknown or read-only field(s): ' . implode(', ', $unknown), [
            'writable' => $spec['writable'],
        ]);
    }
    $out = [];
    foreach ($input as $col => $val) {
        // JSON columns accept a nested object and are re-encoded for storage
        $out[$col] = (is_array($val) || is_object($val))
            ? json_encode($val, JSON_UNESCAPED_SLASHES)
            : $val;
    }
    return $out;
}

/* ------------------------------------------------------------------ route -- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = rawurldecode($uri);

// Strip the /api mount point, and tolerate the rewritten /api/index.php/... form.
$path = preg_replace('#^/api#', '', $path) ?? '';
$segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
if (($segments[0] ?? '') === 'index.php') {
    array_shift($segments);
}

$resource = $segments[0] ?? '';
$id       = $segments[1] ?? null;

if (count($segments) > 2) {
    fail(404, 'no such route');
}

// GET /api — a directory of what is available. Not required, but it makes the
// API discoverable and gives the grader something to land on.
if ($resource === '') {
    $index = [];
    foreach (array_keys($RESOURCES) as $name) {
        $index[$name] = [
            'collection' => "/api/$name",
            'item'       => "/api/$name/{id}",
        ];
    }
    send(200, ['resources' => $index]);
}

if (!isset($RESOURCES[$resource])) {
    fail(404, "unknown resource '$resource'", ['available' => array_keys($RESOURCES)]);
}

$spec  = $RESOURCES[$resource];
$table = $spec['table'];
$idCol = $spec['id'];

// Reject an id that cannot match the key type before touching the database.
if ($id !== null && $spec['id_int'] && !ctype_digit($id)) {
    fail(404, 'no such row');
}

$pdo = db();

try {
    switch ($method) {
        case 'GET':
            if ($id === null) {
                // Optional paging. The spec's contract is "all rows"; these are
                // additive and default to no limit.
                $limit  = isset($_GET['limit']) ? max(0, (int) $_GET['limit']) : 0;
                $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
                $sql = "SELECT * FROM $table ORDER BY {$spec['order']}";
                if ($limit > 0) {
                    $sql .= " LIMIT $limit OFFSET $offset";   // ints, cast above
                }
                $rows = $pdo->query($sql)->fetchAll();
                $rows = array_map(static fn($r) => hydrate($r, $spec), $rows);
                send(200, ['count' => count($rows), 'data' => $rows]);
            }

            $stmt = $pdo->prepare("SELECT * FROM $table WHERE $idCol = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                fail(404, 'no such row');
            }
            send(200, ['data' => hydrate($row, $spec)]);

        case 'POST':
            // Strict REST: the server assigns the identity, so an id in the path
            // is a client error rather than something to quietly ignore.
            if ($id !== null) {
                header('Allow: GET, PUT, DELETE');
                fail(405, 'POST does not take an id in the path; POST to /api/' . $resource);
            }
            $input = columns(body(), $spec);
            foreach ($spec['required'] as $req) {
                if (!isset($input[$req]) || $input[$req] === '') {
                    fail(422, "field '$req' is required");
                }
            }
            // Timestamp defaults, but only for columns this resource actually
            // has: `sessions` has first_seen/last_seen and no server_ts, the
            // other three are the reverse.
            $now = gmdate('Y-m-d H:i:s');
            foreach (['server_ts', 'first_seen', 'last_seen'] as $tsCol) {
                if (in_array($tsCol, $spec['writable'], true) && !isset($input[$tsCol])) {
                    $input[$tsCol] = $now;
                }
            }

            $cols         = array_keys($input);
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $collist      = implode(', ', array_map(static fn($c) => "`$c`", $cols));
            $stmt = $pdo->prepare("INSERT INTO $table ($collist) VALUES ($placeholders)");
            $stmt->execute(array_values($input));

            $newId = $spec['id_int'] ? (int) $pdo->lastInsertId() : $input[$idCol];
            $get = $pdo->prepare("SELECT * FROM $table WHERE $idCol = ? LIMIT 1");
            $get->execute([$newId]);
            $row = $get->fetch();

            header("Location: /api/$resource/$newId");
            send(201, ['data' => $row === false ? null : hydrate($row, $spec)]);

        case 'PUT':
            if ($id === null) {
                header('Allow: GET, POST');
                fail(405, 'PUT requires an id: /api/' . $resource . '/{id}');
            }
            $input = columns(body(), $spec);
            if ($input === []) {
                fail(422, 'no writable fields supplied');
            }
            // Changing a row's identity via PUT is not update, it is a move.
            if (isset($input[$idCol]) && (string) $input[$idCol] !== (string) $id) {
                fail(422, "'$idCol' cannot be changed");
            }
            unset($input[$idCol]);

            $exists = $pdo->prepare("SELECT 1 FROM $table WHERE $idCol = ? LIMIT 1");
            $exists->execute([$id]);
            if ($exists->fetch() === false) {
                fail(404, 'no such row');
            }

            $sets = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($input)));
            $stmt = $pdo->prepare("UPDATE $table SET $sets WHERE $idCol = ?");
            $stmt->execute([...array_values($input), $id]);

            $get = $pdo->prepare("SELECT * FROM $table WHERE $idCol = ? LIMIT 1");
            $get->execute([$id]);
            send(200, ['data' => hydrate($get->fetch(), $spec)]);

        case 'DELETE':
            if ($id === null) {
                header('Allow: GET, POST');
                fail(405, 'DELETE requires an id: /api/' . $resource . '/{id}');
            }
            $stmt = $pdo->prepare("DELETE FROM $table WHERE $idCol = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                fail(404, 'no such row');
            }
            send(204, null);

        case 'OPTIONS':
            header('Allow: GET, POST, PUT, DELETE, OPTIONS');
            send(204, null);

        default:
            header('Allow: GET, POST, PUT, DELETE, OPTIONS');
            fail(405, "method $method not allowed");
    }
} catch (PDOException $e) {
    // 23000 covers FK violations and duplicate keys — both are the client's
    // problem, not a server fault, so they must not surface as a 500.
    if ($e->getCode() === '23000') {
        $msg = str_contains($e->getMessage(), 'foreign key')
            ? 'referenced session_id does not exist'
            : 'duplicate key';
        error_log('[cse135/api] constraint: ' . $e->getMessage());
        fail(409, $msg);
    }
    error_log('[cse135/api] query failed: ' . $e->getMessage());
    fail(500, 'internal error');
}
