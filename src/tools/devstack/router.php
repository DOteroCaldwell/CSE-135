<?php
/* Dev router for `php -S`, emulating the reporting vhost.
 * Mirrors: RewriteCond %{REQUEST_FILENAME} !-f ; RewriteRule ^/api(/.*)?$ /api/index.php
 * Also emulates the app/ deny that .htaccess provides under Apache. */
$root = getenv('DOCROOT') ?: __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (preg_match('#^/app(/|$)#', $path)) {   // .htaccess: Require all denied
    if (is_file($root . '/403.php')) { require $root . '/403.php'; return true; }
    http_response_code(403); echo "403 forbidden (app/)"; return true;
}
$file = $root . $path;
if ($path !== '/' && is_file($file)) { return false; }        // real file: serve it
// DirectoryIndex: /saved/ -> /saved/index.php, as Apache does.
if ($path !== '/' && is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    if (!str_ends_with($path, '/')) { header('Location: ' . $path . '/', true, 301); return true; }
    require rtrim($file, '/') . '/index.php'; return true;
}
if (preg_match('#^/api(/.*)?$#', $path)) { require $root . '/api/index.php'; return true; }
if ($path === '/') {
    if (is_file($root . '/index.php')) { require $root . '/index.php'; return true; }
    return false;
}
// ErrorDocument 404 /404.php, as the vhost does.
if (is_file($root . '/404.php')) { require $root . '/404.php'; return true; }
http_response_code(404); echo "404"; return true;
