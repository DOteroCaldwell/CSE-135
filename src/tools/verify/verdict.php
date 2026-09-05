<?php
declare(strict_types=1);

/**
 * Prints what the platform currently concludes, using the real Metric classes the
 * dashboard uses — not a copy of their logic.
 *
 * Exists to support the bias test (see bias-test.sh): seed a dataset whose
 * bottleneck was chosen in advance, run this, and check that the verdict matches.
 * Then change the bottleneck and check that the verdict MOVES.
 *
 *   php verdict.php [--json] [--synthetic=0]
 */

$root = dirname(__DIR__, 3) . '/sites/reporting';
require_once $root . '/app/bootstrap.php';

$opt = getopt('', ['json', 'synthetic::']);
$f = Filters::fromQuery(['synthetic' => (string) ($opt['synthetic'] ?? '1')]);

$opp   = MetricRegistry::get('opportunity')->compute($f);
$split = MetricRegistry::get('cache-cohort-split')->compute($f);
$res   = MetricRegistry::get('resource-weight')->compute($f);

if (isset($opt['json'])) {
    echo json_encode([
        'winner'   => $opp->summary['winner'] ?? null,
        'ranking'  => array_map(
            static fn($r) => ['phase' => $r['phase'], 'savings_ms' => round($r['savings_ms'], 1)],
            $opp->rows
        ),
        'margin'    => $opp->summary['margin_over_second'] ?? null,
        'pageviews' => $opp->summary['pageviews'] ?? 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

printf("pageviews analysed : %d\n", $opp->summary['pageviews'] ?? 0);
printf("median total load  : %s   (mean %s)\n\n",
    fmt_ms($opp->summary['median_total'] ?? null), fmt_ms($opp->summary['mean_total'] ?? null));

echo "PHASE RANKING BY RECOVERABLE TIME\n";
printf("  %-28s %10s %10s %12s %10s\n", 'phase', 'mean', 'p10 base', 'recoverable', '% of load');
foreach ($opp->rows as $r) {
    printf("  %-28s %10s %10s %12s %10s\n",
        $r['label'], fmt_ms($r['mean']), fmt_ms($r['baseline']),
        fmt_ms($r['savings_per_view']), fmt_pct($r['pct_of_load']));
}

printf("\nVERDICT: %s\n", $opp->summary['winner_label'] ?? 'none');
printf("  recovers %s per pageview (%s of the average load)\n",
    fmt_ms($opp->summary['winner_savings_per_view'] ?? null),
    fmt_pct($opp->summary['winner_pct_of_load'] ?? null));
$m = $opp->summary['margin_over_second'] ?? null;
printf("  margin over runner-up: %s\n", $m === null ? 'n/a' : number_format($m, 2) . 'x');

printf("\nCACHE: cold median %s vs warm median %s (%s)\n",
    fmt_ms($split->summary['cold_median'] ?? null),
    fmt_ms($split->summary['warm_median'] ?? null),
    isset($split->summary['ratio']) && $split->summary['ratio'] !== null
        ? number_format($split->summary['ratio'], 1) . 'x slower' : 'n/a');

printf("\nHEAVIEST RESOURCES (top 5 by bytes)\n");
foreach (array_slice($res->rows, 0, 5) as $r) {
    printf("  %-28s %10s transferred in total, over %d request(s)%s\n",
        $r['short'], fmt_bytes($r['transferred_bytes']), $r['requests'],
        $r['is_blocking'] ? '  [render-blocking]' : '');
}

echo "\nCOVERAGE CAVEATS\n";
foreach ($opp->coverage['caveats'] ?? [] as $c) {
    echo "  - $c\n";
}
