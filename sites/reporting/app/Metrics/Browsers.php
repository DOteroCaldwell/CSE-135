<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Browser family by operating system: the grid a front-end developer reads
 * before deciding what they are allowed to use.
 */
final class Browsers implements Metric
{
    public function id(): string { return 'browsers'; }
    public function title(): string { return 'Browsers and platforms'; }
    public function section(): string { return 'audience'; }

    public function question(): string
    {
        return 'Which browser engines and operating systems does the site have to work on?';
    }

    public function compute(Filters $f): MetricResult
    {
        $rows = AudienceSet::fetch($f);
        $n = count($rows);

        $combo = [];
        foreach ($rows as $r) {
            $k = $r['browser'] . '|' . $r['os'];
            $combo[$k] ??= ['browser' => $r['browser'], 'os' => $r['os'], 'n' => 0,
                            'widths' => [], 'sessions' => [], 'hidpi' => 0];
            $combo[$k]['n']++;
            if ($r['window_width'] !== null) { $combo[$k]['widths'][] = (float) $r['window_width']; }
            $combo[$k]['sessions'][$r['session_id']] = true;
            if ($r['hidpi']) { $combo[$k]['hidpi']++; }
        }
        $out = [];
        foreach ($combo as $c) {
            $out[] = [
                'browser'      => $c['browser'],
                'os'           => $c['os'],
                'label'        => $c['browser'] . ' on ' . $c['os'],
                'n'            => $c['n'],
                'share'        => $c['n'] / $n,
                'sessions'     => count($c['sessions']),
                'median_width' => Stats::median($c['widths']),
                'hidpi_share'  => $c['hidpi'] / $c['n'],
            ];
        }
        usort($out, static fn($a, $b) => $b['n'] <=> $a['n']);

        $families = [];
        foreach ($rows as $r) { $families[$r['browser']] = ($families[$r['browser']] ?? 0) + 1; }
        arsort($families);

        return new MetricResult(
            rows: $out,
            summary: [
                'pageviews'   => $n,
                'families'    => $families,
                'top_browser' => array_key_first($families),
                'top_share'   => $n && $families !== [] ? reset($families) / $n : null,
                'webkit_share'=> $n ? ($families['Safari'] ?? 0) / $n : null,
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
