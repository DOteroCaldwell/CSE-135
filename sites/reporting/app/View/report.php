<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * The chrome shared by every live report page: the gate, the scope line, the
 * "save this view" form, and the body. Each file under /reports/ is one call to
 * report_page().
 */

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/charts.php';

function report_page(string $slug): never
{
    $r = Reports::get($slug);
    if ($r === null) {
        render_error_page(404, 'No such report', 'That report does not exist.');
    }

    Auth::requireSection($r['section']);

    $f = Filters::fromQuery($_GET);

    layout_header($r['title'], [
        'subtitle' => $r['question'],
        'wide'     => true,
        'section'  => $r['section'],
    ]);
    report_toolbar($r, $f);
    echo Reports::render($slug, $f);
    layout_footer();
    exit;
}

/**
 * Scope line plus, for publishers, the form that turns this exact view into a
 * saved report. A plain POST form: it works with scripting off, and the saved
 * report is what a viewer — who may never see this page — will open.
 */
function report_toolbar(array $r, Filters $f): void
{
    ?>
<p class="report-scope">
  <a href="/<?= e($f->toQuery()) ?>">← Back to the dashboard</a>
  &nbsp;·&nbsp; Section: <strong><?= e(Sections::label($r['section'])) ?></strong>
  &nbsp;·&nbsp; Scope: <?= e($f->describe()) ?>
</p>
<?php if (Auth::canPublish()): ?>
<details class="save-view">
  <summary>Save this view as a report</summary>
  <form method="post" action="/saved/save.php" class="save-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="report" value="<?= e($r['slug']) ?>">
    <input type="hidden" name="query" value="<?= e(ltrim($f->toQuery(), '?')) ?>">
    <label class="field" for="save-title">Title
      <input type="text" id="save-title" name="title" required maxlength="160"
             value="<?= e($r['title'] . ' — ' . $f->describe()) ?>">
    </label>
    <label class="field" for="save-note">Analyst comment (optional)
      <textarea id="save-note" name="note" rows="3" maxlength="4000"
                placeholder="What does this view show, and what should the reader take from it?"></textarea>
    </label>
    <p class="field">
      <button class="btn" type="submit">Save report</button>
      <span class="card-question" style="margin:0 0 0 12px">Snapshot of the data as it stands now; viewers can open it and export a PDF.</span>
    </p>
  </form>
</details>
<?php endif;
}

/** The standard "nothing in scope" card, so every report says it the same way. */
function report_empty(string $why): void
{
    ?>
<section class="card">
  <h2>No data in scope</h2>
  <p class="card-question"><?= e($why) ?></p>
</section>
<?php
}
