<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Charts.css renderers.
 *
 * Every chart here is an HTML <table> that CSS draws as a chart. Three consequences
 * worth naming, because they are why this approach was chosen over a JS library:
 *
 *   - It renders with JavaScript disabled. There is no fallback path to maintain
 *     because there is no JavaScript.
 *   - A screen reader gets a real data table, in order, with headers.
 *   - The numbers are in the markup. "View source" on a chart shows the values,
 *     which for an analytics tool is a feature: a reader can check the picture
 *     against the data without an export step.
 *
 * The cost is that scales and bins are computed here rather than by a library.
 */

/** Palette index -> CSS variable, matching --color-N in app.css. */
function chart_color(int $i): string
{
    return 'var(--color-' . (($i % 9) + 1) . ')';
}

/**
 * Horizontal stacked bar: one row per category, one segment per series.
 *
 * @param array $rows    [['label'=>string, 'parts'=>[key=>float], 'total'=>float], ...]
 * @param array $series  key => label, in stacking order
 */
function chart_stacked_bar(array $rows, array $series, array $opts = []): void
{
    if ($rows === []) {
        echo '<p class="card-question">Nothing to chart yet.</p>';
        return;
    }
    // One shared scale across rows so bar lengths are comparable between pages.
    $max = max(array_map(static fn($r) => (float) $r['total'], $rows)) ?: 1.0;
    $unit = $opts['unit'] ?? 'ms';
    ?>
<div class="chart-wrap">
  <table class="charts-css bar multiple stacked show-labels" style="--labels-size:<?= e($opts['labelWidth'] ?? '190px') ?>;height:<?= count($rows) * 42 + 20 ?>px">
<?php if (!empty($opts['caption'])): ?>
    <caption class="sr-only"><?= e($opts['caption']) ?></caption>
<?php endif; ?>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row"><?= e($r['label']) ?></th>
<?php $i = 0; foreach ($series as $key => $label): $v = (float) ($r['parts'][$key] ?? 0); ?>
        <td style="--size:calc(<?= round($v, 3) ?>/<?= round($max, 3) ?>);--color:<?= chart_color($i) ?>">
          <span class="data"><?= $v > $max * 0.06 ? e(fmt_ms($v)) : '' ?></span>
          <span class="tooltip"><?= e($label . ': ' . fmt_ms($v)) ?></span>
        </td>
<?php $i++; endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
    chart_legend($series);
}

/**
 * Grouped column chart: one column group per bin, one bar per series.
 * Used for the cold/warm distribution, where comparing the two SHAPES is the point.
 */
function chart_column_multi(array $bins, array $series, array $opts = []): void
{
    if ($bins === []) {
        echo '<p class="card-question">Nothing to chart yet.</p>';
        return;
    }
    $max = 0.0;
    foreach ($bins as $b) {
        foreach (array_keys($series) as $k) {
            $max = max($max, (float) ($b[$k] ?? 0));
        }
    }
    $max = $max ?: 1.0;
    ?>
<div class="chart-wrap">
  <table class="charts-css column multiple show-labels show-primary-axis" style="height:260px">
<?php if (!empty($opts['caption'])): ?>
    <caption class="sr-only"><?= e($opts['caption']) ?></caption>
<?php endif; ?>
    <tbody>
<?php foreach ($bins as $b): ?>
      <tr>
        <th scope="row"><?= e($b['label']) ?></th>
<?php $i = 0; foreach ($series as $key => $label): $v = (float) ($b[$key] ?? 0); ?>
        <td style="--size:calc(<?= round($v, 3) ?>/<?= round($max, 3) ?>);--color:<?= chart_color($i + ($opts['colorOffset'] ?? 0)) ?>">
          <span class="data"><?= $v > 0 ? e((string) (int) $v) : '' ?></span>
          <span class="tooltip"><?= e($label . ': ' . (int) $v . ' pageviews') ?></span>
        </td>
<?php $i++; endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
    chart_legend($series, $opts['colorOffset'] ?? 0);
}

/**
 * Single-series horizontal bar, for rankings.
 *
 * @param array $rows [['label'=>string,'value'=>float,'note'=>?string], ...]
 */
function chart_ranked_bar(array $rows, array $opts = []): void
{
    if ($rows === []) {
        echo '<p class="card-question">Nothing to chart yet.</p>';
        return;
    }
    $max = max(array_map(static fn($r) => (float) $r['value'], $rows)) ?: 1.0;
    $fmt = $opts['format'] ?? 'fmt_ms';
    ?>
<div class="chart-wrap">
  <table class="charts-css bar show-labels" style="--labels-size:<?= e($opts['labelWidth'] ?? '210px') ?>;height:<?= count($rows) * 34 + 16 ?>px">
<?php if (!empty($opts['caption'])): ?>
    <caption class="sr-only"><?= e($opts['caption']) ?></caption>
<?php endif; ?>
    <tbody>
<?php foreach ($rows as $idx => $r): $v = (float) $r['value']; ?>
      <tr>
        <th scope="row"><?= e($r['label']) ?></th>
        <td style="--size:calc(<?= round($v, 3) ?>/<?= round($max, 3) ?>);--color:<?= chart_color($idx === 0 ? 2 : 4) ?>">
          <span class="data"><?= e($fmt($v)) ?></span>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
}

function chart_legend(array $series, int $offset = 0): void
{
    echo '<ul class="legend">';
    $i = 0;
    foreach ($series as $label) {
        echo '<li><span class="swatch" style="background:' . chart_color($i + $offset) . '"></span>'
           . e($label) . '</li>';
        $i++;
    }
    echo '</ul>';
}

/**
 * The coverage badge.
 *
 * Rendered next to every figure, not tucked into a footnote, because on this
 * dataset the sample size is frequently the most important thing on the screen.
 */
function coverage_badge(array $cov): void
{
    ?>
<div class="coverage">
  <span><b><?= e(fmt_int((float) ($cov['pageviews'] ?? 0))) ?></b> pageviews</span>
<?php if (!empty($cov['sessions'])): ?>
  <span><b><?= e(fmt_int((float) $cov['sessions'])) ?></b> sessions</span>
<?php endif; ?>
<?php if (!empty($cov['devices'])): ?>
  <span><b><?= e(fmt_int((float) $cov['devices'])) ?></b> device profiles</span>
<?php endif; ?>
<?php if (!empty($cov['first_seen'])): ?>
  <span><?= e(substr((string) $cov['first_seen'], 0, 10)) ?> – <?= e(substr((string) $cov['last_seen'], 0, 10)) ?></span>
<?php endif; ?>
<?php foreach ($cov['caveats'] ?? [] as $c): ?>
  <span class="caveat"><?= e($c) ?></span>
<?php endforeach; ?>
</div>
<?php
}

/**
 * Data grid.
 *
 * @param array $cols  key => ['label'=>string, 'num'=>bool, 'fmt'=>?callable, 'wrap'=>bool]
 */
function data_table(array $cols, array $rows, array $opts = []): void
{
    if ($rows === []) {
        echo '<p class="card-question">No rows match the current filters.</p>';
        return;
    }
    ?>
<div class="scroll-x">
<table class="data">
  <thead><tr>
<?php foreach ($cols as $c): ?>
    <th<?= !empty($c['num']) ? ' class="num"' : '' ?>><?= e($c['label']) ?></th>
<?php endforeach; ?>
  </tr></thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
<?php foreach ($cols as $key => $c):
        $raw = $r[$key] ?? null;
        $val = isset($c['fmt']) ? ($c['fmt'])($raw, $r) : (string) $raw;
        $cls = trim((!empty($c['num']) ? 'num ' : '') . (!empty($c['wrap']) ? 'wrap' : ''));
?>
      <td<?= $cls !== '' ? ' class="' . e($cls) . '"' : '' ?>><?= $c['raw'] ?? false ? $val : e($val) ?></td>
<?php endforeach; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php
}
