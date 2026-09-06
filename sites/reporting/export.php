<?php
declare(strict_types=1);

/**
 * CSE 135 HW5 — PDF export of a saved report.
 *
 *   POST id=N   render the saved report to PDF (analyst/admin, in-section)
 *   GET  ?id=N  stream the PDF (anyone who may open that saved report)
 *
 * WHY HEADLESS CHROMIUM
 * The charts are Charts.css: HTML tables drawn with CSS custom properties, grid
 * and flexbox. dompdf and wkhtmltopdf (a 2012 WebKit) render none of that, so
 * either would need a second, chart-less stylesheet kept in step with the first.
 * Chromium prints the page the reader already saw. The cost is a system package
 * on the droplet, which the deploy pipeline cannot install — GRADER.md says so.
 *
 * WHY A FILE, NOT A LIVE URL
 * The renderer runs as www-data with no browser session, so it cannot fetch an
 * authenticated page. Instead the saved report's stored HTML is wrapped in a
 * self-contained document (CSS inlined) and printed from disk: no network, no
 * cookies, no auth to get wrong. The PDF lands OUTSIDE every web root and is
 * streamed by this script, which is what makes the "accessible URL" the spec
 * asks for obey the same section rules as the report itself.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/View/layout.php';

Auth::requireLogin();

const CHROMIUM_CANDIDATES = [
    '/usr/bin/google-chrome-stable', '/usr/bin/google-chrome', '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    'google-chrome-stable', 'google-chrome', 'chromium', 'chromium-browser',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];

function export_dir(): string
{
    $d = (string) (Db::config()['export_dir'] ?? '/var/lib/cse135/exports');
    return rtrim($d, '/');
}

/** Absolute path of a usable renderer, or null. Configured value first. */
function chromium_binary(): ?string
{
    $configured = trim((string) (Db::config()['chromium'] ?? ''));
    $names = $configured !== '' ? array_merge([$configured], CHROMIUM_CANDIDATES) : CHROMIUM_CANDIDATES;
    foreach ($names as $n) {
        if (str_contains($n, '/')) {
            if (is_executable($n)) { return $n; }
            continue;
        }
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($n) . ' 2>/dev/null'));
        if ($found !== '' && is_executable($found)) { return $found; }
    }
    return null;
}

function load_saved(int $id): array
{
    $row = $id > 0 ? Db::one('SELECT * FROM saved_reports WHERE id = ?', [$id]) : null;
    if ($row === null) {
        render_error_page(404, 'No such saved report', 'That saved report does not exist, or has been deleted.');
    }
    if (!Auth::canViewSaved($row['section'])) {
        render_error_page(403, 'Not your section',
            'This saved report belongs to the ' . Sections::label($row['section'])
            . ' section, which this account is not assigned to.');
    }
    return $row;
}

/* ------------------------------------------------------------------ GET ---- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $row = load_saved((int) ($_GET['id'] ?? 0));
    $path = (string) ($row['pdf_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        render_error_page(404, 'No PDF yet',
            'This saved report has not been exported to PDF. An analyst can generate one from the report page.');
    }
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $row['title']) ?: 'report';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . trim($name, '-') . '.pdf"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ----------------------------------------------------------------- POST ---- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: GET, POST');
    render_error_page(405, 'Method not allowed', 'Use GET to download, POST to generate.');
}
Csrf::require();
Auth::requirePublisher();

$row = load_saved((int) ($_POST['id'] ?? 0));
if (!Auth::canViewSection($row['section'])) {
    render_error_page(403, 'Not your section',
        'Generating a PDF for the ' . Sections::label($row['section']) . ' section needs that section.');
}

$bin = chromium_binary();
if ($bin === null) {
    error_log('[cse135/export] no chromium binary found (config: '
        . var_export(Db::config()['chromium'] ?? null, true) . ')');
    render_error_page(503, 'Export unavailable',
        'The PDF renderer is not installed on this server. The saved report itself '
        . 'is still available as a page; ask the administrator to install Chromium.');
}

$dir = export_dir();
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    error_log("[cse135/export] cannot create $dir");
    render_error_page(503, 'Export unavailable', 'The export directory does not exist and could not be created.');
}
if (!is_writable($dir)) {
    error_log("[cse135/export] $dir is not writable by the web server user");
    render_error_page(503, 'Export unavailable', 'The export directory is not writable by the web server.');
}

/* Build the self-contained document. Same CSS the screen version used, inlined. */
$css = (string) @file_get_contents(__DIR__ . '/assets/charts.min.css')
     . "\n" . (string) @file_get_contents(__DIR__ . '/assets/app.css');
$scope = $row['query'] !== '' ? Filters::fromQuery(query_to_array($row['query']))->describe() : 'all data';
$spec  = Reports::get($row['report']);

ob_start();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e($row['title']) ?></title>
<style><?= $css ?></style>
<style>
  @page { size: A4; margin: 14mm 12mm; }
  body { background: #fff; }
  main { max-width: none; padding: 0; }
  .pdf-head { border-bottom: 2px solid #1b1f24; margin-bottom: 18px; padding-bottom: 8px; }
  .pdf-head h1 { margin: 0 0 2px; }
  .pdf-meta { color: #5a6470; font-size: 12px; margin: 0; }
</style>
</head>
<body>
<main class="wide">
  <header class="pdf-head">
    <h1><?= e($row['title']) ?></h1>
    <p class="pdf-meta">
      <?= e(Sections::label($row['section'])) ?> section
      · <?= e($spec['title'] ?? $row['report']) ?>
      · scope: <?= e($scope) ?>
      · saved <?= e(substr((string) $row['created_at'], 0, 16)) ?> UTC by <?= e($row['created_by_name']) ?>
      · exported <?= e(gmdate('Y-m-d H:i')) ?> UTC
    </p>
  </header>
<?php if (!empty($row['note'])): ?>
  <section class="verdict">
    <h2>Analyst comment</h2>
<?php foreach (preg_split('/\R{2,}/', trim((string) $row['note'])) ?: [] as $para): ?>
    <p><?= nl2br(e($para)) ?></p>
<?php endforeach; ?>
  </section>
<?php endif; ?>
<?= $row['html'] ?>
  <footer class="pagefoot" style="display:block">
    <p>CSE 135 Analytics · reporting.ucsdwrestlingclub.com · a fixed view; the live report may have moved on.</p>
  </footer>
</main>
</body>
</html>
<?php
$doc = (string) ob_get_clean();

$id   = (int) $row['id'];
$html = "$dir/report-$id.html";
$pdf  = "$dir/report-$id.pdf";
$tmp  = "$dir/report-$id.pdf.part";
if (@file_put_contents($html, $doc) === false) {
    error_log("[cse135/export] cannot write $html");
    render_error_page(503, 'Export unavailable', 'Could not write the intermediate document.');
}

/*
 * Chromium flags:
 *   --headless=new              current headless mode (old one is deprecated)
 *   --no-sandbox                the user namespace sandbox is unavailable to
 *                               www-data on a stock droplet and inside Docker
 *   --user-data-dir             somewhere writable; www-data has no usable $HOME
 *   --virtual-time-budget       let layout settle before printing
 *   --no-pdf-header-footer      no "file:///..." and date in the page margins
 *   --log-level=3               fatal only. On a headless server Chrome logs a
 *                               dozen harmless "Failed to connect to the bus"
 *                               D-Bus errors per run; without this they would be
 *                               the only thing in the log when something real fails
 * `timeout` bounds a hung renderer; the request must not wait forever.
 */
$profile = sys_get_temp_dir() . '/cse135-chromium';
$cmd = 'timeout 90 ' . escapeshellarg($bin)
     . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
     . ' --log-level=3'
     . ' --user-data-dir=' . escapeshellarg($profile)
     . ' --no-pdf-header-footer --virtual-time-budget=2500'
     . ' --print-to-pdf=' . escapeshellarg($tmp)
     . ' ' . escapeshellarg('file://' . $html) . ' 2>&1';
putenv('HOME=' . $profile);
exec($cmd, $output, $rc);
@unlink($html);

if ($rc !== 0 || !is_file($tmp) || filesize($tmp) < 1000) {
    // Drop the D-Bus chatter so the logged tail is the actual reason.
    $signal = array_values(array_filter($output, static fn($l) => !str_contains($l, 'dbus/')));
    error_log("[cse135/export] chromium rc=$rc: " . implode(' | ', array_slice($signal, -5)));
    @unlink($tmp);
    render_error_page(503, 'Export failed',
        'The PDF renderer did not produce a document. The failure has been logged; the saved report is still available as a page.');
}
rename($tmp, $pdf);
$bytes = (int) filesize($pdf);

Db::conn()->prepare(
    'UPDATE saved_reports SET pdf_path = ?, pdf_bytes = ?, pdf_generated_at = UTC_TIMESTAMP() WHERE id = ?'
)->execute([$pdf, $bytes, $id]);

redirect('/saved/view.php?id=' . $id . '&pdf=1');
