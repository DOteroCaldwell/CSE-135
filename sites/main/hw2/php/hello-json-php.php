<?php
define('HW2_LIB', true);
require __DIR__ . '/lib.php';

/* Same payload as hello-html, serialised as JSON instead of markup. */
send_json([
    'greeting'    => 'Hello from ' . TEAM_NAME,
    'language'    => LANG_NAME,
    'version'     => PHP_VERSION,
    'generatedAt' => now_iso(),
    'clientIp'    => client_ip(),
]);
