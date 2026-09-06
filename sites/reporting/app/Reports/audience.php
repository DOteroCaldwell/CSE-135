<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * CSE 135 HW5 — "Audience", the environment report body.
 *
 * GUIDING QUESTION
 *   Who is visiting, and what can their devices and browsers actually handle?
 *
 * Four movements:
 *   1. Screens        (viewport classes — the design target)
 *   2. Browsers       (engine and platform)
 *   3. Languages      (what the browser asks to read in)
 *   4. Capabilities   (cookies, images, CSS, DPI, connection)
 *
 * The verdict is a design target, computed: the viewport class carrying most
 * pageviews, and the narrowest one that still carries a meaningful share.
 */

function render_report_audience(Filters $f): void
{
    $vp   = MetricRegistry::get('viewport-classes')->compute($f);
    $br   = MetricRegistry::get('browsers')->compute($f);
    $lang = MetricRegistry::get('languages')->compute($f);
    $cap  = MetricRegistry::get('capabilities')->compute($f);

    if ($br->isEmpty()) {
        report_empty('This report needs static pageview rows (screen, language, capability '
            . 'probes). Browse the instrumented site, or widen the filters.');
        return;
    }

    $n    = $br->summary['pageviews'];
    $thin = $n < Coverage::MIN_PAGEVIEWS;
?>

<!-- ============================ THE ANSWER ============================== -->
<section class="verdict">
  <h2>The answer, as the data currently stands</h2>
<?php if (!empty($vp->summary['dominant_label'])): ?>
  <p class="headline">
    Design for <?= e($vp->summary['dominant_label']) ?>
    (<?= e(fmt_pct($vp->summary['dominant_share'])) ?> of pageviews)<?php
    if (!empty($vp->summary['narrowest_label']) && $vp->summary['narrowest'] !== $vp->summary['dominant']): ?>,
    and never break on <?= e($vp->summary['narrowest_label']) ?>
    (<?= e(fmt_pct($vp->summary['narrowest_share'])) ?>)<?php endif; ?>
  </p>
<?php endif; ?>
  <p>
    <strong><?= e((string) $br->summary['top_browser']) ?></strong> is the most common
    browser at <?= e(fmt_pct($br->summary['top_share'])) ?><?php
    if ($br->summary['top_browser'] !== 'Safari'): ?>;
    <?= e(fmt_pct($br->summary['webkit_share'])) ?> of pageviews are Safari, the engine
    with the most divergent feature support<?php else: ?>, and Safari is the engine
    with the most divergent feature support<?php endif; ?>.
    <?= e(strtoupper((string) $lang->summary['top'])) ?> is the leading language at
    <?= e(fmt_pct($lang->summary['top_share'])) ?>, and
    <?= e(fmt_pct($lang->summary['non_english'])) ?> of visitors ask for something other
    than English.
<?php if (($cap->summary['slow_share'] ?? 0) > 0): ?>
    <?= e(fmt_pct($cap->summary['slow_share'])) ?> report a 3G-class connection or worse.
<?php endif; ?>
  </p>
<?php if ($thin): ?>
  <p class="notice notice-error" style="margin:10px 0 0">
    <strong>Thin data.</strong> <?= e((string) $n) ?> pageviews. Audience shares move a
    lot with each new device until there are a few hundred.
  </p>
<?php endif; ?>
</section>

<!-- ============================ 1. SCREENS ============================== -->
<section class="card">
  <h2>1. Screens</h2>
  <p class="card-question"><?= e(MetricRegistry::get('viewport-classes')->question()) ?>
     Window width, not screen width: layout responds to the window.</p>
  <div class="grid-2">
    <div>
<?php
    chart_column_multi(array_map(static fn($r) => [
        'label' => $r['label'], 'axis' => $r['axis'], 'n' => $r['n'],
    ], $vp->rows), ['n' => 'Pageviews'], [
        'caption'     => 'Pageviews by viewport width class.',
        'colorOffset' => 2,
    ]);
?>
    </div>
    <div>
<?php
    data_table([
        'label'        => ['label' => 'Class'],
        'n'            => ['label' => 'Pageviews', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'share'        => ['label' => 'Share', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
        'sessions'     => ['label' => 'Sessions', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'median_width' => ['label' => 'Median width', 'num' => true, 'fmt' => static fn($v) => $v === null ? '—' : number_format((float) $v) . ' px'],
    ], $vp->rows);
?>
    </div>
  </div>
<?php if (($vp->summary['no_width_rows'] ?? 0) > 0): ?>
  <p class="card-question" style="margin-top:14px"><?= e((string) $vp->summary['no_width_rows']) ?> pageviews reported no window width and are left out.</p>
<?php endif; ?>
  <?php coverage_badge($vp->coverage); ?>
</section>

<!-- ============================ 2. BROWSERS ============================= -->
<section class="card">
  <h2>2. Browsers and platforms</h2>
  <p class="card-question"><?= e(MetricRegistry::get('browsers')->question()) ?>
     Coarse by design — family and OS — because that is the granularity a "can we use
     this CSS feature" decision is made at, and the only one that survives UA changes.</p>
  <div class="grid-2">
    <div>
<?php
    $fam = [];
    foreach ($br->summary['families'] as $k => $c) { $fam[] = ['label' => $k, 'value' => (float) $c]; }
    chart_ranked_bar($fam, [
        'caption' => 'Pageviews by browser family.',
        'format'  => static fn($v) => fmt_int((float) $v),
        'uniform' => true,
    ]);
?>
    </div>
    <div>
<?php
    data_table([
        'label'        => ['label' => 'Browser on OS'],
        'n'            => ['label' => 'Pageviews', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'share'        => ['label' => 'Share', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
        'sessions'     => ['label' => 'Sessions', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'median_width' => ['label' => 'Median width', 'num' => true, 'fmt' => static fn($v) => $v === null ? '—' : number_format((float) $v) . ' px'],
        'hidpi_share'  => ['label' => 'Hi-DPI', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
    ], array_slice($br->rows, 0, 12));
?>
    </div>
  </div>
  <?php coverage_badge($br->coverage); ?>
</section>

<!-- ============================ 3. LANGUAGES ============================ -->
<section class="card">
  <h2>3. Languages</h2>
  <p class="card-question"><?= e(MetricRegistry::get('languages')->question()) ?></p>
  <div class="grid-2">
    <div>
<?php
    chart_ranked_bar(array_map(static fn($r) => [
        'label' => $r['label'], 'value' => (float) $r['n'],
    ], array_slice($lang->rows, 0, 8)), [
        'caption' => 'Pageviews by language family.',
        'format'  => static fn($v) => fmt_int((float) $v),
        'uniform' => true,
        'labelWidth' => '64px',
    ]);
?>
    </div>
    <div>
<?php
    data_table([
        'label'    => ['label' => 'Language'],
        'n'        => ['label' => 'Pageviews', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'share'    => ['label' => 'Share', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
        'sessions' => ['label' => 'Sessions', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'tags'     => ['label' => 'Exact tags', 'wrap' => true],
    ], $lang->rows);
?>
    </div>
  </div>
  <?php coverage_badge($lang->coverage); ?>
</section>

<!-- ========================== 4. CAPABILITIES =========================== -->
<section class="card">
  <h2>4. Capabilities and connection</h2>
  <p class="card-question"><?= e(MetricRegistry::get('capabilities')->question()) ?></p>
  <div class="grid-2">
    <div>
<?php
    data_table([
        'capability' => ['label' => 'Capability', 'wrap' => true],
        'yes'        => ['label' => 'Yes', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'of'         => ['label' => 'Of', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'share'      => ['label' => 'Share', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
        'why'        => ['label' => 'Why it matters', 'wrap' => true],
    ], $cap->rows);
?>
    </div>
    <div>
      <h2 class="h2-sub">Connection class, as the browser reports it</h2>
<?php
    data_table([
        'type'  => ['label' => 'effectiveType'],
        'n'     => ['label' => 'Pageviews', 'num' => true, 'fmt' => static fn($v) => fmt_int((float) $v)],
        'share' => ['label' => 'Share', 'num' => true, 'fmt' => static fn($v) => fmt_pct($v)],
    ], $cap->summary['connections'] ?? []);
?>
      <p class="card-question" style="margin-top:14px">
        Chrome quantises this for fingerprinting resistance, so a wired desktop and a
        good phone both read <code>4g</code>. Only the slow classes carry information.
      </p>
<?php if (!empty($cap->summary['timezones'])): ?>
      <h2 class="h2-sub">Time zones (top 5)</h2>
      <p class="card-question"><?= e(implode(' · ', array_map(
          static fn($tz, $c) => "$tz ($c)", array_keys($cap->summary['timezones']), $cap->summary['timezones']))) ?></p>
<?php endif; ?>
    </div>
  </div>
  <?php coverage_badge($cap->coverage); ?>
</section>

<!-- ======================= WRITTEN DISCUSSION =========================== -->
<section class="card">
  <h2>Discussion</h2>
  <p>
    Across <strong><?= e(fmt_int((float) $n)) ?> pageviews</strong> in scope
    (<?= e($f->describe()) ?>),
<?php if (!empty($vp->summary['dominant_label'])): ?>
    <?= e($vp->summary['dominant_label']) ?>-class windows carry
    <?= e(fmt_pct($vp->summary['dominant_share'])) ?> of the traffic and
    phone-class windows <?= e(fmt_pct($vp->summary['phone_share'])) ?>.
<?php if (!empty($vp->summary['narrowest_label']) && $vp->summary['narrowest'] !== $vp->summary['dominant']): ?>
    The narrowest class above <?= e(fmt_pct(ViewportClasses::MEANINGFUL)) ?> is
    <?= e($vp->summary['narrowest_label']) ?>; that is the width at which a layout
    bug is seen by a meaningful share of real visitors, so it is the one to test on
    first, even though it is not the most common.
<?php endif; ?>
<?php endif; ?>
    <?= e(fmt_pct($cap->summary['hidpi_share'])) ?> of screens are high-DPI, which
    <?= ($cap->summary['hidpi_share'] ?? 0) >= 0.5 ? 'makes 2× image variants worth serving — and, for a site whose performance report keeps naming image weight, worth serving carefully' : 'means standard-resolution images are adequate for most visitors' ?>.
  </p>
  <p>
    <?= e((string) $br->summary['top_browser']) ?> leads at <?= e(fmt_pct($br->summary['top_share'])) ?><?=
    $br->summary['top_browser'] !== 'Safari' ? ', with Safari at ' . e(fmt_pct($br->summary['webkit_share'])) : '' ?>. Safari is the
    figure that matters for feature decisions: it is the engine that lags on new CSS
    and the one iOS forces on every browser, so whatever share it holds here is the
    floor on how much of the audience a WebKit-unsupported feature would exclude.
    <?= e(strtoupper((string) $lang->summary['top'])) ?> is the leading language;
    <?= e(fmt_pct($lang->summary['non_english'])) ?> of visitors ask for something else,
    <?= ($lang->summary['non_english'] ?? 0) >= 0.2 ? 'which is a share large enough that a second language on the key pages would be seen' : 'which is small enough that translation is not the first thing to spend on' ?>.
  </p>

  <h2 class="h2-sub" style="margin-top:22px">Analyst comment</h2>
  <p>
    The most important row in this report is the one it cannot show: visitors with
    JavaScript off. The collector is JavaScript, so they never appear here — the
    <code>&lt;noscript&gt;</code> pixel on each test page records them in the collector's
    access log instead, and reconciling that log against this table is a manual
    step, not a chart. A "100% JavaScript" figure is therefore a statement about the
    instrument, not the audience, and the capabilities table says so in its own row.
  </p>
  <p>
    Two other limits are worth stating. The browser family comes from a coarse parse
    of the user-agent string, which browsers are actively making less informative;
    the four-way split here is about as fine as it is safe to go. And the connection
    class is quantised by the browser, so "everyone is on 4g" is what a fingerprint-
    resistant API reports, not what the network is doing — the performance report's
    first-visit cost is the better proxy for a slow link.
  </p>
</section>
<?php
}
