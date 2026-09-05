<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * One pageview per row, with its load already decomposed into phases.
 *
 * Every performance metric on the dashboard is a different way of grouping this
 * same set, so it is fetched once per request and memoised. Without that, a
 * four-card dashboard runs four near-identical scans of `performance`.
 *
 * The split of labour: SQL does the per-row JSON extraction (the part that has to
 * stay in the database as data grows), PHP does the statistics (the part that is
 * clearer and safer written out longhand). See Stats.
 */
final class PageviewSet
{
    /** @var array<string, array> memo, keyed by filter signature */
    private static array $memo = [];

    public static function fetch(Filters $f): array
    {
        $key = $f->toQuery() ?: '(none)';
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $phaseCols = [];
        foreach (array_keys(Phases::DEFS) as $k) {
            $phaseCols[] = Phases::expr($k, 'p.nav_timing') . " AS ph_$k";
        }

        [$where, $params] = $f->where('p', 'server_ts');
        [$cw, $cp]        = $f->cacheWhere('p.nav_timing');
        $where = array_merge(['p.nav_timing IS NOT NULL'], $where, $cw);
        $params = array_merge($params, $cp);

        $sql = 'SELECT p.id, p.session_id, p.pageview_id, p.page, p.host, p.server_ts,
                       ' . Phases::totalExpr('p.nav_timing') . ' AS total_ms,
                       ' . Phases::cacheStateExpr('p.nav_timing') . ' AS cache_state,
                       ' . Phases::sumExpr('p.nav_timing') . ' AS phase_sum,
                       ' . implode(",\n                       ", $phaseCols) . ',
                       st.user_agent, st.screen_width, st.screen_height,
                       st.window_width, st.connection_type
                  FROM performance p
                  LEFT JOIN `static` st
                         ON st.pageview_id = p.pageview_id
                        AND p.pageview_id IS NOT NULL
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY p.server_ts DESC';

        $rows = Db::all($sql, $params);

        foreach ($rows as &$r) {
            $r['total_ms']   = (float) $r['total_ms'];
            $r['phase_sum']  = (float) $r['phase_sum'];
            $r['phases']     = [];
            foreach (array_keys(Phases::DEFS) as $k) {
                $r['phases'][$k] = (float) $r["ph_$k"];
                unset($r["ph_$k"]);
            }

            /*
             * The remainder. Clamped at zero because total_load_ms comes from the
             * client's own arithmetic while the phases come from the timeline; on
             * rare rows they disagree by a fraction of a millisecond, and a
             * negative slice would render as an inverted bar.
             */
            $r['phases'][Phases::OTHER_KEY] = max(0.0, $r['total_ms'] - $r['phase_sum']);

            // Coarse device identity, for "is this all one machine?" in coverage.
            // Deliberately coarse: this is a sample-diversity check, not tracking.
            $r['device_key'] = substr((string) $r['user_agent'], 0, 120)
                             . '|' . (string) $r['screen_width']
                             . 'x' . (string) $r['screen_height'];
        }
        unset($r);

        return self::$memo[$key] = $rows;
    }

    /** Distinct values for a column, for the filter dropdowns. */
    public static function distinct(string $column): array
    {
        if (!in_array($column, ['host', 'page'], true)) {
            return [];   // whitelist: this interpolates into SQL
        }
        $rows = Db::all(
            "SELECT DISTINCT $column AS v FROM performance
              WHERE $column IS NOT NULL AND $column <> '' ORDER BY v"
        );
        return array_column($rows, 'v');
    }
}
