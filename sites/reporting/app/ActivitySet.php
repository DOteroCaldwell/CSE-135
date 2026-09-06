<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * One pageview per row, with its ACTIVITY rolled up: how long it was open, how
 * far it was scrolled, what was clicked, what broke, how much of it was idle.
 *
 * The behaviour metrics are all groupings of this one set, so — like PageviewSet
 * for performance — it is fetched once per request and memoised.
 *
 * SQL does the per-event aggregation (the part that must stay in the database as
 * the activity table grows; it is by far the largest table). PHP derives the
 * per-pageview quantities that need a second table, such as scroll depth, which
 * needs the viewport height from `static`.
 *
 * Joined to `performance` too, so "does a slow load cost engagement" can be asked
 * of the same rows. The cache-state filter is a performance concept and is not
 * applied here: an activity row has no cache state of its own.
 */
final class ActivitySet
{
    private static array $memo = [];

    /** Events that count as "the page broke", as the collector names them. */
    public const ERROR_EVENTS = ['error', 'unhandled-rejection'];

    /** A visit shorter than this is treated as a bounce. */
    public const BOUNCE_MS = 5000;

    public static function fetch(Filters $f): array
    {
        $key = $f->toQuery() ?: '(none)';
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        [$where, $params] = $f->where('a', 'server_ts');
        $where = array_merge(['a.pageview_id IS NOT NULL'], $where);

        // Per-pageview roll-up first, then the joins, so ONLY_FULL_GROUP_BY has
        // nothing to object to and the joins run once per pageview, not per event.
        $sql = 'SELECT x.*,
                       st.window_height, st.window_width, st.user_agent,
                       st.screen_width, st.screen_height,
                       ' . Phases::totalExpr('p.nav_timing') . ' AS total_ms
                  FROM (
                    SELECT a.pageview_id, a.session_id,
                           MIN(a.page)      AS page,
                           MIN(a.host)      AS host,
                           MIN(a.server_ts) AS server_ts,
                           COUNT(*)         AS events,
                           MAX(CASE WHEN a.event_type = \'pageleave\'
                                    THEN JSON_EXTRACT(a.detail, \'$.timeOnPageMs\') + 0 END) AS leave_ms,
                           MAX(a.occurred_at) - MIN(a.occurred_at)                  AS span_ms,
                           MAX(a.scroll_y)                                          AS max_scroll_y,
                           MAX(CASE WHEN a.event_type = \'scroll\'
                                    THEN JSON_EXTRACT(a.detail, \'$.maxY\') + 0 END)  AS page_height,
                           SUM(a.event_type = \'scroll\')                            AS scrolls,
                           SUM(a.event_type = \'click\')                             AS clicks,
                           SUM(a.event_type = \'keydown\')                           AS keys_pressed,
                           SUM(a.event_type IN (\'error\', \'unhandled-rejection\')) AS errors,
                           SUM(a.event_type = \'resource-error\')                    AS resource_errors,
                           SUM(CASE WHEN a.event_type = \'idle\'
                                    THEN COALESCE(a.idle_duration_ms, 0) ELSE 0 END) AS idle_ms,
                           MAX(a.event_type = \'pageleave\')                         AS has_leave
                      FROM activity a
                     WHERE ' . implode(' AND ', $where) . '
                     GROUP BY a.pageview_id, a.session_id
                  ) x
                  LEFT JOIN `static` st ON st.pageview_id = x.pageview_id
                  LEFT JOIN performance p ON p.pageview_id = x.pageview_id
                 ORDER BY x.server_ts DESC';

        $rows = Db::all($sql, $params);

        foreach ($rows as &$r) {
            $r['leave_ms']   = $r['leave_ms'] === null ? null : (float) $r['leave_ms'];
            $r['span_ms']    = $r['span_ms'] === null ? null : (float) $r['span_ms'];
            // Time on page: the collector's own figure from pageleave when it got
            // out; otherwise the span between first and last event, which is a
            // floor (the visitor was there at least that long).
            $r['time_on_page_ms'] = $r['leave_ms'] ?? $r['span_ms'];
            $r['idle_ms']    = (float) $r['idle_ms'];
            $r['errors']     = (int) $r['errors'];
            $r['resource_errors'] = (int) $r['resource_errors'];
            $r['clicks']     = (int) $r['clicks'];
            $r['scrolls']    = (int) $r['scrolls'];
            $r['total_ms']   = $r['total_ms'] === null ? null : (float) $r['total_ms'];

            /*
             * Scroll depth: how much of the page was ever on screen, as a fraction.
             * (deepest scrollY + viewport height) / document height. A page shorter
             * than the viewport is fully seen with no scrolling at all, which is why
             * "no scroll events" must not be read as "saw nothing".
             */
            $h  = (float) ($r['page_height'] ?? 0);
            $vh = (float) ($r['window_height'] ?? 0);
            $sy = (float) ($r['max_scroll_y'] ?? 0);
            if ($h > 0 && $vh > 0) {
                $r['depth'] = min(1.0, ($sy + $vh) / $h);
            } elseif ($h > 0) {
                $r['depth'] = min(1.0, $sy / $h);   // no viewport known: a floor
            } else {
                $r['depth'] = null;                 // no scroll data at all
            }

            $t = $r['time_on_page_ms'];
            $r['idle_share'] = ($t !== null && $t > 0) ? min(1.0, $r['idle_ms'] / $t) : null;
            $r['bounced']    = $t !== null && $t < self::BOUNCE_MS;

            $r['device_key'] = substr((string) $r['user_agent'], 0, 120)
                             . '|' . (string) $r['screen_width'] . 'x' . (string) $r['screen_height'];
        }
        unset($r);

        return self::$memo[$key] = $rows;
    }
}
