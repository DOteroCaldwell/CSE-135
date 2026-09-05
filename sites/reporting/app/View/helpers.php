<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/** Escape for HTML text and quoted attribute contexts. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/**
 * Full standalone error page.
 *
 * Standalone rather than wrapped in the app layout on purpose: these fire in
 * states where the layout's assumptions may not hold (no session, no database, no
 * authenticated user to render a nav bar for). An error page that itself errors is
 * a bad day.
 */
function render_error_page(int $code, string $title, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="error-page">
<main>
  <p class="error-code"><?= e((string) $code) ?></p>
  <h1><?= e($title) ?></h1>
  <p><?= e($message) ?></p>
  <p><a href="/">Back to the dashboard</a></p>
</main>
</body>
</html>
<?php
    exit;
}

/* ------------------------------------------------------------- formatting -- */

/** Milliseconds, scaled so a table of them stays readable. */
function fmt_ms(?float $ms): string
{
    if ($ms === null) {
        return '—';
    }
    if ($ms >= 120000) {
        return number_format($ms / 60000, 1) . ' min';
    }
    if ($ms >= 10000) {
        return number_format($ms / 1000, 1) . ' s';
    }
    if ($ms >= 100) {
        return number_format($ms) . ' ms';
    }
    return number_format($ms, 1) . ' ms';
}

function fmt_bytes(?float $b): string
{
    if ($b === null) {
        return '—';
    }
    if ($b >= 1048576) {
        return number_format($b / 1048576, 1) . ' MB';
    }
    if ($b >= 1024) {
        return number_format($b / 1024) . ' KB';
    }
    return number_format($b) . ' B';
}

function fmt_pct(?float $frac): string
{
    return $frac === null ? '—' : number_format($frac * 100, 1) . '%';
}

function fmt_int(?float $n): string
{
    return $n === null ? '—' : number_format($n);
}
