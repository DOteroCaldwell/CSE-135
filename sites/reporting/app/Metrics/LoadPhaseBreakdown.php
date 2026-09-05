<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Where does load time go, phase by phase, for each page?
 *
 * Uses the MEAN of each phase, not the median, and that is a deliberate statistical
 * choice rather than laziness.
 *
 * Means are additive — mean(a) + mean(b) = mean(a+b) — so a stacked bar of phase
 * means adds up exactly to mean total load. Medians are not additive: a stack of
 * nine phase medians sums to something that is not the median total, and the bar
 * would silently be a picture of a pageview that never happened.
 *
 * The mean is also the right question here. "Which phase should we fix" is about
 * total time spent across all visits, and total time is what the mean summarises.
 * The median matters for "what does a typical visit feel like", so SlowestPages
 * reports that instead.
 */
final class LoadPhaseBreakdown implements Metric
{
    public function id(): string { return 'load-phase-breakdown'; }
    public function title(): string { return 'Where load time goes'; }
    public function section(): string { return 'performance'; }

    public function question(): string
    {
        return 'Of the time a visitor spends waiting for a page, which part of the '
             . 'load is consuming it?';
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
            $entry = ['page' => $page, 'n' => count($group), 'phases' => [], 'total' => 0.0];
            foreach (array_keys(Phases::labels()) as $k) {
                $mean = Stats::mean(array_column(array_column($group, 'phases'), $k)) ?? 0.0;
                $entry['phases'][$k] = $mean;
                $entry['total'] += $mean;
            }
            $out[] = $entry;
        }

        // Slowest first: the page with the most time to give back leads.
        usort($out, static fn($a, $b) => $b['total'] <=> $a['total']);

        // Site-wide totals, for the report's headline.
        $overall = [];
        foreach (array_keys(Phases::labels()) as $k) {
            $overall[$k] = Stats::mean(array_column(array_column($rows, 'phases'), $k)) ?? 0.0;
        }
        arsort($overall);

        return new MetricResult(
            rows: $out,
            summary: [
                'overall_phase_means' => $overall,
                'overall_total'       => array_sum($overall),
                'dominant_phase'      => array_key_first($overall),
                'max_page_total'      => $out === [] ? 0.0 : $out[0]['total'],
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
