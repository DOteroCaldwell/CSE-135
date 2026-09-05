<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * How much do we actually know?
 *
 * The caveats below are DERIVED from the data, never written by hand for a
 * particular dataset. That is deliberate: as real traffic arrives they stop firing
 * on their own, and if the club site later develops a different kind of thin spot
 * the same rules catch it. A hand-written "note: small sample" would have to be
 * remembered and removed, and would not be.
 *
 * Thresholds are judgement calls, stated once here rather than scattered:
 *   < 30 pageviews  a median is not yet stable
 *   < 5  sessions   one person's browsing dominates
 *   <= 1 device     nothing is known about how anyone else experiences the site
 *   < 5  cold loads first-visit cost is the question; warm loads cannot answer it
 */
final class Coverage
{
    public const MIN_PAGEVIEWS = 30;
    public const MIN_SESSIONS  = 5;
    public const MIN_COLD      = 5;

    /**
     * @param array $rows rows carrying at least session_id; optionally
     *                    device_key, cache_state and a timestamp column
     */
    public static function for(array $rows, Filters $f, string $tsKey = 'server_ts'): array
    {
        $sessions = [];
        $devices  = [];
        $cold     = 0;
        $times    = [];

        foreach ($rows as $r) {
            if (isset($r['session_id'])) { $sessions[$r['session_id']] = true; }
            if (!empty($r['device_key'])) { $devices[$r['device_key']] = true; }
            if (($r['cache_state'] ?? null) === 'cold') { $cold++; }
            if (!empty($r[$tsKey])) { $times[] = $r[$tsKey]; }
        }

        sort($times);
        $caveats = [];

        $n = count($rows);
        if ($n === 0) {
            $caveats[] = 'No data matches these filters.';
        } else {
            if ($n < self::MIN_PAGEVIEWS) {
                $caveats[] = "Only $n pageviews — treat these figures as indicative, not settled.";
            }
            if (count($sessions) > 0 && count($sessions) < self::MIN_SESSIONS) {
                $caveats[] = count($sessions) . ' session' . (count($sessions) === 1 ? '' : 's')
                    . ' — one visitor\'s behaviour dominates this result.';
            }
            if (count($devices) === 1) {
                $caveats[] = 'A single device and browser — nothing here reflects how other hardware experiences the site.';
            }
            if ($cold > 0 && $cold < self::MIN_COLD) {
                $caveats[] = "Only $cold first-visit (cold cache) load"
                    . ($cold === 1 ? '' : 's') . ' — the cost of arriving fresh is the least certain number here.';
            }
            if ($f->includeSynthetic) {
                $caveats[] = 'Includes generated traffic. Turn it off to see real visitors only.';
            }
        }

        return MetricResult::coverage(
            pageviews: $n,
            sessions:  count($sessions),
            devices:   count($devices),
            firstSeen: $times[0] ?? null,
            lastSeen:  $times === [] ? null : $times[count($times) - 1],
            caveats:   $caveats,
        );
    }
}
