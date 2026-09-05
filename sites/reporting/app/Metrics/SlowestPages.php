<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Which pages are slowest, and what is slow about each of them?
 *
 * The grid, and the one metric here that reports MEDIAN rather than mean: this
 * table answers "what does a visit to this page feel like", and a single 8-second
 * outlier should not be allowed to describe a page that is usually fine. p90 sits
 * beside it so a page that is usually fine but occasionally terrible cannot hide
 * behind its median.
 *
 * The dominant-phase column is per page, not global. A site does not have to have
 * one problem: the homepage can be image-bound while checkout is server-bound, and
 * a single site-wide verdict would average that distinction away.
 */
final class SlowestPages implements Metric
{
    public function id(): string { return 'slowest-pages'; }
    public function title(): string { return 'Pages ranked by load cost'; }
    public function section(): string { return 'performance'; }

    public function question(): string
    {
        return 'Which pages cost visitors the most time, and is it the same thing '
             . 'slowing each of them down?';
    }

    public function compute(Filters $f): MetricResult
    {
        $rows = PageviewSet::fetch($f);
        $byPage = [];
        foreach ($rows as $r) {
            $byPage[$r['page']][] = $r;
        }

        $out = [];
        foreach ($byPage as $page => $group) {
            $totals = array_column($group, 'total_ms');

            // Mean per phase for this page, so the dominant phase is chosen on the
            // same basis as the site-wide breakdown.
            $phaseMeans = [];
            foreach (array_keys(Phases::labels()) as $k) {
                $phaseMeans[$k] = Stats::mean(array_column(array_column($group, 'phases'), $k)) ?? 0.0;
            }
            arsort($phaseMeans);
            $dominant = array_key_first($phaseMeans);

            $cold = array_values(array_filter($group, static fn($r) => $r['cache_state'] === 'cold'));
            $warm = array_values(array_filter($group, static fn($r) => $r['cache_state'] === 'warm'));

            $out[] = [
                'page'            => $page,
                'n'               => count($group),
                'median_ms'       => Stats::median($totals),
                'p90_ms'          => Stats::percentile($totals, 0.9),
                'cold_median_ms'  => Stats::median(array_column($cold, 'total_ms')),
                'warm_median_ms'  => Stats::median(array_column($warm, 'total_ms')),
                'dominant_phase'  => $dominant,
                'dominant_label'  => Phases::label($dominant),
                'dominant_ms'     => $phaseMeans[$dominant],
                'dominant_share'  => array_sum($phaseMeans) > 0
                    ? $phaseMeans[$dominant] / array_sum($phaseMeans) : null,
                'total_time_ms'   => Stats::sum($totals),
            ];
        }

        // By total time spent, not by median: a slow page nobody visits costs less
        // than a middling page everybody does, and an optimisation budget follows
        // the aggregate.
        usort($out, static fn($a, $b) => $b['total_time_ms'] <=> $a['total_time_ms']);

        return new MetricResult(
            rows: $out,
            summary: [
                'pages' => count($out),
                'slowest_median' => $out === [] ? null : max(array_column($out, 'median_ms')),
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
