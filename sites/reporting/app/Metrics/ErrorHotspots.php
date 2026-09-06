<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * Where does the site break, and what breaks?
 *
 * Two views of the same events. Per PAGE: the share of pageviews that saw at least
 * one script error — a rate, so a busy page and a quiet one are comparable. Per
 * MESSAGE: the distinct errors, ranked by how many pageviews they hit, because one
 * bug firing on every load is one fix and the rate view alone would not say that.
 */
final class ErrorHotspots implements Metric
{
    public function id(): string { return 'error-hotspots'; }
    public function title(): string { return 'Where the site breaks'; }
    public function section(): string { return 'behaviour'; }

    public function question(): string
    {
        return 'Which pages throw JavaScript errors, how often, and is it one bug or many?';
    }

    public function compute(Filters $f): MetricResult
    {
        $rows = ActivitySet::fetch($f);

        $byPage = [];
        foreach ($rows as $r) {
            $byPage[$r['page']] ??= ['n' => 0, 'with_error' => 0, 'errors' => 0, 'resource_errors' => 0];
            $byPage[$r['page']]['n']++;
            $byPage[$r['page']]['errors'] += $r['errors'];
            $byPage[$r['page']]['resource_errors'] += $r['resource_errors'];
            if ($r['errors'] > 0) { $byPage[$r['page']]['with_error']++; }
        }
        $pages = [];
        foreach ($byPage as $page => $c) {
            $pages[] = [
                'page' => $page, 'n' => $c['n'], 'with_error' => $c['with_error'],
                'errors' => $c['errors'], 'resource_errors' => $c['resource_errors'],
                'error_rate' => $c['with_error'] / $c['n'],
            ];
        }
        usort($pages, static fn($a, $b) => $b['error_rate'] <=> $a['error_rate'] ?: $b['n'] <=> $a['n']);

        // The messages themselves. Same filters, straight from the events.
        [$where, $params] = $f->where('a', 'server_ts');
        $where = array_merge(["a.event_type IN ('error', 'unhandled-rejection')"], $where);
        $messages = Db::all(
            'SELECT COALESCE(NULLIF(a.error_message, \'\'), \'(no message)\') AS message,
                    COUNT(*) AS occurrences,
                    COUNT(DISTINCT a.pageview_id) AS pageviews,
                    COUNT(DISTINCT a.page) AS pages,
                    MIN(JSON_UNQUOTE(JSON_EXTRACT(a.detail, \'$.source\'))) AS source
               FROM activity a
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY message
              ORDER BY pageviews DESC, occurrences DESC
              LIMIT 8',
            $params
        );

        $n = count($rows);
        $withErr = count(array_filter($rows, static fn($r) => $r['errors'] > 0));

        return new MetricResult(
            rows: $pages,
            summary: [
                'pageviews'   => $n,
                'with_error'  => $withErr,
                'error_rate'  => $n ? $withErr / $n : null,
                'messages'    => $messages,
                'distinct'    => count($messages),
                'worst_page'  => $pages[0]['page'] ?? null,
                'worst_rate'  => $pages[0]['error_rate'] ?? null,
            ],
            coverage: Coverage::for($rows, $f),
        );
    }
}
