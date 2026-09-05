<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * What does a FIRST visit cost, compared with a return visit?
 *
 * Averaging the two together is the single most misleading thing this dataset
 * invites. A returning visitor reloading a cached page and a stranger arriving
 * fresh are not two samples of one number — they are two different experiences, and
 * only one of them is a first impression.
 *
 * Rendered as a binned distribution rather than two averages because the shape is
 * the finding: if cold loads spread across a wide range while warm loads cluster
 * tight, that is a caching story, and two bar heights would hide it.
 */
final class CacheCohortSplit implements Metric
{
    private const BINS = 8;

    public function id(): string { return 'cache-cohort-split'; }
    public function title(): string { return 'First visit vs return visit'; }
    public function section(): string { return 'performance'; }

    public function question(): string
    {
        return 'How much slower is a page for someone arriving with an empty cache '
             . 'than for someone coming back?';
    }

    public function compute(Filters $f): MetricResult
    {
        $rows = PageviewSet::fetch($f);
        if ($rows === []) {
            return new MetricResult([], [], Coverage::for($rows, $f));
        }

        $cold = [];
        $warm = [];
        foreach ($rows as $r) {
            if ($r['cache_state'] === 'cold') { $cold[] = $r['total_ms']; }
            else                              { $warm[] = $r['total_ms']; }
        }

        $all = array_merge($cold, $warm);
        $max = max($all);
        $min = min($all);

        /*
         * Equal-width bins over the observed range. The top bin is closed rather
         * than open so the slowest observation lands somewhere instead of falling
         * off the end.
         */
        $width = ($max - $min) / self::BINS ?: 1.0;
        $bins  = [];
        for ($i = 0; $i < self::BINS; $i++) {
            $lo = $min + $i * $width;
            $bins[$i] = [
                'lo' => $lo, 'hi' => $lo + $width,
                'label' => fmt_ms($lo) . '–' . fmt_ms($lo + $width),
                'cold' => 0, 'warm' => 0,
            ];
        }
        $place = static function (float $v) use ($min, $width): int {
            $i = (int) floor(($v - $min) / $width);
            return max(0, min(self::BINS - 1, $i));
        };
        foreach ($cold as $v) { $bins[$place($v)]['cold']++; }
        foreach ($warm as $v) { $bins[$place($v)]['warm']++; }

        $coldMedian = Stats::median($cold);
        $warmMedian = Stats::median($warm);

        return new MetricResult(
            rows: array_values($bins),
            summary: [
                'cold_n' => count($cold),
                'warm_n' => count($warm),
                'cold_median' => $coldMedian,
                'warm_median' => $warmMedian,
                'cold_p90' => Stats::percentile($cold, 0.9),
                'warm_p90' => Stats::percentile($warm, 0.9),
                // How many times slower a first visit is. Null when either cohort
                // is missing, rather than a divide-by-zero dressed up as a fact.
                'ratio' => ($coldMedian !== null && $warmMedian !== null && $warmMedian > 0)
                    ? $coldMedian / $warmMedian : null,
                'max_bin' => max(array_map(
                    static fn($b) => $b['cold'] + $b['warm'], $bins
                )),
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
