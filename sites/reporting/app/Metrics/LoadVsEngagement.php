<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Does a slow load actually cost engagement?
 *
 * This is the question HW4's report ended on and could not answer, because it
 * only had performance data. Here the same pageviews are split into four cohorts
 * by their own load time, and each cohort's behaviour is compared: how far people
 * scrolled, how long they stayed, how many bounced.
 *
 * Quartiles of THIS dataset rather than fixed thresholds, for the same reason the
 * performance report uses its own p10: no borrowed number to argue about, and it
 * self-calibrates when the site changes.
 *
 * What it cannot show is causation. Slow loads and short visits can share a cause
 * (a phone on a poor connection does both). The report says so.
 */
final class LoadVsEngagement implements Metric
{
    public function id(): string { return 'load-vs-engagement'; }
    public function title(): string { return 'Load time vs engagement'; }
    public function section(): string { return 'behaviour'; }

    public function question(): string
    {
        return 'Do visitors who waited longer for the page do less with it once it arrives?';
    }

    public function compute(Filters $f): MetricResult
    {
        $rows = array_values(array_filter(
            ActivitySet::fetch($f),
            static fn($r) => $r['total_ms'] !== null && $r['total_ms'] > 0 && $r['depth'] !== null
        ));
        if (count($rows) < 4) {
            return new MetricResult([], ['pageviews' => count($rows)], Coverage::for($rows, $f));
        }

        usort($rows, static fn($a, $b) => $a['total_ms'] <=> $b['total_ms']);
        $n = count($rows);
        $labels = ['Fastest quarter', 'Second quarter', 'Third quarter', 'Slowest quarter'];
        $out = [];
        for ($q = 0; $q < 4; $q++) {
            $from = (int) floor($n * $q / 4);
            $to   = (int) floor($n * ($q + 1) / 4);
            $g    = array_slice($rows, $from, max(1, $to - $from));
            $loads = array_column($g, 'total_ms');
            $out[] = [
                'cohort'         => $labels[$q],
                'label'          => $labels[$q],
                'axis'           => ['Fastest', '2nd', '3rd', 'Slowest'][$q],
                'n'              => count($g),
                'load_lo'        => min($loads),
                'load_hi'        => max($loads),
                'median_load'    => Stats::median($loads),
                'median_depth'   => Stats::median(array_column($g, 'depth')),
                'reached_half'   => count(array_filter($g, static fn($r) => $r['depth'] >= 0.5)) / count($g),
                'median_time_ms' => Stats::median(array_column($g, 'time_on_page_ms')),
                'bounce_rate'    => count(array_filter($g, static fn($r) => $r['bounced'])) / count($g),
                'clicks_per_view'=> Stats::mean(array_column($g, 'clicks')),
            ];
        }

        $fast = $out[0]; $slow = $out[3];
        // Effect size on the one figure a reader can act on: share reaching halfway.
        $gap = $fast['reached_half'] - $slow['reached_half'];

        return new MetricResult(
            rows: $out,
            summary: [
                'pageviews'     => $n,
                'fast_half'     => $fast['reached_half'],
                'slow_half'     => $slow['reached_half'],
                'gap'           => $gap,
                'fast_bounce'   => $fast['bounce_rate'],
                'slow_bounce'   => $slow['bounce_rate'],
                'fast_load'     => $fast['median_load'],
                'slow_load'     => $slow['median_load'],
                // Monotonic decline across all four cohorts is a stronger signal
                // than a fast/slow gap alone, which two noisy quartiles can fake.
                'monotonic'     => $out[0]['reached_half'] >= $out[1]['reached_half']
                                && $out[1]['reached_half'] >= $out[2]['reached_half']
                                && $out[2]['reached_half'] >= $out[3]['reached_half'],
                'verdict'       => $gap >= 0.15 ? 'costs' : ($gap <= -0.15 ? 'inverse' : 'no-clear-link'),
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
