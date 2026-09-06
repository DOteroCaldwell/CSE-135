<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * The behaviour grid: one row per page, the handful of numbers that describe what
 * a visit to it is like.
 *
 * Medians throughout. A single visitor who left a tab open overnight would
 * otherwise own the "time on page" column for the whole site.
 */
final class EngagementByPage implements Metric
{
    public function id(): string { return 'engagement-by-page'; }
    public function title(): string { return 'Engagement by page'; }
    public function section(): string { return 'behaviour'; }

    public function question(): string
    {
        return 'Page by page: how long do people stay, how much do they do, and how '
             . 'often does something break?';
    }

    public function compute(Filters $f): MetricResult
    {
        $rows = ActivitySet::fetch($f);
        $byPage = [];
        foreach ($rows as $r) {
            $byPage[$r['page']][] = $r;
        }

        $out = [];
        foreach ($byPage as $page => $g) {
            $n = count($g);
            $times = array_column($g, 'time_on_page_ms');
            $withErr = count(array_filter($g, static fn($r) => $r['errors'] > 0));
            $out[] = [
                'page'            => $page,
                'n'               => $n,
                'median_time_ms'  => Stats::median($times),
                'p90_time_ms'     => Stats::percentile($times, 0.9),
                'median_depth'    => Stats::median(array_column($g, 'depth')),
                'clicks_per_view' => Stats::mean(array_column($g, 'clicks')),
                'idle_share'      => Stats::median(array_column($g, 'idle_share')),
                'bounce_rate'     => count(array_filter($g, static fn($r) => $r['bounced'])) / $n,
                'error_rate'      => $withErr / $n,
                'total_time_ms'   => Stats::sum($times),
            ];
        }
        usort($out, static fn($a, $b) => $b['n'] <=> $a['n']);

        $times = array_column($rows, 'time_on_page_ms');
        $n = count($rows);

        return new MetricResult(
            rows: $out,
            summary: [
                'pageviews'       => $n,
                'median_time_ms'  => Stats::median($times),
                'bounce_rate'     => $n ? count(array_filter($rows, static fn($r) => $r['bounced'])) / $n : null,
                'clicks_per_view' => Stats::mean(array_column($rows, 'clicks')),
                'idle_share'      => Stats::median(array_column($rows, 'idle_share')),
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
