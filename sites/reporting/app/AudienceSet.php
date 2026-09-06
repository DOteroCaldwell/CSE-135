<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * One pageview per row from `static`: the visitor's environment as the collector
 * saw it. Every audience metric is a grouping of this set, so it is fetched once
 * per request and memoised.
 *
 * The user-agent parse is deliberately coarse — family and OS, nothing more. This
 * is an audience picture for design decisions ("do we need to care about
 * Safari?"), not a fingerprint, and a coarse bucket is also the only kind that
 * stays correct as UA strings keep changing under Client Hints.
 */
final class AudienceSet
{
    private static array $memo = [];

    /** Viewport classes by CSS-pixel window width, narrowest first. */
    public const CLASSES = [
        'phone'   => ['label' => 'Phone',   'axis' => 'Phone',   'min' => 0,    'note' => 'under 600 px'],
        'tablet'  => ['label' => 'Tablet',  'axis' => 'Tablet',  'min' => 600,  'note' => '600 – 1023 px'],
        'laptop'  => ['label' => 'Laptop',  'axis' => 'Laptop',  'min' => 1024, 'note' => '1024 – 1439 px'],
        'desktop' => ['label' => 'Desktop', 'axis' => 'Desktop', 'min' => 1440, 'note' => '1440 px and up'],
    ];

    public static function viewportClass(?int $width): ?string
    {
        if ($width === null || $width <= 0) {
            return null;
        }
        $cls = 'phone';
        foreach (self::CLASSES as $k => $c) {
            if ($width >= $c['min']) { $cls = $k; }
        }
        return $cls;
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'EdgiOS') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')  => 'Opera',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'SamsungBrowser')                      => 'Samsung Internet',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/')                             => 'Safari',
            $ua === ''                                               => 'Unknown',
            default                                                  => 'Other',
        };
    }

    public static function os(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android')                             => 'Android',
            str_contains($ua, 'Windows')                             => 'Windows',
            str_contains($ua, 'CrOS')                                => 'ChromeOS',
            str_contains($ua, 'Mac OS X')                            => 'macOS',
            str_contains($ua, 'Linux')                               => 'Linux',
            $ua === ''                                               => 'Unknown',
            default                                                  => 'Other',
        };
    }

    public static function fetch(Filters $f): array
    {
        $key = $f->toQuery() ?: '(none)';
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        [$where, $params] = $f->where('s', 'server_ts');
        $sql = 'SELECT s.id, s.session_id, s.pageview_id, s.page, s.host, s.server_ts,
                       s.user_agent, s.language, s.cookies_enabled, s.js_enabled,
                       s.images_enabled, s.css_enabled, s.screen_width, s.screen_height,
                       s.window_width, s.window_height, s.connection_type,
                       JSON_EXTRACT(s.raw, \'$.screen.devicePixelRatio\') + 0        AS dpr,
                       JSON_UNQUOTE(JSON_EXTRACT(s.raw, \'$.timezone\'))              AS timezone,
                       JSON_EXTRACT(s.raw, \'$.connection.saveData\')                 AS save_data,
                       JSON_UNQUOTE(JSON_EXTRACT(s.raw, \'$.platform\'))              AS platform
                  FROM `static` s'
             . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
             . ' ORDER BY s.server_ts DESC';

        $rows = Db::all($sql, $params);
        foreach ($rows as &$r) {
            $ua = (string) $r['user_agent'];
            $r['browser']  = self::browser($ua);
            $r['os']       = self::os($ua);
            $r['viewport'] = self::viewportClass($r['window_width'] === null ? null : (int) $r['window_width']);
            $r['dpr']      = $r['dpr'] === null ? null : (float) $r['dpr'];
            $r['hidpi']    = $r['dpr'] !== null && $r['dpr'] >= 1.5;
            $r['language'] = $r['language'] === null || $r['language'] === '' ? null : (string) $r['language'];
            // Language family, so en-US and en-GB count as one audience for copy.
            $r['lang_family'] = $r['language'] === null ? null : strtolower(explode('-', $r['language'])[0]);
            $r['device_key'] = substr($ua, 0, 120) . '|' . (string) $r['screen_width'] . 'x' . (string) $r['screen_height'];
        }
        unset($r);

        return self::$memo[$key] = $rows;
    }
}
