<?php
declare(strict_types=1);

/**
 * CSE 135 HW5 — saved reports.
 *
 * The one page every role can open. For a viewer it is the whole application:
 * the spec defines a viewer as someone who "can only look at saved reports". For
 * analysts and admins it is the list of what has been published, with the
 * section filter their own scope implies.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/View/layout.php';

Auth::requireLogin();

$me = Auth::user();

/* -------------------------------------------------------------- delete ----- */
$errors = [];
$ok = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Csrf::require();
    Auth::requirePublisher();
    $id  = (int) ($_POST['id'] ?? 0);
    $row = Db::one('SELECT id, section, pdf_path FROM saved_reports WHERE id = ?', [$id]);
    if ($row === null) {
        $errors[] = 'That saved report no longer exists.';
    } elseif (!Auth::canViewSection($row['section'])) {
        $errors[] = 'That report belongs to a section this account is not assigned to.';
    } else {
        Db::conn()->prepare('DELETE FROM saved_reports WHERE id = ?')->execute([$id]);
        // The PDF lives outside the web root; remove it with its row.
        if (!empty($row['pdf_path']) && is_file($row['pdf_path'])) {
            @unlink($row['pdf_path']);
        }
        $ok = 'Saved report deleted.';
    }
}

$confirming = null;
if (isset($_GET['delete']) && Auth::canPublish()) {
    $confirming = Db::one('SELECT id, title, section, created_by_name, created_at
                             FROM saved_reports WHERE id = ?', [(int) $_GET['delete']]);
}

/* -------------------------------------------------------------- listing ---- */

$rows = Db::all(
    'SELECT id, title, section, report, query, note, pdf_path, pdf_generated_at,
            created_by_name, created_at, LENGTH(html) AS html_bytes
       FROM saved_reports ORDER BY created_at DESC, id DESC'
);
// Viewers see everything published; analysts see their sections; admins all.
$rows = array_values(array_filter($rows, static fn($r) => Auth::canViewSaved($r['section'])));

layout_header('Saved reports', [
    'subtitle' => Auth::isViewer()
        ? 'Reports an analyst has published. Each one is a fixed view of the data as it stood when it was saved.'
        : 'Fixed views published from the live reports. Viewers see this list and nothing else.',
    'wide'     => true,
]);

foreach ($errors as $err) { echo '<p class="notice notice-error">' . e($err) . '</p>'; }
if ($ok !== null)         { echo '<p class="notice notice-ok">' . e($ok) . '</p>'; }

if ($confirming !== null):
?>
<section class="card">
  <h2>Delete this saved report?</h2>
  <p>You are about to permanently delete <strong><?= e($confirming['title']) ?></strong>
     (<?= e(Sections::label($confirming['section'])) ?>, saved by <?= e($confirming['created_by_name']) ?>
     on <?= e(substr((string) $confirming['created_at'], 0, 10)) ?>). Its PDF, if one was
     generated, is deleted with it. This cannot be undone.</p>
  <form method="post" action="/saved/" style="display:flex;gap:10px;align-items:center">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= e((string) $confirming['id']) ?>">
    <button class="btn btn-danger" type="submit">Yes, delete</button>
    <a class="btn btn-quiet" href="/saved/">Cancel</a>
  </form>
</section>
<?php endif; ?>

<?php if ($rows === []): ?>
<section class="card">
  <h2>Nothing saved yet</h2>
  <p class="card-question">
<?php if (Auth::canPublish()): ?>
    Open a live report, set the filters you want, and use <em>Save this view as a
    report</em> at the top of the page.
<?php else: ?>
    No analyst has published a report yet. There is nothing for a viewer account to
    open until one does.
<?php endif; ?>
  </p>
</section>
<?php else: ?>
<section class="card">
  <h2>All saved reports</h2>
  <p class="card-question"><?= count($rows) ?> report<?= count($rows) === 1 ? '' : 's' ?>.
     A saved report never changes after it is saved; the live report it came from does.</p>
  <div class="scroll-x">
  <table class="data">
    <thead><tr>
      <th>Title</th><th>Section</th><th>Saved by</th><th>Saved</th><th>PDF</th><th>Actions</th>
    </tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td class="wrap"><a href="/saved/view.php?id=<?= e((string) $r['id']) ?>"><?= e($r['title']) ?></a>
<?php if (!empty($r['note'])): ?>
          <br><span class="card-question" style="margin:0"><?= e(mb_strimwidth((string) $r['note'], 0, 110, '…')) ?></span>
<?php endif; ?>
        </td>
        <td><span class="role"><?= e(Sections::label($r['section'])) ?></span></td>
        <td><?= e($r['created_by_name']) ?></td>
        <td><?= e(substr((string) $r['created_at'], 0, 16)) ?></td>
        <td>
<?php if (!empty($r['pdf_path'])): ?>
          <a class="btn btn-quiet btn-small" href="/export.php?id=<?= e((string) $r['id']) ?>">Download</a>
<?php else: ?>
          <span class="card-question" style="margin:0">not yet</span>
<?php endif; ?>
        </td>
        <td>
          <a class="btn btn-quiet btn-small" href="/saved/view.php?id=<?= e((string) $r['id']) ?>">Open</a>
<?php if (Auth::canPublish() && Auth::canViewSection($r['section'])): ?>
          <a class="btn btn-quiet btn-small" href="/saved/?delete=<?= e((string) $r['id']) ?>">Delete</a>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>
<?php layout_footer(); ?>
