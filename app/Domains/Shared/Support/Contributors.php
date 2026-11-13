<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The people who contributed to Lumberjack, most contributions first: as the release build
 * wrote them into contributors.json, or in a checkout read from GitHub now and then.
 */
final class Contributors
{
    public const string CACHE_KEY = 'about:contributors';

    private const int CACHE_SECONDS = 86_400;

    /**
     * @return list<array{login: string, url: string, contributions: int}>
     */
    public static function all(): array
    {
        return self::built() ?? self::fromGitHub();
    }

    /**
     * What the release build wrote, or null in a checkout, which has no such file.
     *
     * @return list<array{login: string, url: string, contributions: int}>|null
     */
    public static function built(?string $path = null): ?array
    {
        $text = @file_get_contents($path ?? base_path('contributors.json'));

        return $text !== false ? self::parse(json_decode($text, true)) : null;
    }

    /**
     * The repository's contributors from GitHub's public API, for a checkout; a day at a
     * time, and none when update checks (Lumberjack's other call to GitHub) are off.
     *
     * @return list<array{login: string, url: string, contributions: int}>
     */
    public static function fromGitHub(): array
    {
        if (! config('lumberjack.update_check')) {
            return [];
        }

        /** @var list<array{login: string, url: string, contributions: int}> */
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            try {
                $rows = Http::acceptJson()->withUserAgent('Lumberjack')->connectTimeout(3)->timeout(5)
                    ->get('https://api.github.com/repos/ExeQue/lumberjack/contributors', ['per_page' => 100])->json();
            } catch (ConnectionException) {
                return [];
            }

            return self::parse(is_array($rows) ? array_values(array_filter($rows, fn (mixed $row): bool => is_array($row) && ($row['type'] ?? 'User') === 'User')) : null);
        });
    }

    /**
     * @return list<array{login: string, url: string, contributions: int}>
     */
    private static function parse(mixed $rows): array
    {
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
