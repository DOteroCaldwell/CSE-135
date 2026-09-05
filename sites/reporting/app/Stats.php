<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Small statistics helpers.
 *
 * Percentiles are computed in PHP rather than SQL on purpose. MySQL 8 has window
 * functions but no percentile aggregate (no PERCENTILE_CONT), so doing it in SQL
 * means ROW_NUMBER plus an offset calculation per percentile per group — a lot of
 * fiddly SQL to get wrong. The per-row JSON extraction, which is the part that
 * genuinely benefits from staying in the database, still happens there; only the
 * ordering-and-picking happens here.
 *
 * The tradeoff is that a metric's result set is materialised in PHP. At the volume
 * this platform will see that is nothing. If the row count ever reaches the point
 * where it matters, this is the file to revisit.
 */
final class Stats
{
    /**
     * Linear-interpolated percentile, $p in 0..1.
     *
     * Interpolating rather than nearest-rank matters at small n, which is exactly
     * where this platform currently lives: with 11 samples, nearest-rank p90 and
     * p100 are the same value, and a report that shows the max twice under two
     * different names is misleading.
     */
    public static function percentile(array $values, float $p): ?float
    {
        $v = array_values(array_filter($values, static fn($x) => $x !== null));
        if ($v === []) {
            return null;
        }
        sort($v, SORT_NUMERIC);
        $n = count($v);
        if ($n === 1) {
            return (float) $v[0];
        }
        $pos   = $p * ($n - 1);
        $lo    = (int) floor($pos);
        $hi    = (int) ceil($pos);
        $frac  = $pos - $lo;
        return (float) $v[$lo] + ($v[$hi] - $v[$lo]) * $frac;
    }

    public static function median(array $values): ?float
    {
        return self::percentile($values, 0.5);
    }

    public static function mean(array $values): ?float
    {
        $v = array_values(array_filter($values, static fn($x) => $x !== null));
        return $v === [] ? null : array_sum($v) / count($v);
    }

    public static function sum(array $values): float
    {
        return (float) array_sum(array_filter($values, static fn($x) => $x !== null));
    }
}
