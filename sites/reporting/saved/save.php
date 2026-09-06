<?php
declare(strict_types=1);

/**
 * CSE 135 HW5 — publish the current view of a report.
 *
 * POST only. Renders the report body for the submitted filters through exactly the
 * code the live page uses, stores the result, and lands on the saved copy.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/View/layout.php';

Auth::requirePublisher();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    render_error_page(405, 'Method not allowed', 'Saving a report is a POST from a report page.');
}
Csrf::require();

$slug  = (string) ($_POST['report'] ?? '');
$spec  = Reports::get($slug);
$title = trim((string) ($_POST['title'] ?? ''));
$note  = trim((string) ($_POST['note'] ?? ''));
$query = (string) ($_POST['query'] ?? '');

if ($spec === null) {
    render_error_page(404, 'No such report', 'That report does not exist.');
}
Auth::requireSection($spec['section']);

if ($title === '' || mb_strlen($title) > 160) {
    render_error_page(400, 'Title required', 'Give the saved report a title of up to 160 characters.');
}
if (mb_strlen($note) > 4000) {
    $note = mb_substr($note, 0, 4000);
}

// Rebuild the filters from the query string the form carried, so what is saved
// is the view the analyst was looking at — not whatever the defaults are.
$f    = Filters::fromQuery(query_to_array($query));
$html = Reports::render($slug, $f);

$me = Auth::user();
Db::conn()->prepare(
    'INSERT INTO saved_reports
        (title, section, report, query, note, html, created_by, created_by_name, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
)->execute([
    $title, $spec['section'], $slug, ltrim($f->toQuery(), '?'),
    $note === '' ? null : $note, $html, (int) $me['id'], (string) $me['username'],
]);

redirect('/saved/view.php?id=' . (int) Db::conn()->lastInsertId());
