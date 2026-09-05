<?php
declare(strict_types=1);

/**
 * CSE 135 HW4 Part 4 — detailed report.
 *
 * GUIDING QUESTION
 *   If we could fix one thing about this site's performance, what should it be,
 *   and what is it worth?
 *
 * Three movements, in the order an analyst would actually reason:
 *   1. Where does the time go?      (diagnosis)
 *   2. What is the one thing?       (the verdict, ranked from data)
 *   3. What is it worth?            (the counterfactual, in time and in bytes)
 *
 * The verdict is COMPUTED. Nothing on this page hardcodes which phase wins; point
 * the platform at a different site and the same code reaches a different
 * conclusion. What IS authored is the remedy text — once the data has named a
 * phase, saying what that phase responds to is domain knowledge, not a prediction,
 * and that distinction is stated on the page rather than blurred.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/View/layout.php';
require_once __DIR__ . '/../app/View/charts.php';

Auth::requireLogin();

$f = Filters::fromQuery($_GET);

$opp       = MetricRegistry::get('opportunity')->compute($f);
$breakdown = MetricRegistry::get('load-phase-breakdown')->compute($f);
$cohort    = MetricRegistry::get('cache-cohort-split')->compute($f);
$weight    = MetricRegistry::get('resource-weight')->compute($f);
$pages     = MetricRegistry::get('slowest-pages')->compute($f);

/**
 * What a given phase responds to.
 *
 * Reached only AFTER the data has chosen a phase. Every entry is written, so every
 * entry has to be equally usable — there is no default branch that quietly assumes
 * the answer, and adding a phase here does not change which phase wins.
 */
const REMEDIES = [
    'tail' => [
        'Compress and resize the heaviest images, and serve modern formats (WebP/AVIF) with width-appropriate variants.',
        'Add loading="lazy" to images below the fold so they stop competing with the load event.',
        'Set long Cache-Control lifetimes on static assets so a return visit pays nothing.',
    ],
    'dom' => [
        'Move synchronous <script> tags out of <head>, or mark them defer/async — a blocking script stalls the parser at the point it appears.',
        'Drop stylesheets and scripts the page never uses; every render-blocking request extends this phase.',
        'Inline only the CSS needed for first paint and load the rest asynchronously.',
    ],
    'ttfb' => [
        'Profile the server-side handler for this route; TTFB is the one phase the client cannot influence.',
        'Add caching in front of the response (Apache mod_cache, or application-level caching of expensive queries).',
        'Check for slow database queries or unindexed lookups on the request path.',
    ],
    'download' => [
        'Enable or verify compression (mod_deflate/gzip, or Brotli) for HTML responses.',
        'Reduce document size — large inline styles and inline data URIs inflate the HTML itself.',
    ],
    'tcp' => [
        'Confirm HTTP/2 or HTTP/3 is in use so connections are reused rather than reopened.',
        'Check TLS session resumption; a full handshake on every visit is avoidable.',
    ],
    'dns' => [
        'Reduce the number of distinct hostnames the page touches, or add dns-prefetch hints for ones it must.',
    ],
    'redirect' => [
        'Remove redirect chains — link directly to the final URL, especially for the landing page.',
    ],
    'dcl' => [
        'Audit DOMContentLoaded handlers; work here delays everything that follows.',
    ],
    'loadevt' => [
        'Audit load-event handlers, and move anything not needed for first render into an idle callback.',
    ],
    'other' => [
        'Time is falling outside the named phases — inspect raw nav_timing for these pageviews before acting.',
    ],
];

$winner       = $opp->summary['winner'] ?? null;
$winnerLabel  = $opp->summary['winner_label'] ?? null;
$margin       = $opp->summary['margin_over_second'] ?? null;
$decisive     = $margin !== null && $margin >= 1.5;
$n            = $opp->summary['pageviews'] ?? 0;
$thin         = $n < Coverage::MIN_PAGEVIEWS;

layout_header('Page load cost', [
    'subtitle' => 'If we could fix one thing about this site\'s performance, what should it be — and what is it worth?',
    'wide'     => true,
]);
?>

<p><a href="/<?= e($f->toQuery()) ?>">← Back to the dashboard</a>
   &nbsp;·&nbsp; Scope: <?= e($f->describe()) ?></p>

<?php if ($opp->isEmpty()): ?>
  <div class="card">
    <h2>No data in scope</h2>
    <p class="card-question">This report needs at least one pageview with navigation
       timing. Adjust the filters on the dashboard, or generate traffic against the
       instrumented site.</p>
  </div>
  <?php layout_footer(); exit; ?>
<?php endif; ?>

<!-- ============================ THE ANSWER ============================== -->
<div class="verdict">
  <h2>The answer, as the data currently stands</h2>
  <p class="headline"><?= e($winnerLabel) ?></p>
  <p>
    Bringing this phase to the speed the site <em>already achieves on its fastest
    10% of loads</em> would recover
    <strong><?= e(fmt_ms($opp->summary['winner_savings_per_view'])) ?> per pageview</strong>
    — <?= e(fmt_pct($opp->summary['winner_pct_of_load'])) ?> of an average load, or
    <strong><?= e(fmt_ms($opp->summary['winner_savings_per_1k'])) ?></strong>
    of visitor time for every 1,000 pageviews served.
  </p>
<?php if (!$decisive): ?>
  <p class="notice notice-error" style="margin:10px 0 0">
    <strong>Not a decisive win.</strong> The runner-up is within
    <?= e($margin === null ? 'the same range' : number_format($margin, 2) . '×') ?>,
    so treat this as two candidates of similar value rather than a single obvious fix.
  </p>
<?php endif; ?>
<?php if ($thin): ?>
  <p class="notice notice-error" style="margin:10px 0 0">
    <strong>Thin data.</strong> This conclusion rests on <?= e((string) $n) ?> pageviews.
    It is the correct reading of what has been measured so far, not a settled fact
    about the site.
  </p>
<?php endif; ?>
</div>

<!-- ================== 1. WHERE DOES THE TIME GO? ======================== -->
<div class="card">
  <h2>1. Where does the time go?</h2>
  <p class="card-question">
    A page load is not one number. Splitting it at the boundaries the browser
    reports turns "this page is slow" into "this <em>part</em> of this page is slow",
    which is the difference between a complaint and a task.
  </p>
<?php
$series = [];
foreach (Phases::labels() as $k => $label) {
    if (($breakdown->summary['overall_phase_means'][$k] ?? 0) > 0.5) { $series[$k] = $label; }
}
chart_stacked_bar(array_map(static fn($r) => [
    'label' => $r['page'], 'parts' => $r['phases'], 'total' => $r['total'],
], array_slice($breakdown->rows, 0, 10)), $series, [
    'caption' => 'Average milliseconds per pageview by load phase, for each page.',
]);
?>
  <p class="card-question" style="margin-top:14px">
    Segments are means rather than medians because means are additive — nine phase
    medians do not sum to the median total, so a stacked median bar would depict a
    pageview that never occurred.
  </p>
  <?php coverage_badge($breakdown->coverage); ?>
</div>

<!-- =================== 2. WHAT IS THE ONE THING? ======================== -->
<div class="card">
  <h2>2. What is the one thing?</h2>
  <p class="card-question">
    Each phase is scored on <strong>recoverable</strong> time, not raw size: how much
    time it spends above its own best-case, summed over every pageview. A phase can
    be large and offer nothing if it is already consistent; a phase can be modest on
    average and offer a lot if it is wildly variable.
  </p>
<?php
chart_ranked_bar(array_map(static fn($r) => [
    'label' => $r['label'], 'value' => $r['savings_per_view'],
], array_slice($opp->rows, 0, 8)), [
    'caption' => 'Recoverable milliseconds per pageview, by phase.',
]);

data_table([
    'label'            => ['label' => 'Phase'],
    'mean'             => ['label' => 'Mean', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'median'           => ['label' => 'Median', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'p90'              => ['label' => 'p90', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'baseline'         => ['label' => 'Best case (p10)', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'savings_per_view' => ['label' => 'Recoverable / view', 'num' => true, 'fmt' => static fn($v) => fmt_ms($v)],
    'pct_of_load'      => ['label' => '% of load', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
], $opp->rows);
?>
  <?php coverage_badge($opp->coverage); ?>
</div>

<!-- ===================== 3. WHAT IS IT WORTH? =========================== -->
<div class="card">
  <h2>3. What is it worth?</h2>
  <p class="card-question">
    Recoverable time expressed three ways, because "<?= e(fmt_ms($opp->summary['winner_savings_per_view'])) ?>"
    means different things to a developer, a visitor, and whoever decides what gets
    worked on next.
  </p>

  <div class="grid-2">
    <div>
      <table class="data">
        <tbody>
          <tr><th>Per pageview</th><td class="num"><?= e(fmt_ms($opp->summary['winner_savings_per_view'])) ?></td></tr>
          <tr><th>Share of an average load</th><td class="num"><?= e(fmt_pct($opp->summary['winner_pct_of_load'])) ?></td></tr>
          <tr><th>Per 1,000 pageviews</th><td class="num"><?= e(fmt_ms($opp->summary['winner_savings_per_1k'])) ?></td></tr>
          <tr><th>Across the <?= e(fmt_int((float) $n)) ?> pageviews measured</th>
              <td class="num"><?= e(fmt_ms($opp->summary['winner_savings_per_view'] * $n)) ?></td></tr>
          <tr><th>Median load today</th><td class="num"><?= e(fmt_ms($opp->summary['median_total'])) ?></td></tr>
          <tr><th>Median load if fixed</th>
              <td class="num"><?= e(fmt_ms(max(0, $opp->summary['median_total'] - $opp->summary['winner_savings_per_view']))) ?></td></tr>
        </tbody>
      </table>
    </div>
    <div>
      <h2 style="font-size:15px">What this phase responds to</h2>
      <ul>
<?php foreach (REMEDIES[$winner] ?? [] as $r): ?>
        <li><?= e($r) ?></li>
<?php endforeach; ?>
      </ul>
      <p class="card-question">
        These are written, not derived. The data chose the phase; this list says what
        that phase is known to respond to.
      </p>
    </div>
  </div>
</div>

<!-- ================== SUPPORTING: cache and resources =================== -->
<div class="grid-2">
  <div class="card">
    <h2>First visit vs return visit</h2>
    <p class="card-question">The cost of arriving fresh, which is the only cost a new visitor ever sees.</p>
<?php chart_column_multi($cohort->rows, ['cold' => 'First visit', 'warm' => 'Return visit'], ['colorOffset' => 2]); ?>
<?php if (($cohort->summary['ratio'] ?? null) !== null): ?>
    <p class="card-question" style="margin-top:14px">
      Median first visit <?= e(fmt_ms($cohort->summary['cold_median'])) ?> against
      <?= e(fmt_ms($cohort->summary['warm_median'])) ?> on return — a
      <strong><?= e(number_format($cohort->summary['ratio'], 1)) ?>×</strong> difference.
      Every figure elsewhere in this report averages the two together, so the
      first-visit experience is worse than the headline numbers suggest.
    </p>
<?php endif; ?>
    <?php coverage_badge($cohort->coverage); ?>
  </div>

  <div class="card">
    <h2>Heaviest resources</h2>
    <p class="card-question">
      Ranked by total bytes actually transferred, so a file requested on every page
      outranks a larger one requested once.
    </p>
<?php
data_table([
    'short'             => ['label' => 'File', 'wrap' => true],
    'initiator_type'    => ['label' => 'Type'],
    'body_bytes'        => ['label' => 'Size', 'num' => true, 'fmt' => static fn($v) => fmt_bytes($v)],
    'transferred_bytes' => ['label' => 'Total sent', 'num' => true, 'fmt' => static fn($v) => fmt_bytes($v)],
    'requests'          => ['label' => 'Requests', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
    'cache_hit_rate'    => ['label' => 'From cache', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
    'is_blocking'       => ['label' => 'Blocking', 'fmt' => static fn($v) => $v ? 'yes' : ''],
], array_slice($weight->rows, 0, 12));
?>
    <?php coverage_badge($weight->coverage); ?>
  </div>
</div>

<!-- ======================= WRITTEN DISCUSSION =========================== -->
<div class="card">
  <h2>Discussion</h2>

  <p>
    Across <strong><?= e(fmt_int((float) $n)) ?> pageviews</strong> in scope
    (<?= e($f->describe()) ?>), the average load is
    <strong><?= e(fmt_ms($opp->summary['mean_total'])) ?></strong> and the median is
    <strong><?= e(fmt_ms($opp->summary['median_total'])) ?></strong>. The gap between
    those two is itself informative: the mean sitting well above the median means a
    minority of very slow loads is pulling the average up, and those loads are
    somebody's real experience.
  </p>

  <p>
    The largest single source of recoverable time is
    <strong><?= e($winnerLabel) ?></strong>, at
    <?= e(fmt_ms($opp->summary['winner_savings_per_view'])) ?> per pageview.
    <?= e(Phases::explain((string) $winner)) ?>
    <?php if ($decisive): ?>
      It leads the next candidate by <?= e(number_format($margin, 1)) ?>×, which is a
      wide enough margin to act on without further measurement.
    <?php else: ?>
      It does <em>not</em> lead decisively, so the honest reading is that there are
      two comparable candidates rather than one clear answer.
    <?php endif; ?>
  </p>

<?php if (($cohort->summary['ratio'] ?? null) !== null && $cohort->summary['ratio'] > 2): ?>
  <p>
    A first visit costs <?= e(number_format($cohort->summary['ratio'], 1)) ?>× what a
    return visit costs. That ratio matters more than it looks for a club site: a
    returning member with a warm cache is not the person deciding whether the site is
    worth using. Anyone arriving from a search result or a shared link pays the
    <?= e(fmt_ms($cohort->summary['cold_median'])) ?> figure, every time, and it is
    the number that should be optimised against.
  </p>
<?php endif; ?>

<?php if ($weight->rows !== []): $top = $weight->rows[0]; ?>
  <p>
    The heaviest single resource is <code><?= e($top['short']) ?></code> at
    <?= e(fmt_bytes($top['body_bytes'])) ?>, requested
    <?= e(fmt_int((float) $top['requests'])) ?> times for
    <?= e(fmt_bytes($top['transferred_bytes'])) ?> transferred in total.
<?php if (($weight->summary['blocking_count'] ?? 0) > 0): ?>
    <?= e((string) $weight->summary['blocking_count']) ?> of the resources listed are
    render-blocking, meaning the browser will not paint until they finish.
<?php endif; ?>
  </p>
<?php endif; ?>

  <h2 style="font-size:16px;margin-top:22px">Analyst comment</h2>
  <p>
    The method here is deliberately conservative. Every target is a level this site
    has already reached on its own best loads, on its own hardware, so no estimate
    depends on a benchmark borrowed from somewhere else, and none of it needs
    revisiting when the club site replaces the test site. The reason to prefer that
    over an industry threshold is that a threshold invites arguing about the
    threshold; "you already do this, 10% of the time" does not.
  </p>
  <p>
    What this analysis cannot see is worth stating plainly. It measures the phases a
    load is divided into, so it will always name a phase — it cannot tell you that the
    real problem is a page nobody should have to load at all. It assumes the fast case
    is reachable from the slow case, which holds for compression and caching but not
    for a visitor on a genuinely poor connection. And it says nothing about whether
    any of this changes behaviour: the platform records what visitors do, so the next
    question worth building is whether slow loads actually cost engagement.
  </p>
</div>

<?php layout_footer(); ?>
