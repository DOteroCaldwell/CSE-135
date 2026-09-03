<?php
define('HW2_LIB', true);
require __DIR__ . '/lib.php';

/*
 * Dump the CGI environment.
 *
 * Two values are deliberately withheld: this whole site sits behind HTTP basic
 * auth, so HTTP_AUTHORIZATION carries the grader password in every request, and
 * HTTP_COOKIE carries live session identifiers. Printing either would leak a
 * working credential into every screenshot of this page.
 */
const REDACTED = ['HTTP_AUTHORIZATION', 'HTTP_PROXY_AUTHORIZATION', 'HTTP_COOKIE'];

$env = $_SERVER;
ksort($env);

$rows = [];
foreach ($env as $key => $value) {
    if (in_array($key, REDACTED, true)) {
        $rows[$key] = '[redacted — see the note below]';
        continue;
    }
    $rows[$key] = is_scalar($value) ? (string) $value : json_encode($value);
}

page_top('Environment Variables — PHP');
kv_table($rows, 'CGI / server environment as seen by PHP');
?>
    <p class="note">
      <code>HTTP_AUTHORIZATION</code> and <code>HTTP_COOKIE</code> are redacted on
      purpose. The site is protected by HTTP basic auth, so the raw header holds a
      usable credential, and the cookie header holds a live session ID.
    </p>
<?php
page_bottom();
