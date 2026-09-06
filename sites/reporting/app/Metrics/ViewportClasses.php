<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * What size of screen is the site actually being looked at on?
 *
 * Window width, not screen width: a 27-inch monitor with the browser at half
 * width is a laptop-sized viewport, and layout responds to the window.
 *
 * The verdict is a design target: the class that carries the most pageviews, and
 * the narrowest class that still carries a meaningful share — the one a layout
 * must not break on.
 */
final class ViewportClasses implements Metric
{
    public const MEANINGFUL = 0.10;

    public function id(): string { return 'viewport-classes'; }
    public function title(): string { return 'Viewport sizes'; }
    public function section(): string { return 'audience'; }

    public function question(): string
    {
        return 'How wide is the window the site is being read in — and which size must the layout never break on?';
    }

    public function compute(Filters $f): MetricResult
    {
        $all  = AudienceSet::fetch($f);
        $rows = array_values(array_filter($all, static fn($r) => $r['viewport'] !== null));
        $n = count($rows);

        $out = [];
        foreach (AudienceSet::CLASSES as $k => $c) {
            $g = array_filter($rows, static fn($r) => $r['viewport'] === $k);
            $widths = array_map(static fn($r) => (float) $r['window_width'], $g);
            $out[] = [
                'class'        => $k,
                'label'        => $c['label'] . ' (' . $c['note'] . ')',
                'axis'         => $c['axis'],
                'n'            => count($g),
                'share'        => $n ? count($g) / $n : null,
                'median_width' => Stats::median($widths),
                'sessions'     => count(array_unique(array_column($g, 'session_id'))),
            ];
        }

        $byShare = $out;
        usort($byShare, static fn($a, $b) => $b['n'] <=> $a['n']);
        $dominant = $byShare[0]['n'] > 0 ? $byShare[0] : null;
        $narrowest = null;
        foreach ($out as $r) {                       // narrowest first, by construction
            if (($r['share'] ?? 0) >= self::MEANINGFUL) { $narrowest = $r; break; }
        }

        return new MetricResult(
            rows: $out,
            summary: [
                'pageviews'       => $n,
                'dominant'        => $dominant['class'] ?? null,
                'dominant_label'  => $dominant ? AudienceSet::CLASSES[$dominant['class']]['label'] : null,
                'dominant_share'  => $dominant['share'] ?? null,
                'narrowest'       => $narrowest['class'] ?? null,
                'narrowest_label' => $narrowest ? AudienceSet::CLASSES[$narrowest['class']]['label'] : null,
                'narrowest_share' => $narrowest['share'] ?? null,
                'phone_share'     => $out[0]['share'] ?? null,
                'no_width_rows'   => count($all) - $n,
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
