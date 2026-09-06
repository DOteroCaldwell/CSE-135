<?php
declare(strict_types=1);

/**
 * CSE 135 HW5 — one saved report.
 *
 * Renders the HTML captured at save time inside the normal chrome. Nothing here
 * touches the analytics tables: the body is a stored string, which is exactly what
 * makes it safe to show a viewer and stable enough to export.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/View/layout.php';

Auth::requireLogin();

$id  = (int) ($_GET['id'] ?? 0);
$row = $id > 0 ? Db::one('SELECT * FROM saved_reports WHERE id = ?', [$id]) : null;
if ($row === null) {
    render_error_page(404, 'No such saved report', 'That saved report does not exist, or has been deleted.');
}
if (!Auth::canViewSaved($row['section'])) {
    render_error_page(403, 'Not your section',
        'This saved report belongs to the ' . Sections::label($row['section'])
        . ' section, which this account is not assigned to.');
}

$spec = Reports::get($row['report']);

layout_header($row['title'], [
    'subtitle' => 'Saved ' . substr((string) $row['created_at'], 0, 16) . ' UTC by '
                . $row['created_by_name'] . ' · ' . Sections::label($row['section']) . ' section',
    'wide'     => true,
    'section'  => $row['section'],
]);
?>
<p class="report-scope">
  <a href="/saved/">← All saved reports</a>
<?php if ($spec !== null && Auth::canViewSection($row['section'])): ?>
  &nbsp;·&nbsp; <a href="<?= e($spec['path'] . ($row['query'] !== '' ? '?' . $row['query'] : '')) ?>">Open the live version of this view</a>
<?php endif; ?>
<?php if (!empty($row['pdf_path'])): ?>
  &nbsp;·&nbsp; <a href="/export.php?id=<?= e((string) $row['id']) ?>">Download PDF</a>
  <span class="card-question" style="margin:0">(<?= e(fmt_bytes((float) $row['pdf_bytes'])) ?>)</span>
<?php endif; ?>
</p>

<?php if (Auth::canPublish() && Auth::canViewSection($row['section'])): ?>
<form method="post" action="/export.php" class="export-form">
  <?= Csrf::field() ?>
  <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
  <button class="btn btn-quiet" type="submit"><?= empty($row['pdf_path']) ? 'Generate PDF' : 'Regenerate PDF' ?></button>
</form>
<?php endif; ?>

<?php if (isset($_GET['pdf']) && !empty($row['pdf_path'])): ?>
<p class="notice notice-ok">PDF generated (<?= e(fmt_bytes((float) $row['pdf_bytes'])) ?>).
   <a href="/export.php?id=<?= e((string) $row['id']) ?>">Open it</a> — the same link is on the saved reports list for every role that can see this report.</p>
<?php endif; ?>

<?php if (!empty($row['note'])): ?>
<section class="verdict">
  <h2>Analyst comment</h2>
  <?php foreach (preg_split('/\R{2,}/', trim((string) $row['note'])) ?: [] as $para): ?>
  <p><?= nl2br(e($para)) ?></p>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<p class="notice notice-ok">
  Snapshot. These figures are as of <?= e(substr((string) $row['created_at'], 0, 16)) ?> UTC
  and will not change; scope at save time: <?= e($row['query'] !== '' ? Filters::fromQuery(query_to_array($row['query']))->describe() : 'all data') ?>.
</p>

<?= $row['html'] /* trusted: rendered by our own report code at save time */ ?>

<?php layout_footer(); ?>
