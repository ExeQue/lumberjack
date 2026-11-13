<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Support;

use App\Domains\Connectors\Packagist\Exceptions\PackagistRequestFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Packagist's public API (packagist.org/apidoc): a package's figures and versions, its
 * downloads per day, in all and per major version, its security advisories, and the
 * package search. Nothing needs a key. Packagist counts downloads per day and publishes a
 * day once it is over, a day or so late.
 */
class PackagistClient
{
    public const string HOST = 'https://packagist.org';

    public const int TIMEOUT_S = 15;

    /** A package name as Composer allows it: vendor/name. */
    public const string NAME_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    /** Releases a package page lists, newest first. */
    public const int RELEASES = 10;

    /**
     * @return array{name: string, description: string, repository: string|null, downloads: array{total: int, monthly: int, daily: int}, favers: int, dependents: int, suggesters: int, github_stars: int|null, releases: list<array{version: string, time: string|null}>}
     *
     * @throws PackagistRequestFailedException
     */
    public function package(string $name): array
    {
        $package = $this->get('/packages/'.self::checked($name).'.json')['package'] ?? null;

        if (! is_array($package)) {
            throw new PackagistRequestFailedException(__('connectors.packagist.errors.not_found'));
        }

        $downloads = is_array($package['downloads'] ?? null) ? $package['downloads'] : [];
        $count = fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;

        return [
            'name' => is_string($package['name'] ?? null) ? $package['name'] : $name,
            'description' => is_string($package['description'] ?? null) ? $package['description'] : '',
            'repository' => is_string($package['repository'] ?? null) && str_starts_with($package['repository'], 'https://') ? $package['repository'] : null,
            'downloads' => ['total' => $count($downloads['total'] ?? 0), 'monthly' => $count($downloads['monthly'] ?? 0), 'daily' => $count($downloads['daily'] ?? 0)],
            'favers' => $count($package['favers'] ?? 0),
            'dependents' => $count($package['dependents'] ?? 0),
            'suggesters' => $count($package['suggesters'] ?? 0),
            'github_stars' => is_numeric($package['github_stars'] ?? null) ? (int) $package['github_stars'] : null,
            'releases' => self::releases(is_array($package['versions'] ?? null) ? $package['versions'] : []),
        ];
    }

    /**
     * Downloads per day from $from up to the last day Packagist has published.
     *
     * @return array<string, int> by date (Y-m-d), oldest first
     *
     * @throws PackagistRequestFailedException
     */
    public function daily(string $name, CarbonImmutable $from): array
    {
        $body = $this->get('/packages/'.self::checked($name).'/stats/all.json', ['average' => 'daily', 'from' => $from->format('Y-m-d')]);
        $values = is_array($body['values'] ?? null) ? array_values($body['values'])[0] ?? [] : [];

        return self::days($body['labels'] ?? null, is_array($values) ? $values : []);
    }

    /**
     * Downloads per day of each major version from $from, the newest major first.
     *
     * @return array<string, array<string, int>> by major, then date
     *
     * @throws PackagistRequestFailedException
     */
    public function majors(string $name, CarbonImmutable $from): array
    {
        $body = $this->get('/packages/'.self::checked($name).'/stats/major/all.json', ['average' => 'daily', 'from' => $from->format('Y-m-d')]);
        $majors = [];

        foreach (is_array($body['values'] ?? null) ? $body['values'] : [] as $major => $values) {
            if (is_array($values)) {
                $majors[(string) $major] = self::days($body['labels'] ?? null, $values);
            }
        }

        uksort($majors, fn (string $a, string $b): int => version_compare($b, $a));

        return $majors;
    }

    /**
     * The security advisories known against the package, the newest first.
     *
     * @return list<array{id: string, title: string, link: string|null, cve: string|null, affected: string, severity: string|null, reported_at: string|null}>
     *
     * @throws PackagistRequestFailedException
     */
    public function advisories(string $name): array
    {
        $body = $this->get('/api/security-advisories/', ['packages' => [self::checked($name)]]);
        $advisories = [];

        foreach (is_array($body['advisories'][$name] ?? null) ? $body['advisories'][$name] : [] as $row) {
            if (! is_array($row) || ! is_string($row['advisoryId'] ?? null)) {
                continue;
            }

            $string = fn (string $key): ?string => is_string($row[$key] ?? null) && $row[$key] !== '' ? $row[$key] : null;
            $link = $string('link');

            $advisories[] = [
                'id' => $row['advisoryId'],
                'title' => $string('title') ?? $row['advisoryId'],
                'link' => $link !== null && str_starts_with($link, 'https://') ? $link : null,
                'cve' => $string('cve'),
                'affected' => $string('affectedVersions') ?? '',
                'severity' => $string('severity'),
                'reported_at' => ($at = $string('reportedAt')) !== null ? CarbonImmutable::parse($at, 'UTC')->toIso8601String() : null,
            ];
        }

        usort($advisories, fn (array $a, array $b): int => strcmp((string) $b['reported_at'], (string) $a['reported_at']));

        return $advisories;
    }

    /**
     * Packages matching what is typed, the most downloaded first as Packagist ranks them.
     *
     * @return list<array{name: string, description: string, downloads: int}>
     *
     * @throws PackagistRequestFailedException
     */
    public function search(string $query): array
    {
        $body = $this->get('/search.json', ['q' => mb_substr($query, 0, 100), 'per_page' => 15]);
        $found = [];

        foreach (is_array($body['results'] ?? null) ? $body['results'] : [] as $row) {
            // A virtual package is something others provide, not one to read.
            if (is_array($row) && is_string($row['name'] ?? null) && ! ($row['virtual'] ?? false) && preg_match(self::NAME_PATTERN, $row['name']) === 1) {
                $found[] = [
                    'name' => $row['name'],
                    'description' => is_string($row['description'] ?? null) ? mb_substr($row['description'], 0, 200) : '',
                    'downloads' => is_numeric($row['downloads'] ?? null) ? (int) $row['downloads'] : 0,
                ];
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws PackagistRequestFailedException
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = Http::retry(
                times: 3,
                sleepMilliseconds: 200,
                when: fn (?Throwable $e): bool => $e instanceof ConnectionException,
                throw: false,
            )->timeout(self::TIMEOUT_S)->connectTimeout(5)->acceptJson()
                ->withUserAgent('Lumberjack-packagist/1')
                ->get(self::HOST.$path, $query);
        } catch (ConnectionException) {
            throw new PackagistRequestFailedException(__('connectors.packagist.errors.unreachable'));
        }

        if ($response->status() !== 200) {
            throw new PackagistRequestFailedException(match ($response->status()) {
                404 => __('connectors.packagist.errors.not_found'),
                429 => __('connectors.packagist.errors.rate_limited'),
                default => __('connectors.packagist.errors.status', ['status' => $response->status()]),
            });
        }

        $data = $response->json();

        return is_array($data) ? $data : throw new PackagistRequestFailedException(__('connectors.packagist.errors.status', ['status' => $response->status()]));
    }

    /**
     * @throws PackagistRequestFailedException
     */
    private static function checked(string $name): string
    {
        return preg_match(self::NAME_PATTERN, $name) === 1 ? $name : throw new PackagistRequestFailedException(__('connectors.packagist.errors.not_found'));
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, int>
     */
    private static function days(mixed $labels, array $values): array
    {
        $days = [];

        foreach (is_array($labels) ? array_values($labels) : [] as $i => $date) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $days[$date] = is_numeric($values[$i] ?? null) ? (int) $values[$i] : 0;
            }
        }

        ksort($days);

        return $days;
    }

    /**
     * The tagged releases, newest first; branches (dev-main, 1.x-dev) are left out.
     *
     * @param  array<mixed>  $versions
     * @return list<array{version: string, time: string|null}>
     */
    private static function releases(array $versions): array
    {
        $releases = [];

        foreach ($versions as $version => $data) {
            $normalized = is_array($data) && is_string($data['version_normalized'] ?? null) ? $data['version_normalized'] : '';

            if (str_starts_with($normalized, 'dev-') || str_ends_with($normalized, '-dev')) {
                continue;
            }

            $time = is_array($data) && is_string($data['time'] ?? null) ? CarbonImmutable::parse($data['time'])->toIso8601String() : null;
            $releases[] = ['version' => (string) $version, 'time' => $time, 'normalized' => $normalized];
        }

        usort($releases, fn (array $a, array $b): int => version_compare($b['normalized'], $a['normalized']));

        return array_map(fn (array $r): array => ['version' => $r['version'], 'time' => $r['time']], array_slice($releases, 0, self::RELEASES));
    }
}
