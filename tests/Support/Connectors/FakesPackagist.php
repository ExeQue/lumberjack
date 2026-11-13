<?php

declare(strict_types=1);

namespace Tests\Support\Connectors;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stands Packagist's public API in for acme/widgets, in the shapes packagist.org/apidoc
 * documents. Packagist has published downloads through yesterday: 10 a day, 20 on each of
 * the last two days; per major, version 2 has three quarters of them and 1 the rest. It has
 * releases 2.1.0 and 1.0.0 and branches, and one critical advisory. Any other package is
 * unknown (404).
 */
trait FakesPackagist
{
    /** Status codes Packagist answers with instead, by path. */
    public array $packagistStatuses = [];

    public function fakePackagist(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (isset($this->packagistStatuses[$path])) {
                return Http::response([], $this->packagistStatuses[$path]);
            }

            $from = CarbonImmutable::parse((string) ($query['from'] ?? 'yesterday'), 'UTC');
            $yesterday = CarbonImmutable::now('UTC')->startOfDay()->subDay();
            $labels = [];
            $values = [];

            for ($day = $from; $day <= $yesterday; $day = $day->addDay()) {
                $labels[] = $day->format('Y-m-d');
                $values[] = $day->diffInDays($yesterday) < 2 ? 20 : 10;
            }

            return match ($path) {
                '/packages/acme/widgets.json' => Http::response(['package' => [
                    'name' => 'acme/widgets',
                    'description' => 'Widgets for everyone.',
                    'repository' => 'https://github.com/acme/widgets',
                    'downloads' => ['total' => 12345, 'monthly' => 340, 'daily' => 20],
                    'favers' => 12,
                    'dependents' => 3,
                    'suggesters' => 1,
                    'github_stars' => 40,
                    'versions' => [
                        'dev-main' => ['version_normalized' => 'dev-main', 'time' => '2026-11-12T10:00:00+00:00'],
                        '2.x-dev' => ['version_normalized' => '2.9999999.9999999.9999999-dev', 'time' => '2026-11-11T10:00:00+00:00'],
                        'v2.1.0' => ['version_normalized' => '2.1.0.0', 'time' => '2026-11-01T10:00:00+00:00'],
                        'v1.0.0' => ['version_normalized' => '1.0.0.0', 'time' => '2025-06-01T10:00:00+00:00'],
                    ],
                ]]),
                '/packages/acme/widgets/stats/all.json' => Http::response(['labels' => $labels, 'values' => ['acme/widgets' => $values], 'average' => 'daily']),
                '/packages/acme/widgets/stats/major/all.json' => Http::response(['labels' => $labels, 'values' => [
                    '1' => array_map(fn (int $v): int => intdiv($v, 4), $values),
                    '2' => array_map(fn (int $v): int => $v - intdiv($v, 4), $values),
                ], 'average' => 'daily']),
                '/api/security-advisories/' => Http::response(['advisories' => ['acme/widgets' => [[
                    'advisoryId' => 'PKSA-test-0001',
                    'packageName' => 'acme/widgets',
                    'title' => 'Widgets can be spun the wrong way',
                    'link' => 'https://github.com/advisories/GHSA-test',
                    'cve' => 'CVE-2026-0001',
                    'affectedVersions' => '>=1.0.0,<2.1.0',
                    'reportedAt' => '2026-10-23 21:50:27',
                    'severity' => 'critical',
                ]]]]),
                '/search.json' => Http::response(['results' => [
                    ['name' => 'acme/widgets', 'description' => 'Widgets for everyone.', 'downloads' => 12345, 'favers' => 12],
                    ['name' => 'acme/widgets', 'description' => '', 'virtual' => true],
                ]]),
                default => Http::response(['status' => 'error', 'message' => 'Package not found'], 404),
            };
        });
    }
}
