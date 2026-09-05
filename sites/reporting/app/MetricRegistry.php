<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Phases.php';
require_once __DIR__ . '/Filters.php';
require_once __DIR__ . '/MetricResult.php';
require_once __DIR__ . '/Coverage.php';
require_once __DIR__ . '/Metric.php';
require_once __DIR__ . '/PageviewSet.php';

foreach (glob(__DIR__ . '/Metrics/*.php') ?: [] as $file) {
    require_once $file;
}

/**
 * The list of everything this platform can answer.
 *
 * Registering a metric is one line here plus one class. Nothing in the dashboard or
 * the report templates names a metric directly — they ask the registry — so the set
 * of questions can grow as the club site changes without any page being rewritten.
 */
final class MetricRegistry
{
    private const MAP = [
        'load-phase-breakdown' => LoadPhaseBreakdown::class,
        'cache-cohort-split'   => CacheCohortSplit::class,
        'slowest-pages'        => SlowestPages::class,
        'resource-weight'      => ResourceWeight::class,
        'opportunity'          => Opportunity::class,
    ];

    /** @var array<string, Metric> */
    private static array $instances = [];

    public static function get(string $id): Metric
    {
        if (!isset(self::MAP[$id])) {
            throw new InvalidArgumentException("no such metric '$id'");
        }
        return self::$instances[$id] ??= new (self::MAP[$id])();
    }

    public static function has(string $id): bool
    {
        return isset(self::MAP[$id]);
    }

    /** @return Metric[] */
    public static function all(): array
    {
        return array_map(static fn($id) => self::get($id), array_keys(self::MAP));
    }

    /**
     * Metrics belonging to one section. HW5 scopes analysts to sections, so the
     * lookup a permission check will need already exists.
     *
     * @return Metric[]
     */
    public static function section(string $section): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(Metric $m) => $m->section() === $section
        ));
    }

    /** @return string[] */
    public static function sections(): array
    {
        return array_values(array_unique(array_map(
            static fn(Metric $m) => $m->section(), self::all()
        )));
    }
}
