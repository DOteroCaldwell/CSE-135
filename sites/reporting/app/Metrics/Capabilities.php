<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * What can the visitor's browser actually do?
 *
 * The four yes/no probes the assignment asked the collector for, plus the ones
 * that turn out to matter for a design decision (high-DPI, Save-Data, connection
 * class). Each is a share of pageviews, with the count beside it so a 100% on
 * three rows cannot pass for a finding.
 *
 * One honest gap: the JavaScript row is always 100%, because a browser without
 * JavaScript never runs the collector. The <noscript> pixel on every test page
 * records those visits in the collector's ACCESS LOG, not in this database. The
 * report says so rather than showing a number that cannot be anything but 100.
 */
final class Capabilities implements Metric
{
    public function id(): string { return 'capabilities'; }
    public function title(): string { return 'Browser capabilities'; }
    public function section(): string { return 'audience'; }

    public function question(): string
    {
        return 'Can the site rely on cookies, images, CSS and a sharp screen — and how many visitors are on a constrained connection?';
    }

    public function compute(Filters $f): MetricResult
    {
        $rows = AudienceSet::fetch($f);
        $n = count($rows);
        if ($n === 0) {
            return new MetricResult([], [], Coverage::for($rows, $f));
        }

        $count = static fn(callable $p) => count(array_filter($rows, $p));
        $known = static fn(string $col) => count(array_filter($rows, static fn($r) => $r[$col] !== null));

        $out = [
            ['capability' => 'Cookies (round-trip test)', 'yes' => $count(static fn($r) => (int) $r['cookies_enabled'] === 1), 'of' => $known('cookies_enabled'),
             'why' => 'Sessions and the analytics sid depend on this.'],
            ['capability' => 'JavaScript', 'yes' => $count(static fn($r) => (int) $r['js_enabled'] === 1), 'of' => $known('js_enabled'),
             'why' => 'Always 100% here by construction; JS-off visits land in the collector access log via the <noscript> pixel.'],
            ['capability' => 'Images loading', 'yes' => $count(static fn($r) => (int) $r['images_enabled'] === 1), 'of' => $known('images_enabled'),
             'why' => 'Probed with a real request, not a data URI, so a blocked-images setting is caught.'],
            ['capability' => 'CSS applied (inline and linked)', 'yes' => $count(static fn($r) => (int) $r['css_enabled'] === 1), 'of' => $known('css_enabled'),
             'why' => 'Reader modes and text browsers fail this.'],
            ['capability' => 'High-DPI screen (2× or more)', 'yes' => $count(static fn($r) => $r['hidpi']), 'of' => $known('dpr'),
             'why' => 'Decides whether 2× image variants are worth serving.'],
            ['capability' => 'Save-Data requested', 'yes' => $count(static fn($r) => (string) $r['save_data'] === 'true' || (string) $r['save_data'] === '1'), 'of' => $known('save_data'),
             'why' => 'A visitor asking for less; the site can honour it.'],
        ];
        foreach ($out as &$r) {
            $r['share'] = $r['of'] > 0 ? $r['yes'] / $r['of'] : null;
        }
        unset($r);

        $conn = [];
        foreach ($rows as $r) {
            $k = $r['connection_type'] === null || $r['connection_type'] === '' ? 'not reported' : (string) $r['connection_type'];
            $conn[$k] = ($conn[$k] ?? 0) + 1;
        }
        arsort($conn);
        $connRows = [];
        foreach ($conn as $k => $c) {
            $connRows[] = ['type' => $k, 'n' => $c, 'share' => $c / $n];
        }

        $tz = [];
        foreach ($rows as $r) {
            if (!empty($r['timezone'])) { $tz[$r['timezone']] = ($tz[$r['timezone']] ?? 0) + 1; }
        }
        arsort($tz);

        return new MetricResult(
            rows: $out,
            summary: [
                'pageviews'     => $n,
                'connections'   => $connRows,
                'timezones'     => array_slice($tz, 0, 5, true),
                'cookies_share' => $out[0]['share'],
                'hidpi_share'   => $out[4]['share'],
                'slow_share'    => $n ? (($conn['slow-2g'] ?? 0) + ($conn['2g'] ?? 0) + ($conn['3g'] ?? 0)) / $n : null,
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
