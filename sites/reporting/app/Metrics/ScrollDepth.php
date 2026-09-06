<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * How far down each page do visitors actually get?
 *
 * Rendered per page as a stacked bar of four depth bands, so the shape of the bar
 * IS the answer: a page whose bar is mostly "top quarter only" is a page people
 * open and abandon, whatever its load time says.
 *
 * Bands rather than a mean depth because the distribution is usually bimodal —
 * people either leave at once or read to the end — and a mean of 55% describes
 * nobody.
 */
final class ScrollDepth implements Metric
{
    public const BANDS = [
        'q1' => ['label' => 'Top quarter only', 'max' => 0.25],
        'q2' => ['label' => 'To halfway',       'max' => 0.50],
        'q3' => ['label' => 'To three quarters','max' => 0.75],
        'q4' => ['label' => 'To the bottom',    'max' => 1.01],
    ];

    public function id(): string { return 'scroll-depth'; }
    public function title(): string { return 'How far visitors scroll'; }
    public function section(): string { return 'behaviour'; }

    public function question(): string
    {
        return 'On each page, how much of the content does a visitor actually see '
             . 'before leaving?';
    }

    public static function band(float $depth): string
    {
        foreach (self::BANDS as $k => $b) {
            if ($depth < $b['max']) { return $k; }
        }
        return 'q4';
    }

    public static function labels(): array
    {
        return array_map(static fn($b) => $b['label'], self::BANDS);
    }

    public function compute(Filters $f): MetricResult
    {
        $all  = ActivitySet::fetch($f);
        $rows = array_values(array_filter($all, static fn($r) => $r['depth'] !== null));

        $byPage = [];
        foreach ($rows as $r) {
            $byPage[$r['page']][] = $r;
        }

        $out = [];
        foreach ($byPage as $page => $group) {
            $parts = array_fill_keys(array_keys(self::BANDS), 0);
            foreach ($group as $r) {
                $parts[self::band((float) $r['depth'])]++;
            }
            $depths = array_column($group, 'depth');
            $out[] = [
                'page'          => $page,
                'n'             => count($group),
                'parts'         => $parts,
                'median_depth'  => Stats::median($depths),
                'reached_half'  => count(array_filter($depths, static fn($d) => $d >= 0.5)) / count($group),
                'reached_end'   => count(array_filter($depths, static fn($d) => $d >= 0.75)) / count($group),
            ];
        }
        usort($out, static fn($a, $b) => $b['n'] <=> $a['n']);

        $depths = array_column($rows, 'depth');
        $n = count($rows);

        // The page people give up on: lowest median depth among pages with enough
        // views for a median to mean anything.
        $candidates = array_filter($out, static fn($r) => $r['n'] >= 5);
        usort($candidates, static fn($a, $b) => $a['median_depth'] <=> $b['median_depth']);
        $worst = $candidates[0] ?? null;

        return new MetricResult(
            rows: $out,
            summary: [
                'pageviews'     => $n,
                'median_depth'  => Stats::median($depths),
                'reached_half'  => $n ? count(array_filter($depths, static fn($d) => $d >= 0.5)) / $n : null,
                'reached_end'   => $n ? count(array_filter($depths, static fn($d) => $d >= 0.75)) / $n : null,
                'worst_page'    => $worst['page'] ?? null,
                'worst_median'  => $worst['median_depth'] ?? null,
                'no_depth_rows' => count($all) - $n,
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
