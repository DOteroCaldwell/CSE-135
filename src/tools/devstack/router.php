<?php
/* Dev router for `php -S`, emulating the reporting vhost.
 * Mirrors: RewriteCond %{REQUEST_FILENAME} !-f ; RewriteRule ^/api(/.*)?$ /api/index.php
 * Also emulates the app/ deny that .htaccess provides under Apache. */
$root = getenv('DOCROOT') ?: __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (preg_match('#^/app(/|$)#', $path)) {   // .htaccess: Require all denied
    http_response_code(403); echo "403 forbidden (app/)"; return true;
}
$file = $root . $path;
if ($path !== '/' && is_file($file)) { return false; }        // real file: serve it
if (preg_match('#^/api(/.*)?$#', $path)) { require $root . '/api/index.php'; return true; }
if ($path === '/') {
    if (is_file($root . '/index.php')) { require $root . '/index.php'; return true; }
    return false;
}
http_response_code(404); echo "404"; return true;
