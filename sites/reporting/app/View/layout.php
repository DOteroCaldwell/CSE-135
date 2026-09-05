<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Page chrome. Every authenticated page opens with layout_header() and closes with
 * layout_footer().
 *
 * $opts:
 *   subtitle  string  one line under the h1
 *   wide      bool    widen the content column (grids with many columns)
 */
function layout_header(string $title, array $opts = []): void
{
    $user  = Auth::user();
    $admin = Auth::isAdmin();
    $here  = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

    $nav = [
        '/'                            => 'Dashboard',
        '/reports/page-load-cost.php'  => 'Load cost report',
    ];
    if ($admin) {
        $nav['/users.php'] = 'Users';
    }

    header('Content-Type: text/html; charset=utf-8');
    // The dashboard reads live data; a cached copy shown to the next person at this
    // browser would be both stale and a disclosure.
    header('Cache-Control: no-store, private');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — CSE 135 Analytics</title>
<link rel="stylesheet" href="/assets/charts.min.css">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/">CSE&nbsp;135 Analytics</a>
    <nav aria-label="Main">
      <ul>
<?php foreach ($nav as $href => $label): ?>
        <li><a href="<?= e($href) ?>"<?= $here === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
<?php endforeach; ?>
      </ul>
    </nav>
<?php if ($user !== null): ?>
    <div class="whoami">
      <span><?= e($user['username']) ?> <span class="role role-<?= e($user['role']) ?>"><?= e(str_replace('_', ' ', $user['role'])) ?></span></span>
      <a class="btn btn-quiet" href="/logout.php">Log out</a>
    </div>
<?php endif; ?>
  </div>
</header>

<main class="<?= !empty($opts['wide']) ? 'wide' : '' ?>">
  <h1><?= e($title) ?></h1>
<?php if (!empty($opts['subtitle'])): ?>
  <p class="subtitle"><?= e($opts['subtitle']) ?></p>
<?php endif; ?>
<?php
}

function layout_footer(): void
{
    ?>
</main>
<footer class="pagefoot">
  <p>CSE 135 · reporting.ucsdwrestlingclub.com · data collected by
     <a href="https://collector.ucsdwrestlingclub.com/collector.js">collector.js</a></p>
</footer>
</body>
</html>
<?php
}

/**
 * Standalone chrome for the pages that exist outside the app shell — login and
 * logout. They must render for someone with no session at all, so they cannot use
 * a header that asks Auth who the user is.
 */
function layout_bare_header(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — CSE 135 Analytics</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bare">
<main class="card-narrow">
<?php
}

function layout_bare_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}
