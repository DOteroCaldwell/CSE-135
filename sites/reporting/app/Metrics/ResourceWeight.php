<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Which individual files cost the most?
 *
 * This is what turns a phase-level verdict into an actionable one. Navigation
 * timing can prove the subresource tail is expensive; only resource timing can say
 * WHICH subresource, and "compress these four images" is a task whereas "the
 * subresource tail is 1.4 s" is an observation.
 *
 * Ranked by aggregate cost — bytes × how often the file is actually requested —
 * rather than by size alone. A 3 MB image on a page nobody visits is a smaller
 * problem than a 300 KB image on every page, and ranking by size would get that
 * backwards.
 *
 * transfer_size = 0 is ambiguous and is disambiguated here rather than left to the
 * reader: with a body present it means the file came from cache, with no body it
 * means a cross-origin response that did not send Timing-Allow-Origin. Only the
 * first is good news, and counting the second as "free" would understate real cost.
 */
final class ResourceWeight implements Metric
{
    public function id(): string { return 'resource-weight'; }
    public function title(): string { return 'Heaviest resources'; }
    public function section(): string { return 'performance'; }

    public function question(): string
    {
        return 'Which specific files are responsible for the bytes and the wait?';
    }

    public function compute(Filters $f): MetricResult
    {
        [$where, $params] = $f->where('r', 'server_ts');
        if (!$f->includeSynthetic) {
            // where() already added the EXISTS clause against sessions.
        }
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

        $rows = Db::all(
            "SELECT r.name,
                    r.initiator_type,
                    COUNT(*)                                   AS requests,
                    COUNT(DISTINCT r.pageview_id)              AS pageviews,
                    AVG(NULLIF(r.duration_ms, 0))              AS avg_duration_ms,
                    SUM(r.duration_ms)                         AS total_duration_ms,
                    MAX(r.decoded_body_size)                   AS body_bytes,
                    SUM(r.transfer_size)                       AS transferred_bytes,
                    SUM(CASE WHEN r.transfer_size = 0 AND r.decoded_body_size > 0
                             THEN 1 ELSE 0 END)                AS cache_hits,
                    SUM(CASE WHEN r.transfer_size = 0 AND COALESCE(r.decoded_body_size,0) = 0
                             THEN 1 ELSE 0 END)                AS opaque,
                    MAX(r.render_blocking_status)              AS blocking
               FROM resources r
              WHERE $whereSql
              GROUP BY r.name, r.initiator_type
              ORDER BY transferred_bytes DESC, total_duration_ms DESC
              LIMIT 40",
            $params
        );

        $totalBytes = 0.0;
        $totalTime  = 0.0;
        foreach ($rows as &$r) {
            $r['requests']          = (int) $r['requests'];
            $r['pageviews']         = (int) $r['pageviews'];
            $r['body_bytes']        = (float) $r['body_bytes'];
            $r['transferred_bytes'] = (float) $r['transferred_bytes'];
            $r['total_duration_ms'] = (float) $r['total_duration_ms'];
            $r['avg_duration_ms']   = $r['avg_duration_ms'] === null ? null : (float) $r['avg_duration_ms'];
            $r['cache_hit_rate']    = $r['requests'] > 0 ? (int) $r['cache_hits'] / $r['requests'] : null;
            $r['is_blocking']       = $r['blocking'] === 'blocking';
            $r['short']             = self::shorten((string) $r['name']);
            $totalBytes += $r['transferred_bytes'];
            $totalTime  += $r['total_duration_ms'];
        }
        unset($r);

        $byType = [];
        foreach ($rows as $r) {
            $t = $r['initiator_type'] ?: 'other';
            $byType[$t] = ($byType[$t] ?? 0) + $r['transferred_bytes'];
        }
        arsort($byType);

        return new MetricResult(
            rows: $rows,
            summary: [
                'total_transferred' => $totalBytes,
                'total_duration'    => $totalTime,
                'by_type'           => $byType,
                'heaviest_type'     => $byType === [] ? null : array_key_first($byType),
                'blocking_count'    => count(array_filter($rows, static fn($r) => $r['is_blocking'])),
            ],
            coverage: MetricResult::coverage(
                pageviews: (int) (Db::one("SELECT COUNT(DISTINCT pageview_id) n FROM resources r WHERE $whereSql", $params)['n'] ?? 0),
                caveats: $rows === []
                    ? ['No resource timing collected yet for these filters.']
                    : [],
            ),
        );
    }

    /** Trim a URL to its filename for display; the full URL stays in a title. */
    private static function shorten(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $base = basename($path);
        return $base !== '' ? $base : $path;
    }
}
