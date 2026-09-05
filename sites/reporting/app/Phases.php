<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Decomposition of a page load into the phases PerformanceNavigationTiming marks.
 *
 * This is the backbone of the whole platform: "where does the time go" is answered
 * by splitting one number (total load) into nine that sum back to it, and every
 * other metric is a slice or a ranking of these.
 *
 * The nine boundaries come straight from the Navigation Timing timeline, in order:
 *
 *   redirectStart .. redirectEnd            redirects before us
 *   domainLookupStart .. domainLookupEnd    DNS
 *   connectStart .. connectEnd              TCP + TLS
 *   requestStart .. responseStart           waiting on the server  (TTFB)
 *   responseStart .. responseEnd            pulling the document down
 *   responseEnd .. domInteractive           parsing + render-blocking scripts
 *   dCLStart .. dCLEnd                      DOMContentLoaded handlers
 *   dCLEnd .. loadEventStart                SUBRESOURCES: images, late scripts
 *   loadEventStart .. loadEventEnd          load handlers
 *
 * Nothing here encodes an expectation about which phase is large. That is the
 * point — the ranking is computed, so the platform can name a bottleneck nobody
 * anticipated, including on a future version of the site that shares none of this
 * one's problems.
 */
final class Phases
{
    /** key => [label, startMark, endMark, one-line explanation for the report] */
    public const DEFS = [
        'redirect' => ['Redirects', 'redirectStart', 'redirectEnd',
            'Time spent following redirects before this page began loading.'],
        'dns' => ['DNS lookup', 'domainLookupStart', 'domainLookupEnd',
            'Resolving the hostname to an IP address.'],
        'tcp' => ['Connect + TLS', 'connectStart', 'connectEnd',
            'Opening the TCP connection and completing the TLS handshake.'],
        'ttfb' => ['Server wait (TTFB)', 'requestStart', 'responseStart',
            'Request sent, waiting for the server to start replying. This is the only phase the server controls directly.'],
        'download' => ['Document download', 'responseStart', 'responseEnd',
            'Receiving the HTML document itself — not its images or scripts.'],
        'dom' => ['DOM processing', 'responseEnd', 'domInteractive',
            'Parsing the HTML. Synchronous scripts in the <head> block here, which is why script placement shows up in this phase.'],
        'dcl' => ['DOMContentLoaded handlers', 'domContentLoadedEventStart', 'domContentLoadedEventEnd',
            'Running whatever the page registered on DOMContentLoaded.'],
        'tail' => ['Subresource tail', 'domContentLoadedEventEnd', 'loadEventStart',
            'Waiting for images, stylesheets and deferred scripts to finish. Page weight lands here.'],
        'loadevt' => ['Load handlers', 'loadEventStart', 'loadEventEnd',
            'Running whatever the page registered on the load event.'],
    ];

    /**
     * Anything the nine phases do not account for.
     *
     * Small gaps exist in the timeline by construction — fetchStart to
     * domainLookupStart, domInteractive to domContentLoadedEventStart — and they
     * are usually near zero. Carrying the remainder explicitly means the stacked
     * bar always sums to the real total instead of quietly losing milliseconds, and
     * if this bucket is ever large that is itself a finding worth seeing.
     */
    public const OTHER_KEY   = 'other';
    public const OTHER_LABEL = 'Unattributed';

    public static function labels(): array
    {
        $out = [];
        foreach (self::DEFS as $k => $d) {
            $out[$k] = $d[0];
        }
        $out[self::OTHER_KEY] = self::OTHER_LABEL;
        return $out;
    }

    public static function label(string $key): string
    {
        return $key === self::OTHER_KEY
            ? self::OTHER_LABEL
            : (self::DEFS[$key][0] ?? $key);
    }

    public static function explain(string $key): string
    {
        return $key === self::OTHER_KEY
            ? 'Timeline gaps not covered by the named phases.'
            : (self::DEFS[$key][3] ?? '');
    }

    /**
     * Read one numeric field out of the nav_timing JSON column.
     *
     * `JSON_EXTRACT(...) + 0` rather than JSON_VALUE(... RETURNING DOUBLE):
     * JSON_VALUE needs MySQL 8.0.21, and this has to run against whatever 8.x the
     * droplet happens to have. The `+ 0` coercion works on every 8.x.
     */
    public static function num(string $col, string $field): string
    {
        return "COALESCE(JSON_EXTRACT($col, '$.$field') + 0, 0)";
    }

    /**
     * Duration of one phase, in ms.
     *
     * GREATEST(...,0) because marks that never happened are reported as 0, and
     * `0 - responseEnd` would otherwise contribute a large negative number and
     * silently shrink the total. A phase that did not occur is zero, not negative.
     */
    public static function expr(string $key, string $col = 'nav_timing'): string
    {
        [, $start, $end] = self::DEFS[$key];
        return 'GREATEST(0, ' . self::num($col, $end) . ' - ' . self::num($col, $start) . ')';
    }

    /** Sum of the nine named phases — used to derive the unattributed remainder. */
    public static function sumExpr(string $col = 'nav_timing'): string
    {
        $parts = [];
        foreach (array_keys(self::DEFS) as $k) {
            $parts[] = self::expr($k, $col);
        }
        return '(' . implode(' + ', $parts) . ')';
    }

    /**
     * Total load time, preferring the collector's own figure.
     *
     * total_load_ms is computed client-side as loadEventEnd - startTime, which is
     * the definition the assignment asks for. Falling back to the timeline keeps
     * rows usable when that column is null.
     */
    public static function totalExpr(string $col = 'nav_timing'): string
    {
        return 'COALESCE(NULLIF(total_load_ms, 0), ' . self::num($col, 'loadEventEnd') . ')';
    }

    /**
     * Cold (first visit) vs warm (something was cached).
     *
     * deliveryType is authoritative where the browser sets it. transferSize == 0 is
     * the fallback: a document served from cache crosses no wire. The two disagree
     * only on browsers that do not implement deliveryType, where the fallback is
     * still correct.
     */
    public static function cacheStateExpr(string $col = 'nav_timing'): string
    {
        return "CASE
                  WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT($col, '$.deliveryType')), '') = 'cache'
                    THEN 'warm'
                  WHEN " . self::num($col, 'transferSize') . " = 0 THEN 'warm'
                  ELSE 'cold'
                END";
    }
}
