<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

/**
 * The people who contributed to Lumberjack, as the release build wrote them into
 * contributors.json from GitHub, most contributions first. A checkout has none.
 */
final class Contributors
{
    /**
     * @return list<array{login: string, url: string, contributions: int}>
     */
    public static function all(?string $path = null): array
    {
        $text = @file_get_contents($path ?? base_path('contributors.json'));
        $rows = $text !== false ? json_decode($text, true) : null;

        if (! is_array($rows)) {
            return [];
        }

        $contributors = [];

        foreach ($rows as $row) {
            $login = is_array($row) ? ($row['login'] ?? null) : null;

            // Only a GitHub login, so the link can only lead to a GitHub profile.
            if (! is_string($login) || preg_match('/^[A-Za-z0-9-]{1,39}$/', $login) !== 1) {
                continue;
            }

            $contributors[] = ['login' => $login, 'url' => 'https://github.com/'.$login, 'contributions' => (int) ($row['contributions'] ?? 0)];
        }

        usort($contributors, fn (array $a, array $b): int => $b['contributions'] <=> $a['contributions']);

        return $contributors;
    }
}
