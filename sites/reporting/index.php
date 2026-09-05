<?php
declare(strict_types=1);

/**
 * CSE 135 HW4 Part 3 — reporting dashboard.
 *
 * Everything on this page serves one question:
 *
 *   If we could fix one thing about this site's performance, what should it be,
 *   and what is it worth?
 *
 * The dashboard is the overview — where time goes, who it goes to, and which pages
 * carry it. The detailed report drills into the verdict.
 *
 * No JavaScript. Filters are a GET form, charts are CSS, so the whole page works
 * with scripting disabled and every view has a shareable URL.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/View/layout.php';
require_once __DIR__ . '/app/View/charts.php';

Auth::requireLogin();

$f = Filters::fromQuery($_GET);

$breakdown = MetricRegistry::get('load-phase-breakdown')->compute($f);
$cohort    = MetricRegistry::get('cache-cohort-split')->compute($f);
$pages     = MetricRegistry::get('slowest-pages')->compute($f);
$opp       = MetricRegistry::get('opportunity')->compute($f);

$hosts = PageviewSet::distinct('host');
$pageList = PageviewSet::distinct('page');

layout_header('Performance dashboard', [
    'subtitle' => 'If we could fix one thing about this site\'s performance, what should it be — and what is it worth?',
    'wide'     => true,
]);
?>

<form class="filters" method="get" action="/">
  <div class="field">
    <label for="host">Site</label>
    <select id="host" name="host">
      <option value="">All sites</option>
<?php foreach ($hosts as $h): ?>
      <option value="<?= e($h) ?>"<?= $f->host === $h ? ' selected' : '' ?>><?= e($h) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="page">Page</label>
    <select id="page" name="page">
      <option value="">All pages</option>
<?php foreach ($pageList as $p): ?>
      <option value="<?= e($p) ?>"<?= $f->page === $p ? ' selected' : '' ?>><?= e($p) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="cache">Cache state</label>
    <select id="cache" name="cache">
<?php foreach (['all' => 'First and return visits', 'cold' => 'First visits only', 'warm' => 'Return visits only'] as $k => $lbl): ?>
      <option value="<?= e($k) ?>"<?= $f->cache === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="from">From</label>
    <input type="text" id="from" name="from" placeholder="YYYY-MM-DD" value="<?= e($f->from ?? '') ?>">
  </div>
  <div class="field">
    <label for="to">To</label>
    <input type="text" id="to" name="to" placeholder="YYYY-MM-DD" value="<?= e($f->to ?? '') ?>">
  </div>
  <div class="field">
    <label for="synthetic">Generated traffic</label>
    <select id="synthetic" name="synthetic">
      <option value="1"<?= $f->includeSynthetic ? ' selected' : '' ?>>Included</option>
      <option value="0"<?= $f->includeSynthetic ? '' : ' selected' ?>>Excluded</option>
    </select>
  </div>
  <div class="field" style="min-width:auto"><button class="btn" type="submit">Apply</button></div>
  <div class="field" style="min-width:auto"><a class="btn btn-quiet" href="/">Reset</a></div>
</form>

<?php if ($opp->isEmpty()): ?>
  <div class="card">
    <h2>No performance data yet</h2>
    <p class="card-question">
      Nothing matches these filters. Either the collector has not recorded a pageview
      for this selection, or the filters exclude everything — try Reset.
    </p>
  </div>
<?php else: ?>

<div class="verdict">
  <h2>Current answer</h2>
  <p class="headline">
    <?= e($opp->summary['winner_label']) ?> —
    <?= e(fmt_ms($opp->summary['winner_savings_per_view'])) ?> recoverable per pageview
  </p>
  <p>
    That is <?= e(fmt_pct($opp->summary['winner_pct_of_load'])) ?> of an average load,
    or <?= e(fmt_ms($opp->summary['winner_savings_per_1k'])) ?> of visitor time
    for every 1,000 pageviews served.
  </p>
  <p><a href="/reports/page-load-cost.php<?= e($f->toQuery()) ?>">See the full reasoning in the load cost report →</a></p>
</div>

<div class="grid-2">

  <div class="card">
    <h2>Where load time goes</h2>
    <p class="card-question"><?= e(MetricRegistry::get('load-phase-breakdown')->question()) ?></p>
<?php
    /*
     * Series are ordered by the TIMELINE, not by size.
     *
     * overall_phase_means arrives sorted largest-first, which is right for ranking
     * and wrong for stacking: a stacked bar of a page load should read left to
     * right in the order the phases actually happen — DNS, connect, server wait,
     * download, parse, subresources — so the bar tells the story of the load. Sorted
     * by magnitude it is just a sorted list wearing a bar chart's clothes.
     *
     * Iterating Phases::labels() also keeps a phase's colour stable across pages, so
     * the same segment means the same thing in every bar.
     */
    $means  = $breakdown->summary['overall_phase_means'];
    $series = [];
    foreach (Phases::labels() as $k => $label) {
        // Sub-millisecond phases get no colour and no legend entry, or nine labels
        // compete to explain a bar with three visible segments.
        if (($means[$k] ?? 0) > 0.5) { $series[$k] = $label; }
    }
    $rows = array_map(static fn($r) => [
        'label' => $r['page'],
        'parts' => $r['phases'],
        'total' => $r['total'],
    ], array_slice($breakdown->rows, 0, 8));
    chart_stacked_bar($rows, $series, [
        'caption' => 'Average milliseconds per pageview by load phase, for each page.',
    ]);
?>
    <p class="card-question" style="margin-top:14px">
      Averages, not medians — means add up, so the segments sum to the real total.
    </p>
    <?php coverage_badge($breakdown->coverage); ?>
  </div>

  <div class="card">
    <h2>First visit vs return visit</h2>
    <p class="card-question"><?= e(MetricRegistry::get('cache-cohort-split')->question()) ?></p>
<?php
    chart_column_multi($cohort->rows, ['cold' => 'First visit (cold cache)', 'warm' => 'Return visit (warm cache)'], [
        'caption'     => 'Number of pageviews falling in each load-time band, split by cache state.',
        'colorOffset' => 2,
    ]);
?>
<?php if (($cohort->summary['ratio'] ?? null) !== null): ?>
    <p class="card-question" style="margin-top:14px">
      A first visit takes <strong><?= e(number_format($cohort->summary['ratio'], 1)) ?>×</strong>
      as long as a return visit
      (<?= e(fmt_ms($cohort->summary['cold_median'])) ?> vs <?= e(fmt_ms($cohort->summary['warm_median'])) ?> median).
    </p>
<?php endif; ?>
    <?php coverage_badge($cohort->coverage); ?>
  </div>

</div>

<div class="card">
  <h2>Pages ranked by load cost</h2>
  <p class="card-question"><?= e(MetricRegistry::get('slowest-pages')->question()) ?></p>
<?php
data_table([
    'page'           => ['label' => 'Page'],
    'n'              => ['label' => 'Pageviews', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
    'median_ms'      => ['label' => 'Median', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'p90_ms'         => ['label' => 'p90', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'cold_median_ms' => ['label' => 'First visit', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'warm_median_ms' => ['label' => 'Return visit', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'dominant_label' => ['label' => 'Dominant phase'],
    'dominant_share' => ['label' => 'Share', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
], $pages->rows);
?>
  <p class="card-question" style="margin-top:14px">
    Ordered by total visitor time spent, not by median — a middling page everyone
    loads costs more than a slow page nobody does.
  </p>
  <?php coverage_badge($pages->coverage); ?>
</div>

<?php endif; ?>
<?php layout_footer(); ?>
