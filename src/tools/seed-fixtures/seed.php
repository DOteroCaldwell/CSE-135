<?php
declare(strict_types=1);

/**
 * CSE 135 HW4 — synthetic fixture generator.
 *
 * Writes generated pageviews straight into the database, bypassing the browser and
 * the collector. Two jobs:
 *
 *   1. Development. The metrics need data to be built against, and waiting for
 *      real traffic to a password-protected prototype is not a plan.
 *
 *   2. THE BIAS TEST. This is the important one. The platform is supposed to name
 *      whichever phase actually dominates, not the one the author expected. The
 *      only way to demonstrate that is to generate a dataset whose bottleneck we
 *      chose in advance and check that the report says so — then do it again with a
 *      different bottleneck and check that the verdict MOVES.
 *
 *      A report that always says "images" is not measuring anything.
 *
 * Every session written here is flagged is_synthetic = 1 and every user agent
 * carries the marker token, so this data can never be mistaken for real visitors
 * and can be excluded with one dashboard toggle.
 *
 * Usage:
 *   php seed.php --profile=images --sessions=12 [--config=/etc/cse135/db.ini] [--purge]
 *
 * Profiles pick which phase dominates:
 *   images        heavy subresource tail (unoptimised media)
 *   ttfb          slow server, light page
 *   render-block  synchronous scripts in <head> stalling the parser
 *   balanced      no single dominant phase
 */

const MARKER = 'CSE135-SyntheticTraffic';

/* ------------------------------------------------------------------- args -- */

$opt = getopt('', ['profile::', 'sessions::', 'config::', 'purge', 'help']);
if (isset($opt['help'])) {
    fwrite(STDERR, "see the header of this file\n");
    exit(0);
}
$profileName = (string) ($opt['profile'] ?? 'balanced');
$sessionCount = max(1, (int) ($opt['sessions'] ?? 10));
$configPath  = (string) ($opt['config'] ?? '/etc/cse135/db.ini');

/*
 * Phase budgets in milliseconds: [min, max] per phase, plus the page's asset mix.
 * These are the ONLY place a profile's intent is expressed. Nothing downstream —
 * no metric, no report — knows these numbers exist.
 */
$PROFILES = [
    'images' => [
        'ttfb' => [12, 30],  'dom' => [15, 45],   'tail' => [400, 2600],
        'assets' => ['img' => [8, 14], 'imgKB' => [400, 3200], 'js' => [2, 4], 'css' => [1, 2]],
    ],
    'ttfb' => [
        'ttfb' => [380, 1400], 'dom' => [12, 40], 'tail' => [20, 90],
        'assets' => ['img' => [2, 4], 'imgKB' => [20, 80], 'js' => [2, 3], 'css' => [1, 2]],
    ],
    'render-block' => [
        'ttfb' => [12, 30],  'dom' => [350, 1500], 'tail' => [30, 120],
        'assets' => ['img' => [3, 6], 'imgKB' => [30, 120], 'js' => [5, 9], 'css' => [3, 6]],
    ],
    'balanced' => [
        'ttfb' => [40, 120], 'dom' => [40, 140],  'tail' => [50, 200],
        'assets' => ['img' => [4, 8], 'imgKB' => [40, 300], 'js' => [3, 5], 'css' => [2, 4]],
    ],
];

if (!isset($PROFILES[$profileName])) {
    fwrite(STDERR, "unknown profile '$profileName'; have: " . implode(', ', array_keys($PROFILES)) . "\n");
    exit(1);
}
$P = $PROFILES[$profileName];

/* --------------------------------------------------------------------- db -- */

$cfg = @parse_ini_file($configPath);
if (!is_array($cfg)) {
    fwrite(STDERR, "cannot read $configPath\n");
    exit(1);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'] ?? '127.0.0.1', (int) ($cfg['port'] ?? 3306), $cfg['name']),
    $cfg['user'], $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

if (isset($opt['purge'])) {
    // ON DELETE CASCADE clears static/performance/activity/resources with it.
    $n = $pdo->exec('DELETE FROM sessions WHERE is_synthetic = 1');
    echo "purged $n synthetic session(s)\n";
}

/* ------------------------------------------------------------------ dice -- */

function between(array $r): float { return $r[0] + (mt_rand() / mt_getrandmax()) * ($r[1] - $r[0]); }
function pick(array $a): mixed    { return $a[array_rand($a)]; }
function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return implode('-', [bin2hex(substr($b,0,4)), bin2hex(substr($b,4,2)),
                         bin2hex(substr($b,6,2)), bin2hex(substr($b,8,2)), bin2hex(substr($b,10,6))]);
}

$PAGES = ['/index.html', '/products.html', '/product-detail.html', '/checkout.html', '/liquidation.html'];
$HOST  = 'test.ucsdwrestlingclub.com';

// A deliberately varied fleet, so coverage checks have something to find and the
// device-diversity caveat can actually clear.
$DEVICES = [
    ['ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36', 'sw'=>2560,'sh'=>1440,'ww'=>1440,'conn'=>'4g'],
    ['ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',        'sw'=>1920,'sh'=>1080,'ww'=>1280,'conn'=>'4g'],
    ['ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'sw'=>390,'sh'=>844,'ww'=>390,'conn'=>'3g'],
    ['ua' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Mobile Safari/537.36',  'sw'=>412,'sh'=>915,'ww'=>412,'conn'=>'4g'],
    ['ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'sw'=>1680,'sh'=>1050,'ww'=>1440,'conn'=>null],
];

/* ---------------------------------------------------------------- inserts -- */

$insSession = $pdo->prepare(
    'INSERT INTO sessions (session_id, sid_source, first_seen, last_seen, entry_page,
                           entry_host, client_ip, user_agent, payload_count, is_synthetic)
     VALUES (?,?,?,?,?,?,?,?,?,1)');
$insStatic = $pdo->prepare(
    'INSERT INTO `static` (session_id, pageview_id, page, host, user_agent, language,
        cookies_enabled, js_enabled, images_enabled, css_enabled, screen_width,
        screen_height, window_width, window_height, connection_type, raw,
        client_sent_at, server_ts) VALUES (?,?,?,?,?,?,1,1,1,1,?,?,?,?,?,?,?,?)');
$insPerf = $pdo->prepare(
    'INSERT INTO performance (session_id, pageview_id, page, host, load_start_ms,
        load_end_ms, load_start_epoch, load_end_epoch, total_load_ms, timing_source,
        nav_timing, client_sent_at, server_ts) VALUES (?,?,?,?,0,?,?,?,?,?,?,?,?)');
$insAct = $pdo->prepare(
    'INSERT INTO activity (session_id, pageview_id, page, host, event_type, occurred_at,
        pos_x, pos_y, scroll_x, scroll_y, mouse_button, key_name, idle_duration_ms,
        error_message, detail, client_sent_at, server_ts)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insRes = $pdo->prepare(
    'INSERT INTO resources (session_id, pageview_id, page, host, name, initiator_type,
        start_ms, duration_ms, transfer_size, encoded_body_size, decoded_body_size,
        next_hop_protocol, render_blocking_status, delivery_type, raw, client_sent_at,
        server_ts) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$pdo->beginTransaction();
$pageviews = 0; $resourceRows = 0;

for ($s = 0; $s < $sessionCount; $s++) {
    $sid = 'syn-' . bin2hex(random_bytes(8));
    $dev = pick($DEVICES);
    $ua  = $dev['ua'] . ' ' . MARKER;
    $when = new DateTimeImmutable('-' . mt_rand(0, 10) . ' days ' . mt_rand(0, 23) . ' hours');
    $entry = pick($PAGES);

    $insSession->execute([$sid, 'server', $when->format('Y-m-d H:i:s'),
        $when->format('Y-m-d H:i:s'), $entry, $HOST, '203.0.113.' . mt_rand(1, 254), $ua, 1]);

    // First page of a visit is a cold cache; later pages in the same visit are warm.
    $perSession = mt_rand(1, 4);
    for ($v = 0; $v < $perSession; $v++) {
        $cold = ($v === 0);
        $page = $v === 0 ? $entry : pick($PAGES);
        $pvid = uuid();
        $ts   = $when->modify('+' . ($v * mt_rand(10, 90)) . ' seconds');

        /* -- the timeline ------------------------------------------------- */
        $dns  = $cold ? between([2, 25])  : 0.0;
        $tcp  = $cold ? between([8, 60])  : 0.0;
        $ttfb = between($P['ttfb']);
        $dl   = between([2, 20]);
        $dom  = between($P['dom']);
        $dcl  = between([0.5, 8]);
        // A warm cache mostly removes network cost from the subresource tail.
        $tail = between($P['tail']) * ($cold ? 1.0 : 0.08);
        $le   = between([0.3, 6]);

        $t = [];
        $t['startTime'] = 0; $t['redirectStart'] = 0; $t['redirectEnd'] = 0;
        $t['fetchStart'] = 0.4;
        $t['domainLookupStart'] = $t['fetchStart'] + 0.2;
        $t['domainLookupEnd']   = $t['domainLookupStart'] + $dns;
        $t['connectStart']      = $t['domainLookupEnd'];
        $t['secureConnectionStart'] = $t['connectStart'] + ($cold ? $tcp * 0.5 : 0);
        $t['connectEnd']        = $t['connectStart'] + $tcp;
        $t['requestStart']      = $t['connectEnd'] + 0.3;
        $t['responseStart']     = $t['requestStart'] + $ttfb;
        $t['responseEnd']       = $t['responseStart'] + $dl;
        $t['domInteractive']    = $t['responseEnd'] + $dom;
        $t['domContentLoadedEventStart'] = $t['domInteractive'] + 0.4;
        $t['domContentLoadedEventEnd']   = $t['domContentLoadedEventStart'] + $dcl;
        $t['domComplete']       = $t['domContentLoadedEventEnd'] + $tail * 0.98;
        $t['loadEventStart']    = $t['domContentLoadedEventEnd'] + $tail;
        $t['loadEventEnd']      = $t['loadEventStart'] + $le;
        // NB: not $v — that is the pageview loop counter one scope up.
        foreach ($t as $k => $mark) { $t[$k] = round($mark, 2); }

        $t['entryType'] = 'navigation'; $t['name'] = "https://$HOST$page";
        $t['type'] = 'navigate'; $t['redirectCount'] = 0;
        $t['nextHopProtocol'] = 'h2'; $t['responseStatus'] = 200;
        $t['deliveryType'] = $cold ? '' : 'cache';
        $t['transferSize']    = $cold ? mt_rand(2500, 9000) : 0;
        $t['encodedBodySize'] = mt_rand(2200, 8500);
        $t['decodedBodySize'] = mt_rand(9000, 26000);
        $t['duration'] = $t['loadEventEnd'];

        $total = (int) round($t['loadEventEnd']);
        $epoch = (int) ($ts->format('U')) * 1000;

        $insPerf->execute([$sid, $pvid, $page, $HOST, $t['loadEventEnd'],
            $epoch, $epoch + $total, $total, 'PerformanceNavigationTiming',
            json_encode($t), $epoch + $total, $ts->format('Y-m-d H:i:s')]);

        $insStatic->execute([$sid, $pvid, $page, $HOST, $ua, 'en-US',
            $dev['sw'], $dev['sh'], $dev['ww'], (int) ($dev['sh'] * 0.8),
            $dev['conn'],
            json_encode(['userAgent' => $ua, 'screen' => ['width' => $dev['sw'],
                'height' => $dev['sh'], 'devicePixelRatio' => 2],
                'connection' => $dev['conn'] ? ['effectiveType' => $dev['conn']] : null]),
            $epoch + $total, $ts->format('Y-m-d H:i:s')]);

        /* -- the assets that produced that tail --------------------------- */
        $A = $P['assets'];
        $mk = function (string $name, string $kind, float $kb, float $start, float $dur,
                        ?string $blocking = null) use (
            $insRes, $sid, $pvid, $page, $HOST, $cold, $ts, &$resourceRows
        ) {
            $bytes = (int) round($kb * 1024);
            $insRes->execute([$sid, $pvid, $page, $HOST,
                "https://$HOST$name", $kind, round($start, 2), round($dur, 2),
                $cold ? $bytes : 0, $bytes, (int) round($bytes * 1.02),
                'h2', $blocking, $cold ? '' : 'cache', json_encode(['synthetic' => true]),
                null, $ts->format('Y-m-d H:i:s')]);
            $resourceRows++;
        };

        for ($i = 0; $i < (int) between($A['css']); $i++) {
            $mk("/css/sheet$i.css", 'css', between([3, 9]),
                $t['responseEnd'] + $i, between([4, 30]), 'blocking');
        }
        for ($i = 0; $i < (int) between($A['js']); $i++) {
            $mk("/js/module$i.js", 'script', between([6, 45]),
                $t['responseEnd'] + $i * 2, between([8, max(10, $dom / 2)]),
                $i < 2 ? 'blocking' : 'non-blocking');
        }
        $imgCount = (int) between($A['img']);
        for ($i = 0; $i < $imgCount; $i++) {
            $mk("/assets/product-$i.png", 'img', between($A['imgKB']),
                $t['domContentLoadedEventEnd'] + $i * 3,
                max(5, $tail / max(1, $imgCount)) * between([0.6, 1.4]), 'non-blocking');
        }

        /* -- activity ----------------------------------------------------
         * Not used by any performance metric, but the collector records it and
         * the REST console lists it, so a fixture that leaves the table empty
         * makes a working endpoint look broken. Also seeds the behavioural data
         * the "does slow load cost engagement" question will need later.
         */
        $act = function (string $type, array $cols = [], array $detail = []) use (
            $insAct, $sid, $pvid, $page, $HOST, $ts, $epoch
        ) {
            $insAct->execute([$sid, $pvid, $page, $HOST, $type,
                $epoch + ($cols['at'] ?? 0),
                $cols['x'] ?? null, $cols['y'] ?? null,
                $cols['sx'] ?? null, $cols['sy'] ?? null,
                $cols['button'] ?? null, $cols['key'] ?? null,
                $cols['idle'] ?? null, $cols['error'] ?? null,
                json_encode($detail ?: null), $epoch, $ts->format('Y-m-d H:i:s')]);
        };

        $dwell = mt_rand(1500, 45000);
        $act('pageenter', ['at' => 0], ['title' => 'Wrecked Tech', 'visibility' => 'visible']);
        for ($k = 0; $k < mt_rand(2, 9); $k++) {
            $act('scroll', ['at' => (int) ($dwell * ($k + 1) / 12),
                            'sx' => 0, 'sy' => mt_rand(0, 3200)]);
        }
        for ($k = 0; $k < mt_rand(0, 4); $k++) {
            $act('click', ['at' => (int) ($dwell * mt_rand(1, 9) / 10),
                           'x' => mt_rand(0, 1400), 'y' => mt_rand(0, 900), 'button' => 0],
                 ['buttonName' => 'left', 'target' => 'a.product-card']);
        }
        if (mt_rand(1, 3) === 1) {
            $gap = mt_rand(2000, 12000);
            $act('idle', ['at' => (int) ($dwell * 0.6), 'idle' => $gap], ['durationMs' => $gap]);
        }
        // Errors are a property of the page, not of the profile being tested.
        if (mt_rand(1, 6) === 1) {
            $act('error', ['at' => mt_rand(50, 900),
                           'error' => 'Uncaught ReferenceError: undefinedVariable is not defined'],
                 ['source' => "https://$HOST/js/chaos.js", 'line' => 42]);
        }
        $act('pageleave', ['at' => $dwell], ['timeOnPageMs' => $dwell, 'reason' => 'pagehide']);

        $pageviews++;
    }
}

$pdo->commit();

$actRows = (int) $pdo->query(
    'SELECT COUNT(*) FROM activity a JOIN sessions s ON s.session_id = a.session_id
      WHERE s.is_synthetic = 1')->fetchColumn();
printf("profile=%s  sessions=%d  pageviews=%d  resources=%d  activity=%d\n",
    $profileName, $sessionCount, $pageviews, $resourceRows, $actRows);
printf("all rows flagged is_synthetic=1 and tagged '%s'\n", MARKER);
