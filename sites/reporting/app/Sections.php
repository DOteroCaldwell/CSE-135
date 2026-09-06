<?php
declare(strict_types=1);
defined('CSE135_APP') || exit;

/**
 * The report categories, and the one place their names live.
 *
 * HW5 scopes analysts to sections ("Sam is in charge of performance"). Every
 * Metric declares one of these, every report page belongs to one, every REST
 * resource maps to one, and user_sections rows hold one. Keeping the list here
 * rather than in a database ENUM means adding a category is a code change, which
 * is where the report that gives it meaning has to be written anyway.
 */
final class Sections
{
    public const ALL = [
        'performance' => [
            'label' => 'Performance',
            'blurb' => 'How fast pages load, where the time goes, and what a fix is worth.',
        ],
        'behaviour' => [
            'label' => 'Behaviour',
            'blurb' => 'What visitors do on a page: how far they scroll, how long they stay, what breaks.',
        ],
        'audience' => [
            'label' => 'Audience',
            'blurb' => 'Who is visiting: screens, languages, and what their browsers can do.',
        ],
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    public static function label(string $key): string
    {
        return self::ALL[$key]['label'] ?? $key;
    }

    public static function blurb(string $key): string
    {
        return self::ALL[$key]['blurb'] ?? '';
    }

    /**
     * Normalise a submitted list (e.g. from checkboxes) to valid, unique keys in
     * canonical order. Anything unknown is dropped rather than stored.
     */
    public static function normalise(array $keys): array
    {
        $out = [];
        foreach (self::keys() as $k) {
            if (in_array($k, $keys, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }
}
