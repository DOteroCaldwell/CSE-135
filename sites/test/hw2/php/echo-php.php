<?php
define('HW2_LIB', true);
require __DIR__ . '/lib.php';

/*
 * Echo whatever was sent, over any method and either encoding.
 *
 * $_GET is populated for us. $_POST is not enough: it only fills for
 * POST + x-www-form-urlencoded, so PUT, DELETE and every JSON body get read
 * off php://input and parsed in parsed_body().
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$type   = $_SERVER['CONTENT_TYPE'] ?? '(none)';
[$bodyFields, $rawBody] = parsed_body();

$wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

$request = [
    'method'       => $method,
    'contentType'  => $type,
    'queryString'  => $_SERVER['QUERY_STRING'] ?? '',
    'hostname'     => server_hostname(),
    'generatedAt'  => now_iso(),
    'userAgent'    => user_agent(),
    'clientIp'     => client_ip(),
];

if ($wantsJson) {
    // Cast to object so an empty set encodes as {} rather than PHP's [],
    // matching what the Go and Python endpoints return.
    send_json($request + [
        'queryFields' => (object) $_GET,
        'bodyFields'  => (object) $bodyFields,
        'rawBody'     => $rawBody,
    ]);
    exit;
}

page_top('Echo — PHP');
kv_table($request, 'Request metadata');
kv_table($_GET, 'Query-string fields');
kv_table($bodyFields, 'Parsed body fields');
?>
    <h2>Raw request body</h2>
    <pre class="out"><?= h($rawBody === '' ? '(empty)' : $rawBody) ?></pre>

    <p class="note">
      All echoed values are HTML-escaped before output. Submitting
      <code>&lt;script&gt;</code> through the form renders it as visible text
      rather than executing it.
    </p>

    <p><a href="/hw2/echo-form.html">&larr; Back to the echo form</a></p>
<?php
page_bottom();
