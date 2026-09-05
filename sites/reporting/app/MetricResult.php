<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * What every metric returns.
 *
 * `coverage` is mandatory, not optional decoration. Every number this platform
 * shows is computed from however much data happens to exist, and the honest
 * reading of a median over eleven pageviews from one laptop is "not yet known".
 * Forcing each metric to declare its own sample size means a thin result cannot be
 * rendered as though it were a finding.
 */
final class MetricResult
{
    public function __construct(
        public readonly array $rows = [],
        public readonly array $summary = [],
        public readonly array $coverage = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Standard coverage block.
     *
     * $caveats are conditions the reader needs in order to not over-read the
     * number. They are computed, not authored: see Coverage::for().
     */
    public static function coverage(
        int $pageviews,
        int $sessions = 0,
        int $devices = 0,
        ?string $firstSeen = null,
        ?string $lastSeen = null,
        array $caveats = [],
    ): array {
        return [
            'pageviews'  => $pageviews,
            'sessions'   => $sessions,
            'devices'    => $devices,
            'first_seen' => $firstSeen,
            'last_seen'  => $lastSeen,
            'caveats'    => $caveats,
        ];
    }
}
