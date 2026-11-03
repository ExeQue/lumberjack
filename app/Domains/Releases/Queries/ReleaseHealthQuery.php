<?php

declare(strict_types=1);

namespace App\Domains\Releases\Queries;

use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\Percentiles;
use App\Domains\Shared\Support\StatisticsCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per release seen in the period: its traffic, errors and the issues it
 * brought, compared with the release before it.
 */
class ReleaseHealthQuery
{
    /**
     * @return list<array{release: string, first_seen_at: string, last_seen_at: string, requests: int, error_rate: float|null, p95: int|null, occurrences: int, new_issues: int, regressions: int, previous: array{release: string, error_rate: float|null, p95: int|null}|null, change: array{error_rate: float|null, p95: float|null}}>
     */
    public function get(int $projectId, Period $period, ?string $environment = null, int $limit = 50): array
    {
        return StatisticsCache::remember('releases', $period, [$projectId, $environment, $limit], fn (): array => $this->compute($projectId, $period, $environment, $limit));
    }

    /**
     * @return list<array{release: string, first_seen_at: string, last_seen_at: string, requests: int, error_rate: float|null, p95: int|null, occurrences: int, new_issues: int, regressions: int, previous: array{release: string, error_rate: float|null, p95: int|null}|null, change: array{error_rate: float|null, p95: float|null}}>
     */
    private function compute(int $projectId, Period $period, ?string $environment, int $limit): array
    {
        $since = $period->since()->format('Y-m-d H:i:s');
        $bindings = [$projectId, $since];
        $where = 'project_id = ? AND occurred_at >= ? AND release IS NOT NULL';

        if ($environment !== null) {
            $where .= ' AND environment = ?';
            $bindings[] = $environment;
        }

        $p95 = Percentiles::select('duration_us', '0.95', 'p95');
        $limit = (int) $limit;

        // First and last seen are within the period: older traffic is not looked at.
        $rows = DB::select("
            WITH requests AS (
                SELECT release, COUNT(*) AS requests, {$p95},
                    COUNT(*) FILTER (WHERE status_code >= 500)::float / COUNT(*) AS error_rate,
                    MIN(occurred_at) AS first_seen, MAX(occurred_at) AS last_seen
                FROM request_runs WHERE {$where} GROUP BY release
            ), errors AS (
                SELECT release, COUNT(*) AS occurrences, MIN(occurred_at) AS first_seen, MAX(occurred_at) AS last_seen
                FROM occurrences WHERE {$where} GROUP BY release
            )
            SELECT COALESCE(r.release, e.release) AS release,
                LEAST(r.first_seen, e.first_seen) AS first_seen, GREATEST(r.last_seen, e.last_seen) AS last_seen,
                COALESCE(r.requests, 0) AS requests, r.error_rate, r.p95, COALESCE(e.occurrences, 0) AS occurrences
            FROM requests r FULL OUTER JOIN errors e ON e.release = r.release
            ORDER BY first_seen DESC LIMIT {$limit}", [...$bindings, ...$bindings]);

        $newIssues = DB::query()->fromSub($this->firstOccurrences($projectId, $period, $environment), 'firsts')
            ->whereNotNull('release')->groupBy('release')->selectRaw('release, COUNT(*) AS c')->pluck('c', 'release');

        // The regressed event carries the release of the occurrence that reopened the issue.
        $regressions = DB::table('issue_events')
            ->join('issues', 'issues.id', '=', 'issue_events.issue_id')
            ->where('issues.project_id', $projectId)
            ->where('issue_events.type', IssueEventType::Regressed->value)
            ->where('issue_events.occurred_at', '>=', $since)
            ->when($environment !== null)->whereRaw("issue_events.data->>'environment' = ?", [$environment])
            ->selectRaw("issue_events.data->>'release' AS release, COUNT(*) AS c")
            ->groupByRaw("issue_events.data->>'release'")
            ->pluck('c', 'release');

        $releases = array_map(function (object $object) use ($newIssues, $regressions): array {
            $row = (array) $object;

            return [
                'release' => (string) $row['release'],
                'first_seen_at' => CarbonImmutable::parse($row['first_seen'])->toIso8601String(),
                'last_seen_at' => CarbonImmutable::parse($row['last_seen'])->toIso8601String(),
                'requests' => (int) $row['requests'],
                'error_rate' => $row['error_rate'] !== null ? (float) $row['error_rate'] : null,
                'p95' => $row['p95'] !== null ? (int) $row['p95'] : null,
                'occurrences' => (int) $row['occurrences'],
                'new_issues' => (int) ($newIssues[$row['release']] ?? 0),
                'regressions' => (int) ($regressions[$row['release']] ?? 0),
            ];
        }, $rows);

        // Newest first, so the release before each one is the next row.
        return array_map(function (array $release, int $i) use ($releases): array {
            $previous = $releases[$i + 1] ?? null;

            return [
                ...$release,
                'previous' => $previous !== null ? ['release' => $previous['release'], 'error_rate' => $previous['error_rate'], 'p95' => $previous['p95']] : null,
                'change' => [
                    'error_rate' => $this->change($release['error_rate'], $previous['error_rate'] ?? null),
                    'p95' => $this->change($release['p95'], $previous['p95'] ?? null),
                ],
            ];
        }, $releases, array_keys($releases));
    }

    /**
     * The ids of the issues a release brought in the period.
     *
     * @return list<int>
     */
    public function newIssueIds(int $projectId, Period $period, ?string $environment, string $release): array
    {
        return array_values(DB::query()->fromSub($this->firstOccurrences($projectId, $period, $environment), 'firsts')
            ->where('release', $release)->pluck('issue_id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * The earliest occurrence of every issue first seen in the period, with its release.
     *
     * An issue is new in the release its earliest occurrence carried, not in whatever
     * release was deployed around issues.first_seen_at: releases overlap during rolling
     * deploys and across environments, so a time window would hand an issue to the wrong
     * one. With an environment filter it is the earliest occurrence in that environment.
     */
    private function firstOccurrences(int $projectId, Period $period, ?string $environment): Builder
    {
        return DB::table('occurrences')
            ->join('issues', 'issues.id', '=', 'occurrences.issue_id')
            ->where('issues.project_id', $projectId)
            ->where('issues.first_seen_at', '>=', $period->since())
            ->when($environment !== null)->where('occurrences.environment', $environment)
            ->selectRaw('DISTINCT ON (occurrences.issue_id) occurrences.issue_id, occurrences.release')
            ->orderBy('occurrences.issue_id')->orderBy('occurrences.occurred_at')->orderBy('occurrences.id');
    }

    /**
     * The relative change from the previous release, null without a baseline to compare with.
     */
    private function change(int|float|null $current, int|float|null $previous): ?float
    {
        return $current === null || $previous === null || $previous == 0 ? null : round($current / $previous - 1, 4);
    }
}
