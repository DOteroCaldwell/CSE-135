<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * CSE 135 HW5 — "Engagement", the behaviour report body.
 *
 * GUIDING QUESTION
 *   Do visitors engage with a page once it has loaded — and where do they give up?
 *
 * Four movements:
 *   1. How far do they get?            (scroll depth, per page)
 *   2. What does a visit look like?    (the grid: time, clicks, idle, bounces)
 *   3. Does waiting cost attention?    (load time vs engagement — the question the
 *                                       performance report could not answer)
 *   4. Where does it break?            (errors, per page and per message)
 *
 * As with the performance report, every verdict is computed from the rows in
 * scope. The prose interpolates whatever the data produced; nothing here assumes
 * which page is abandoned or whether slow loads matter.
 */

function render_report_engagement(Filters $f): void
{
    $depth = MetricRegistry::get('scroll-depth')->compute($f);
    $grid  = MetricRegistry::get('engagement-by-page')->compute($f);
    $err   = MetricRegistry::get('error-hotspots')->compute($f);
    $lve   = MetricRegistry::get('load-vs-engagement')->compute($f);

    if ($grid->isEmpty()) {
        report_empty('This report needs pageviews with activity events (scrolls, clicks, '
            . 'page leave). Browse the instrumented site, or widen the filters.');
        return;
    }

    $n        = $grid->summary['pageviews'];
    $thin     = $n < Coverage::MIN_PAGEVIEWS;
    $worst    = $depth->summary['worst_page'] ?? null;
    $lveRows  = $lve->rows;
    $lveVerd  = $lve->summary['verdict'] ?? null;
?>

<!-- ============================ THE ANSWER ============================== -->
<section class="verdict">
  <h2>The answer, as the data currently stands</h2>
<?php if ($depth->summary['reached_half'] !== null): $dn = (int) $depth->summary['pageviews']; ?>
  <p class="headline">
    <?= e(fmt_pct($depth->summary['reached_half'])) ?> of pageviews get at least halfway down the page<?php
    if ($dn < $n): ?> <span class="card-question" style="font-size:14px;font-weight:400">(scroll depth known for <?= e((string) $dn) ?> of <?= e((string) $n) ?>)</span><?php endif; ?>
<?php if ($worst !== null): ?>
    — least of all on <code><?= e($worst) ?></code>
<?php endif; ?>
  </p>
<?php endif; ?>
  <p>
    The median visit lasts <strong><?= e(fmt_ms($grid->summary['median_time_ms'])) ?></strong>,
    <strong><?= e(fmt_pct($grid->summary['bounce_rate'])) ?></strong> of visits end within
    <?= e(fmt_ms((float) ActivitySet::BOUNCE_MS)) ?>, and
    <strong><?= e(fmt_pct($err->summary['error_rate'])) ?></strong> of pageviews hit a script error.
<?php if ($lveVerd === 'costs'): ?>
    Waiting costs attention: <?= e(fmt_pct($lve->summary['fast_half'])) ?> of the fastest
    quarter of loads reach halfway, against <?= e(fmt_pct($lve->summary['slow_half'])) ?>
    of the slowest quarter.
<?php elseif ($lveVerd === 'no-clear-link'): ?>
    Load time does not clearly predict engagement in this data — the fast and slow
    quarters reach halfway at similar rates.
<?php endif; ?>
  </p>
<?php if ($thin): ?>
  <p class="notice notice-error" style="margin:10px 0 0">
    <strong>Thin data.</strong> <?= e((string) $n) ?> pageviews. The pattern is real for
    what has been measured; whether it holds is not yet settled.
  </p>
<?php endif; ?>
</section>

<!-- ==================== 1. HOW FAR DO THEY GET? ========================= -->
<section class="card">
  <h2>1. How far do they get?</h2>
  <p class="card-question">
    <?= e(MetricRegistry::get('scroll-depth')->question()) ?> Each bar is one page;
    the segments are the share of its visits that stopped in each quarter of the
    page. A bar that is mostly the first segment is a page people open and leave.
  </p>
<?php
    chart_stacked_bar(array_map(static fn($r) => [
        'label' => $r['page'], 'parts' => $r['parts'], 'total' => $r['n'],
    ], array_slice($depth->rows, 0, 10)), ScrollDepth::labels(), [
        'caption' => 'Pageviews per page, split by the deepest quarter of the page reached.',
        'format'  => static fn($v) => fmt_int((float) $v) . ($v == 1 ? ' view' : ' views'),
    ]);
?>
  <p class="card-question" style="margin-top:14px">
    Depth is (deepest scroll + viewport height) ÷ page height, so a page that fits in
    the window counts as fully seen without a single scroll.
<?php if (($depth->summary['no_depth_rows'] ?? 0) > 0): ?>
    <?= e((string) $depth->summary['no_depth_rows']) ?> pageviews recorded no scroll data and are left out of this chart.
<?php endif; ?>
  </p>
  <?php coverage_badge($depth->coverage); ?>
</section>

<!-- ================= 2. WHAT DOES A VISIT LOOK LIKE? ==================== -->
<section class="card">
  <h2>2. What does a visit look like?</h2>
  <p class="card-question"><?= e(MetricRegistry::get('engagement-by-page')->question()) ?>
     Medians, so one tab left open overnight does not describe a page.</p>
<?php
    data_table([
        'page'            => ['label' => 'Page'],
        'n'               => ['label' => 'Pageviews', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'median_time_ms'  => ['label' => 'Median visit', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
        'p90_time_ms'     => ['label' => 'p90 visit', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
        'median_depth'    => ['label' => 'Median depth', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
        'clicks_per_view' => ['label' => 'Clicks / view', 'num' => true, 'fmt' => static fn($v) => $v === null ? '—' : number_format((float) $v, 1)],
        'idle_share'      => ['label' => 'Idle share', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
        'bounce_rate'     => ['label' => 'Left < 5 s', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
        'error_rate'      => ['label' => 'Saw an error', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
    ], $grid->rows);
?>
  <p class="card-question" style="margin-top:14px">
    Idle share is time in gaps of two seconds or more with no input, as a share of
    the visit — reading looks like idling, so a high figure on a long visit is not
    a bad sign.
  </p>
  <?php coverage_badge($grid->coverage); ?>
</section>

<!-- ================ 3. DOES WAITING COST ATTENTION? ===================== -->
<section class="card">
  <h2>3. Does waiting cost attention?</h2>
  <p class="card-question">
    <?= e(MetricRegistry::get('load-vs-engagement')->question()) ?> The same pageviews
    split into four cohorts by their own load time; if slow loads cost engagement,
    the bars should shrink from left to right.
  </p>
<?php if ($lveRows === []): ?>
  <p class="card-question">Not enough pageviews carry both a load time and a scroll depth to split into cohorts yet.</p>
<?php else: ?>
  <div class="grid-2">
    <div>
<?php
    chart_ranked_bar(array_map(static fn($r) => [
        'label' => $r['cohort'] . ' (' . fmt_ms($r['median_load']) . ')',
        'value' => $r['reached_half'],
    ], $lveRows), [
        'caption' => 'Share of pageviews reaching halfway down the page, by load-time cohort.',
        'format'  => 'fmt_pct',
        'uniform' => true,
        'labelWidth' => 'clamp(150px, 30vw, 240px)',
    ]);
?>
    </div>
    <div>
<?php
    data_table([
        'cohort'         => ['label' => 'Load-time cohort'],
        'n'              => ['label' => 'Views', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'median_load'    => ['label' => 'Median load', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
        'reached_half'   => ['label' => 'Reach halfway', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
        'median_time_ms' => ['label' => 'Median visit', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
        'bounce_rate'    => ['label' => 'Left < 5 s', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
    ], $lveRows);
?>
    </div>
  </div>
  <p class="card-question" style="margin-top:14px">
<?php if ($lveVerd === 'costs'): ?>
    The slowest quarter of loads (median <?= e(fmt_ms($lve->summary['slow_load'])) ?>)
    reaches halfway <?= e(fmt_pct($lve->summary['gap'])) ?> less often than the fastest
    (median <?= e(fmt_ms($lve->summary['fast_load'])) ?>)
    <?= !empty($lve->summary['monotonic']) ? 'and the decline is steady across all four cohorts, which is harder to produce by chance than a single gap.' : 'though the middle cohorts do not fall in order, so treat the size of the effect with care.' ?>
<?php elseif ($lveVerd === 'inverse'): ?>
    Slower loads are reaching <em>further</em> here. That usually means the slow
    loads are heavy pages people came specifically to read; it is not evidence that
    slowness helps.
<?php else: ?>
    No clear relationship at this sample size. That is a finding too: it argues
    against spending on load time for engagement's sake until there is more data.
<?php endif; ?>
    None of this proves cause. A phone on a poor connection loads slowly <em>and</em>
    is used impatiently; the cohorts cannot separate the two.
  </p>
  <?php coverage_badge($lve->coverage); ?>
<?php endif; ?>
</section>

<!-- ===================== 4. WHERE DOES IT BREAK? ======================== -->
<section class="card">
  <h2>4. Where does it break?</h2>
  <p class="card-question"><?= e(MetricRegistry::get('error-hotspots')->question()) ?></p>
  <div class="grid-2">
    <div>
<?php
    $errRows = array_filter($err->rows, static fn($r) => $r['error_rate'] > 0);
    if ($errRows === []) {
        echo '<p class="card-question">No script errors recorded in scope.</p>';
    } else {
        chart_ranked_bar(array_map(static fn($r) => [
            'label' => $r['page'], 'value' => $r['error_rate'],
        ], array_slice(array_values($errRows), 0, 8)), [
            'caption' => 'Share of pageviews that saw at least one JavaScript error, by page.',
            'format'  => 'fmt_pct',
        ]);
    }
?>
    </div>
    <div>
<?php
    data_table([
        'message'     => ['label' => 'Error', 'wrap' => true],
        'pageviews'   => ['label' => 'Views hit', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'occurrences' => ['label' => 'Times', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'pages'       => ['label' => 'Pages', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'source'      => ['label' => 'Source', 'wrap' => true, 'fmt' => static fn($v) => $v === null ? '—' : basename((string) $v)],
    ], $err->summary['messages'] ?? []);
?>
    </div>
  </div>
  <?php coverage_badge($err->coverage); ?>
</section>

<!-- ======================= WRITTEN DISCUSSION =========================== -->
<section class="card">
  <h2>Discussion</h2>

  <p>
    Across <strong><?= e(fmt_int((float) $n)) ?> pageviews</strong> in scope
    (<?= e($f->describe()) ?>), the median visit lasts
    <strong><?= e(fmt_ms($grid->summary['median_time_ms'])) ?></strong> and
    <?= e(fmt_pct($depth->summary['reached_half'])) ?> of visits get at least halfway down
    the page. <?= e(fmt_pct($depth->summary['reached_end'])) ?> reach the last quarter.
<?php if ($worst !== null): ?>
    The page visitors give up on soonest is <code><?= e($worst) ?></code>, with a median
    depth of <?= e(fmt_pct($depth->summary['worst_median'])) ?> — whatever is below that
    point on that page is being seen by fewer than half the people who open it.
<?php endif; ?>
  </p>

<?php if (($err->summary['with_error'] ?? 0) > 0): ?>
  <p>
    <?= e(fmt_int((float) $err->summary['with_error'])) ?> pageviews
    (<?= e(fmt_pct($err->summary['error_rate'])) ?>) saw a JavaScript error, from
    <?= e((string) $err->summary['distinct']) ?> distinct message<?= $err->summary['distinct'] === 1 ? '' : 's' ?>.
<?php if (!empty($err->summary['worst_page'])): ?>
    The rate is highest on <code><?= e($err->summary['worst_page']) ?></code>
    (<?= e(fmt_pct($err->summary['worst_rate'])) ?> of its views).
<?php endif; ?>
<?php if (!empty($err->summary['messages'][0])): $top = $err->summary['messages'][0]; ?>
    The single most widespread error is <code><?= e(mb_strimwidth((string) $top['message'], 0, 90, '…')) ?></code>,
    hitting <?= e(fmt_int((float) $top['pageviews'])) ?> pageviews across
    <?= e(fmt_int((float) $top['pages'])) ?> page<?= (int) $top['pages'] === 1 ? '' : 's' ?> — one fix, not many.
<?php endif; ?>
  </p>
<?php endif; ?>

<?php if ($lveRows !== []): ?>
  <p>
<?php if ($lveVerd === 'costs'): ?>
    The performance report ends by asking whether slow loads actually cost anything.
    Here they do: the slowest quarter of loads reaches halfway
    <?= e(fmt_pct($lve->summary['gap'])) ?> less often and bounces at
    <?= e(fmt_pct($lve->summary['slow_bounce'])) ?> against
    <?= e(fmt_pct($lve->summary['fast_bounce'])) ?> for the fastest quarter. That is the
    number that turns "recoverable milliseconds" into something worth a developer's
    afternoon.
<?php else: ?>
    The performance report ends by asking whether slow loads actually cost anything.
    This data does not yet show that they do: engagement across the four load-time
    cohorts is <?= $lveVerd === 'inverse' ? 'higher on the slower loads, which points at page content rather than speed' : 'flat within the noise' ?>.
    The honest recommendation is to keep collecting before spending on speed for
    engagement's sake.
<?php endif; ?>
  </p>
<?php endif; ?>

  <h2 class="h2-sub" style="margin-top:22px">Analyst comment</h2>
  <p>
    Scroll depth is the most useful single number here and the easiest to misread.
    It measures what was <em>on screen</em>, not what was read, and a short page
    scores 100% for everyone. Compare pages of similar length, or compare the same
    page over time, and it is dependable; compare the homepage to the checkout and
    it says more about their heights than their appeal.
  </p>
  <p>
    Two things this report cannot see. It records nothing from visitors whose
    browsers block the collector, so a privacy-conscious audience is invisible here
    and over-represented nowhere. And every visit lands in one page's row: a visitor
    who bounces off the homepage straight into the product page is a "bounce" and a
    success at once. Session-level paths are the natural next report — the data is
    already there, joined on the session id the server minted.
  </p>
</section>
<?php
}
