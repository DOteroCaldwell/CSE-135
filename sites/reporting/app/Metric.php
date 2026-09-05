<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * One question, answered against the current filters.
 *
 * Adding a metric later is writing one class and adding one line to
 * MetricRegistry — no template edits, because the dashboard and report pages
 * render whatever the registry hands them. That is what lets this survive the main
 * site changing shape after the course ends.
 */
interface Metric
{
    /** Stable slug, used in URLs and as the registry key. */
    public function id(): string;

    public function title(): string;

    /** The sub-question this metric exists to answer, in plain words. */
    public function question(): string;

    /**
     * HW5 seam. Analysts are scoped to sections ("Sam is in charge of
     * performance"), so every metric declares which one it belongs to.
     */
    public function section(): string;

    public function compute(Filters $f): MetricResult;
}
