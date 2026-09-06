<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * What language is the browser asking for?
 *
 * navigator.language is a preference, not a nationality, and that is exactly what
 * a site wants to know: it is the language the visitor chose to read in. Grouped
 * by family (en-US and en-GB both read English copy) with the full tags kept in
 * the grid.
 */
final class Languages implements Metric
{
    public function id(): string { return 'languages'; }
    public function title(): string { return 'Languages'; }
    public function section(): string { return 'audience'; }

    public function question(): string
    {
        return 'Which languages do visitors\' browsers ask for, and is anyone reading in something the site does not offer?';
    }

    public function compute(Filters $f): MetricResult
    {
        $all  = AudienceSet::fetch($f);
        $rows = array_values(array_filter($all, static fn($r) => $r['lang_family'] !== null));
        $n = count($rows);

        $fam = [];
        foreach ($rows as $r) {
            $fam[$r['lang_family']] ??= ['n' => 0, 'tags' => [], 'sessions' => []];
            $fam[$r['lang_family']]['n']++;
            $fam[$r['lang_family']]['tags'][$r['language']] = ($fam[$r['lang_family']]['tags'][$r['language']] ?? 0) + 1;
            $fam[$r['lang_family']]['sessions'][$r['session_id']] = true;
        }
        $out = [];
        foreach ($fam as $k => $c) {
            arsort($c['tags']);
            $out[] = [
                'family'   => $k,
                'label'    => strtoupper($k),
                'n'        => $c['n'],
                'value'    => $c['n'],
                'share'    => $c['n'] / $n,
                'sessions' => count($c['sessions']),
                'tags'     => implode(', ', array_map(
                    static fn($t, $c) => "$t ($c)", array_keys($c['tags']), $c['tags'])),
            ];
        }
        usort($out, static fn($a, $b) => $b['n'] <=> $a['n']);

        return new MetricResult(
            rows: $out,
            summary: [
                'pageviews'   => $n,
                'families'    => count($out),
                'top'         => $out[0]['family'] ?? null,
                'top_share'   => $out[0]['share'] ?? null,
                'non_english' => $n ? count(array_filter($rows, static fn($r) => $r['lang_family'] !== 'en')) / $n : null,
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
