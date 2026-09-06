<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * The report pages, one per section, and how to render each one.
 *
 * A report here is a FUNCTION that draws its body for a given Filters. The same
 * function backs two things:
 *
 *   - the live page under /reports/, gated by Auth::requireSection()
 *   - the snapshot stored in saved_reports, which a viewer opens later
 *
 * That is what keeps HW5's "saved reports are set views, even if made static"
 * honest: the static copy is the live page's output, not a second implementation
 * that could drift.
 */
final class Reports
{
    public const ALL = [
        'page-load-cost' => [
            'section'  => 'performance',
            'title'    => 'Page load cost',
            'question' => 'If we could fix one thing about this site\'s performance, what should it be — and what is it worth?',
            'path'     => '/reports/page-load-cost.php',
            'file'     => __DIR__ . '/Reports/page-load-cost.php',
            'fn'       => 'render_report_page_load_cost',
        ],
        'engagement' => [
            'section'  => 'behaviour',
            'title'    => 'Engagement',
            'question' => 'Do visitors engage with a page once it has loaded — and where do they give up?',
            'path'     => '/reports/engagement.php',
            'file'     => __DIR__ . '/Reports/engagement.php',
            'fn'       => 'render_report_engagement',
        ],
        'audience' => [
            'section'  => 'audience',
            'title'    => 'Audience',
            'question' => 'Who is visiting, and what can their devices and browsers actually handle?',
            'path'     => '/reports/audience.php',
            'file'     => __DIR__ . '/Reports/audience.php',
            'fn'       => 'render_report_audience',
        ],
    ];

    public static function get(string $slug): ?array
    {
        if (!isset(self::ALL[$slug])) {
            return null;
        }
        return ['slug' => $slug] + self::ALL[$slug];
    }

    /** @return array<string, array> slug => spec, for one section */
    public static function forSection(string $section): array
    {
        return array_filter(self::ALL, static fn($r) => $r['section'] === $section);
    }

    /**
     * Reports the current account may open live. Drives the navigation, so the
     * nav never shows a link that leads to a 403.
     *
     * @return array<string, array>
     */
    public static function visible(): array
    {
        $out = [];
        foreach (self::ALL as $slug => $r) {
            if (Auth::canViewSection($r['section'])) {
                $out[$slug] = $r;
            }
        }
        return $out;
    }

    /**
     * Render a report body to a string. Used by the live page (echoed straight
     * out) and by save.php (stored). Loads the body file on demand so a page only
     * parses the report it is showing.
     */
    public static function render(string $slug, Filters $f): string
    {
        $r = self::get($slug);
        if ($r === null) {
            throw new InvalidArgumentException("no such report '$slug'");
        }
        require_once __DIR__ . '/View/helpers.php';
        require_once __DIR__ . '/View/charts.php';
        require_once __DIR__ . '/View/report.php';
        require_once $r['file'];

        ob_start();
        try {
            ($r['fn'])($f);
        } finally {
            $html = ob_get_clean();
        }
        return (string) $html;
    }
}
