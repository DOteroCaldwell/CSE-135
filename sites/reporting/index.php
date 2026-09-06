<?php
declare(strict_types=1);

/**
 * CSE 135 HW4 Part 3 / HW5 — reporting dashboard.
 *
 * The performance cards serve one question:
 *
 *   If we could fix one thing about this site's performance, what should it be,
 *   and what is it worth?
 *
 * HW5 adds one card per further section — behaviour, audience — each an overview
 * that links to its full report. Cards render only for the sections this account
 * is assigned to; a viewer never reaches this page at all.
 *
 * No JavaScript. Filters are a GET form, charts are CSS, so the whole page works
 * with scripting disabled and every view has a shareable URL.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/View/layout.php';
require_once __DIR__ . '/app/View/charts.php';

Auth::requireLogin();

// A viewer's application is the saved-reports list. Sending them here would show
// a page whose every link 403s.
if (Auth::isViewer()) {
    redirect('/saved/');
}

$f = Filters::fromQuery($_GET);
$mySections = Auth::sections();

$hasPerf = in_array('performance', $mySections, true);
if ($hasPerf) {
    $breakdown = MetricRegistry::get('load-phase-breakdown')->compute($f);
    $cohort    = MetricRegistry::get('cache-cohort-split')->compute($f);
    $pages     = MetricRegistry::get('slowest-pages')->compute($f);
    $opp       = MetricRegistry::get('opportunity')->compute($f);
}

$hosts = PageviewSet::distinct('host');
$pageList = PageviewSet::distinct('page');

$hasBehaviour = in_array('behaviour', $mySections, true);
$hasAudience  = in_array('audience', $mySections, true);
if ($hasBehaviour) {
    $depth = MetricRegistry::get('scroll-depth')->compute($f);
    $lve   = MetricRegistry::get('load-vs-engagement')->compute($f);
}
if ($hasAudience) {
    $vp = MetricRegistry::get('viewport-classes')->compute($f);
}

layout_header('Dashboard', [
    'subtitle' => $hasPerf
        ? 'If we could fix one thing about this site\'s performance, what should it be — and what is it worth?'
        : 'Overview of the sections this account is assigned to. Each card links to its full report.',
    'wide'     => true,
]);
?>

<form class="filters" method="get" action="/">
  <label class="field" for="host">
    Site<select id="host" name="host">
      <option value="">All sites</option>
<?php foreach ($hosts as $h): ?>
      <option value="<?= e($h) ?>"<?= $f->host === $h ? ' selected' : '' ?>><?= e($h) ?></option>
<?php endforeach; ?>
    </select>
 </label>
  <label class="field" for="page">
    Page<select id="page" name="page">
      <option value="">All pages</option>
<?php foreach ($pageList as $p): ?>
      <option value="<?= e($p) ?>"<?= $f->page === $p ? ' selected' : '' ?>><?= e($p) ?></option>
<?php endforeach; ?>
    </select>
 </label>
  <label class="field" for="cache">
    Cache state (performance only)<select id="cache" name="cache">
<?php foreach (['all' => 'First and return visits', 'cold' => 'First visits only', 'warm' => 'Return visits only'] as $k => $lbl): ?>
      <option value="<?= e($k) ?>"<?= $f->cache === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
<?php endforeach; ?>
    </select>
 </label>
  <label class="field" for="from">
    From<input type="text" id="from" name="from" placeholder="YYYY-MM-DD" value="<?= e($f->from ?? '') ?>">
 </label>
  <label class="field" for="to">
    To<input type="text" id="to" name="to" placeholder="YYYY-MM-DD" value="<?= e($f->to ?? '') ?>">
 </label>
  <label class="field" for="synthetic">
    Generated traffic<select id="synthetic" name="synthetic">
      <option value="1"<?= $f->includeSynthetic ? ' selected' : '' ?>>Included</option>
      <option value="0"<?= $f->includeSynthetic ? '' : ' selected' ?>>Excluded</option>
    </select>
 </label>
  <p class="field" style="min-width:auto"><button class="btn" type="submit">Apply</button></p>
  <p class="field" style="min-width:auto"><a class="btn btn-quiet" href="/">Reset</a></p>
</form>

<?php if ($mySections === []): ?>
  <section class="card">
    <h2>No sections assigned</h2>
    <p class="card-question">
      This analyst account is not assigned to any report section yet, so there is
      nothing to show here. An administrator can assign sections from User management.
    </p>
  </section>
<?php endif; ?>

<?php /* ---------------------------------------------------- performance --- */ ?>
<?php if ($hasPerf): ?>
<?php if ($opp->isEmpty()): ?>
  <section class="card">
    <h2>No performance data yet</h2>
    <p class="card-question">
      Nothing matches these filters. Either the collector has not recorded a pageview
      for this selection, or the filters exclude everything — try Reset.
    </p>
  </section>
<?php else: ?>

<section class="verdict">
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
</section>

<div class="grid-2">

  <section class="card">
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
        'caption'   => 'Average milliseconds per pageview by load phase, for each page.',
        // Marks whichever phase the opportunity ranking put first. Computed, not
        // chosen: point this at another site and a different segment gets the ring.
        'highlight' => $opp->summary['winner'] ?? null,
    ]);
?>
    <p class="card-question" style="margin-top:14px">
      Averages, not medians — means add up, so the segments sum to the real total.
    </p>
    <?php coverage_badge($breakdown->coverage); ?>
  </section>

  <section class="card">
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
  </section>

</div>

<section class="card">
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
</section>

<?php endif; ?>
<?php endif; /* performance */ ?>

<?php /* ------------------------------------------ behaviour + audience --- */ ?>
<?php if ($hasBehaviour || $hasAudience): ?>
<div class="grid-2">
<?php if ($hasBehaviour): ?>
  <section class="card">
    <h2>How far visitors scroll <span class="role section-badge">Behaviour</span></h2>
    <p class="card-question"><?= e(MetricRegistry::get('scroll-depth')->question()) ?></p>
<?php if ($depth->isEmpty()): ?>
    <p class="card-question">No activity recorded for these filters.</p>
<?php else: ?>
<?php
    chart_stacked_bar(array_map(static fn($r) => [
        'label' => $r['page'], 'parts' => $r['parts'], 'total' => $r['n'],
    ], array_slice($depth->rows, 0, 6)), ScrollDepth::labels(), [
        'caption' => 'Pageviews per page, split by the deepest quarter of the page reached.',
        'format'  => static fn($v) => fmt_int((float) $v) . ($v == 1 ? ' view' : ' views'),
    ]);
?>
    <p class="card-question" style="margin-top:14px">
      <?= e(fmt_pct($depth->summary['reached_half'])) ?> of pageviews reach halfway.
<?php if (($lve->summary['verdict'] ?? null) === 'costs'): ?>
      Slow loads cost attention: <?= e(fmt_pct($lve->summary['fast_half'])) ?> of the fastest
      quarter of loads reach halfway, <?= e(fmt_pct($lve->summary['slow_half'])) ?> of the slowest.
<?php elseif (($lve->summary['verdict'] ?? null) === 'no-clear-link'): ?>
      Load time does not clearly predict engagement in this data.
<?php endif; ?>
      <a href="/reports/engagement.php<?= e($f->toQuery()) ?>">Engagement report →</a>
    </p>
    <?php coverage_badge($depth->coverage); ?>
<?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($hasAudience): ?>
  <section class="card">
    <h2>Viewport sizes <span class="role section-badge">Audience</span></h2>
    <p class="card-question"><?= e(MetricRegistry::get('viewport-classes')->question()) ?></p>
<?php if (($vp->summary['pageviews'] ?? 0) === 0): ?>
    <p class="card-question">No static pageview rows for these filters.</p>
<?php else: ?>
<?php
    chart_column_multi(array_map(static fn($r) => [
        'label' => $r['label'], 'axis' => $r['axis'], 'n' => $r['n'],
    ], $vp->rows), ['n' => 'Pageviews'], [
        'caption'     => 'Pageviews by viewport width class.',
        'colorOffset' => 2,
    ]);
?>
    <p class="card-question" style="margin-top:14px">
<?php if (!empty($vp->summary['dominant_label'])): ?>
      Design for <strong><?= e($vp->summary['dominant_label']) ?></strong>
      (<?= e(fmt_pct($vp->summary['dominant_share'])) ?>)<?php
      if (!empty($vp->summary['narrowest_label']) && $vp->summary['narrowest'] !== $vp->summary['dominant']): ?>;
      never break on <strong><?= e($vp->summary['narrowest_label']) ?></strong>
      (<?= e(fmt_pct($vp->summary['narrowest_share'])) ?>)<?php endif; ?>.
<?php endif; ?>
      <a href="/reports/audience.php<?= e($f->toQuery()) ?>">Audience report →</a>
    </p>
    <?php coverage_badge($vp->coverage); ?>
<?php endif; ?>
  </section>
<?php endif; ?>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
