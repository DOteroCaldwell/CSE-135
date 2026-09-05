<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * If we fixed one phase, what would it be worth?
 *
 * This is the metric the whole platform exists for, so the model it uses matters
 * more than any other decision here.
 *
 * THE BASELINE IS THE DATA'S OWN p10.
 *
 * For each phase, the target is the value that phase already achieves on the
 * fastest 10% of observed pageviews, and the opportunity is the time spent above
 * that target, summed across every pageview:
 *
 *     savings = Σ max(0, observed − p10(phase))
 *
 * Why that and not an industry benchmark, a round number, or zero:
 *
 *   - It needs no outside authority. "Your TTFB should be under 200 ms" is a claim
 *     about somebody else's server. p10 is a claim about THIS site on THIS
 *     infrastructure, and it is therefore demonstrably achievable — it has already
 *     happened, repeatedly.
 *   - It is self-calibrating. Point this at the club's real homepage after the
 *     course and the baselines move with it. Nothing needs re-tuning.
 *   - It cannot be gamed by the author. There is no constant here to nudge until
 *     the phase you suspected comes out on top.
 *   - Targeting zero would rank phases by raw size, which just says "the biggest
 *     phase is the biggest". Targeting p10 ranks by RECOVERABLE time, which is the
 *     actual question. A phase that is large but already consistent offers little;
 *     a phase that is wildly variable offers a lot.
 *
 * The honest limitation, stated rather than buried: this measures how much a phase
 * varies, so it assumes the good case is reachable in the bad case. Usually true
 * (a cache miss can become a hit, an unoptimised image can be compressed).
 * Sometimes not — a visitor on a genuinely slow network cannot be given a fast one.
 * The report says so where it matters.
 */
final class Opportunity implements Metric
{
    private const BASELINE_PERCENTILE = 0.10;

    public function id(): string { return 'opportunity'; }
    public function title(): string { return 'What is each fix worth?'; }
    public function section(): string { return 'performance'; }

    public function question(): string
    {
        return 'If one phase of the load were brought to the speed this site already '
             . 'reaches on its best loads, how much visitor time would that recover?';
    }

    public function compute(Filters $f): MetricResult
    {
        $rows = PageviewSet::fetch($f);
        $n = count($rows);
        if ($n === 0) {
            return new MetricResult([], [], Coverage::for($rows, $f));
        }

        $totals      = array_column($rows, 'total_ms');
        $medianTotal = Stats::median($totals) ?? 0.0;

        /*
         * Savings are expressed as a share of the MEAN load, not the median.
         *
         * savings_per_view is itself a mean (total recoverable time / pageviews), so
         * dividing it by a median mixes two different statistics and can exceed
         * 100%: with a bimodal cold/warm dataset the median sits down among the warm
         * loads while the savings come almost entirely from the cold ones. Comparing
         * a mean to a mean keeps the ratio meaningful.
         */
        $meanTotal = Stats::mean($totals) ?? 0.0;
        $out = [];

        foreach (array_keys(Phases::labels()) as $key) {
            $vals = array_column(array_column($rows, 'phases'), $key);
            $baseline = Stats::percentile($vals, self::BASELINE_PERCENTILE) ?? 0.0;

            $savings = 0.0;
            foreach ($vals as $v) {
                $savings += max(0.0, $v - $baseline);
            }

            $out[] = [
                'phase'        => $key,
                'label'        => Phases::label($key),
                'explain'      => Phases::explain($key),
                'mean'         => Stats::mean($vals) ?? 0.0,
                'median'       => Stats::median($vals) ?? 0.0,
                'p90'          => Stats::percentile($vals, 0.9) ?? 0.0,
                'baseline'     => $baseline,
                'total_ms'     => Stats::sum($vals),
                'savings_ms'   => $savings,
                'savings_per_view' => $savings / $n,
                'savings_per_1k'   => ($savings / $n) * 1000,
                // Share of an average page load this fix would remove.
                'pct_of_load' => $meanTotal > 0 ? ($savings / $n) / $meanTotal : null,
            ];
        }

        /*
         * The ranking IS the answer. Nothing above encodes an expectation about
         * which phase wins; sorting by measured recoverable time is what makes the
         * verdict follow the data instead of the author.
         */
        usort($out, static fn($a, $b) => $b['savings_ms'] <=> $a['savings_ms']);

        $top = $out[0];
        $totalRecoverable = array_sum(array_column($out, 'savings_ms'));

        return new MetricResult(
            rows: $out,
            summary: [
                'winner'            => $top['phase'],
                'winner_label'      => $top['label'],
                'winner_savings_per_view' => $top['savings_per_view'],
                'winner_savings_per_1k'   => $top['savings_per_1k'],
                'winner_pct_of_load'      => $top['pct_of_load'],
                'median_total'      => $medianTotal,
                'mean_total'        => $meanTotal,
                'pageviews'         => $n,
                'total_recoverable' => $totalRecoverable,
                // How decisive the win is. A narrow margin means "these two are
                // worth the same"; the report must not present a coin-flip as a
                // clear answer.
                'margin_over_second' => count($out) > 1 && $out[1]['savings_ms'] > 0
                    ? $top['savings_ms'] / $out[1]['savings_ms'] : null,
                'baseline_percentile' => self::BASELINE_PERCENTILE,
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
