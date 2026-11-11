<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

/**
 * The CHANGELOG.md this install was built with, one entry per release, newest first.
 */
final class Changelog
{
    /**
     * Each "## [version] - date" section with its notes as markdown; an empty Unreleased
     * is left out, and so is everything when the file is not there.
     *
     * @return list<array{version: string, date: string|null, notes: string}>
     */
    public static function releases(?string $path = null): array
    {
        $text = @file_get_contents($path ?? base_path('CHANGELOG.md'));

        if ($text === false) {
            return [];
        }

        // The link definitions at the foot ([0.6.1]: https://...) belong to no release.
        $text = (string) preg_replace('/^\[[^\]]+\]:\s+\S+[ \t]*$/m', '', $text);
        preg_match_all('/^## \[([^\]]+)\](?: - (\d{4}-\d{2}-\d{2}))?[ \t]*$/m', $text, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $releases = [];

        foreach ($headings as $i => $heading) {
            // From the end of this heading to the start of the next.
            $start = $heading[0][1] + strlen($heading[0][0]);
            $end = isset($headings[$i + 1]) ? $headings[$i + 1][0][1] : strlen($text);
            $notes = trim(substr($text, $start, $end - $start));

            if ($notes !== '') {
                $date = $heading[2][0] ?? '';
                $releases[] = ['version' => $heading[1][0], 'date' => $date !== '' ? $date : null, 'notes' => $notes];
            }
        }

        return $releases;
    }
}
