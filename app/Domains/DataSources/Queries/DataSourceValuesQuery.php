<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Queries;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Support\Ical;
use App\Domains\DataSources\Support\JsonPath;
use App\Domains\DataSources\Support\Prometheus;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Shared\Support\LruCache;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a data source answered, read back for screen widgets: the latest body, the body at
 * a moment, and a series over a window, each through a JSONPath (for Prometheus, a
 * selector) given at read time, so every widget can read its own figure from the same
 * fetches. Read only: fetching is the background's job (FetchDataSourceAction).
 */
class DataSourceValuesQuery
{
    /**
     * Decoded bodies, which a screen's widgets ask for again and again; few, as one can be a megabyte.
     *
     * @var LruCache<mixed>
     */
    private LruCache $memo;

    public function __construct()
    {
        $this->memo = new LruCache(20);
    }

    /**
     * The latest body it answered, decoded (for Prometheus: parsed); null before its first good fetch.
     */
    public function latest(DataSource $source): mixed
    {
        if ($source->last_body === null) {
            return null;
        }

        return $this->memo->remember("latest:{$source->id}:{$source->last_ok_at?->getTimestamp()}", fn (): mixed => $source->format === DataSourceFormat::Prometheus
            ? Prometheus::parse($source->last_body)
            : json_decode($source->last_body, true));
    }

    /**
     * The events of an iCalendar source's latest feed in the window (Ical::events). A large
     * feed is slow to parse, so the window's events are kept until the next good fetch.
     *
     * @return list<array{title: string, location: string|null, start: int, end: int, all_day: bool}>
     */
    public function events(DataSource $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($source->format !== DataSourceFormat::Ical || $source->last_body === null) {
            return [];
        }

        $key = "data-source-events:{$source->id}:{$source->last_ok_at?->getTimestamp()}:{$from->getTimestamp()}:{$to->getTimestamp()}:{$from->getTimezone()->getName()}";

        return Cache::remember($key, 3600, fn (): array => Ical::events((string) $source->last_body, $from, $to));
    }

    /**
     * The number at the path (for Prometheus: the selector) in the latest body.
     */
    public function number(DataSource $source, string $path): ?float
    {
        if ($source->format === DataSourceFormat::Prometheus) {
            return Prometheus::number($this->evaluate($source, $path));
        }

        $latest = $this->latest($source);

        return $latest !== null ? JsonPath::number($latest, $path) : null;
    }

    /**
     * The rows a list shows from the latest body (see JsonPath::rows and Prometheus::rows).
     *
     * @return list<array{label: string, value: float|string|null}>
     */
    public function rows(DataSource $source, string $path, ?string $labelPath, ?string $valuePath, int $limit): array
    {
        if ($source->format === DataSourceFormat::Prometheus) {
            return Prometheus::rows($this->evaluate($source, $path), $limit);
        }

        $latest = $this->latest($source);

        return $latest !== null ? JsonPath::rows($latest, $path, $labelPath, $valuePath, $limit) : [];
    }

    /**
     * What a Prometheus selector reads from the latest body; a counter against the fetch
     * before it in the history (Prometheus::evaluate). Null when the selector does not parse.
     *
     * @return array{name: string, counter: bool, matched: int, grouped: bool, rows: list<array{labels: array<string, string>, value: float}>}|null
     */
    public function evaluate(DataSource $source, string $selector): ?array
    {
        $latest = $this->latest($source);
        $at = $source->last_ok_at;
        $previous = $latest !== null && $at !== null ? $this->memo->remember("previous:{$source->id}:{$at->getTimestamp()}", fn (): ?array => $this->fetches(DB::table('data_source_fetches')
            ->where('data_source_id', $source->id)->where('fetched_at', '<', $at), 1)[0] ?? null) : null;

        return Prometheus::evaluate(
            is_array($latest) ? $latest : null,
            is_array($previous) && is_array($previous['body']) ? $previous['body'] : null,
            is_array($previous) ? (float) ($at->getTimestamp() - $previous['t']) : null,
            $selector,
        );
    }

    /**
     * The number at the path in the last body it answered by the given moment, and no more
     * than $maxAgeSeconds before it: a stat compared a period back takes nothing older than
     * two periods, so a source that was down for long has no previous value, not a stale one.
     * A Prometheus counter's rate is against the fetch before that one, in the same span.
     */
    public function numberAt(DataSource $source, string $path, CarbonInterface $at, int $maxAgeSeconds): ?float
    {
        $rows = $this->fetches(DB::table('data_source_fetches')->where('data_source_id', $source->id)
            ->where('fetched_at', '<=', $at)->where('fetched_at', '>', $at->toImmutable()->subSeconds($maxAgeSeconds)), 2);

        return $this->numberIn($source, $path, $rows[0] ?? null, $rows[1] ?? null);
    }

    /**
     * {t, v} per bucket of the window: the number at the path in the bucket's last fetch,
     * null where there is none; a Prometheus counter's against the point before it. One
     * fetch per bucket is read, so a month costs 30 bodies.
     *
     * @return list<array{t: string, v: float|null}>
     */
    public function series(DataSource $source, string $path, MetricWindow $window): array
    {
        $bucket = $window->bucketSeconds;
        /** @var array<int, array{body: mixed, t: int}> $bodies */
        $bodies = $this->memo->remember("series:{$source->id}:{$window->from->getTimestamp()}:{$window->to->getTimestamp()}:{$bucket}", fn (): array => DB::table('data_source_fetches')
            ->where('data_source_id', $source->id)
            ->whereBetween('fetched_at', [$window->from, $window->to])
            ->selectRaw('DISTINCT ON (b) floor(extract(epoch from fetched_at) / ?)::bigint * ? AS b, body, fetched_at', [$bucket, $bucket])
            ->orderBy('b')->orderByDesc('fetched_at')
            ->get()->mapWithKeys(fn (object $row): array => [(int) $row->b => ['body' => json_decode($row->body, true), 't' => CarbonImmutable::parse($row->fetched_at)->getTimestamp()]])->all());
        $before = null;
        $points = [];

        foreach ($window->buckets() as $t) {
            $points[] = ['t' => gmdate('Y-m-d\TH:i:s\Z', $t), 'v' => isset($bodies[$t]) ? $this->numberIn($source, $path, $bodies[$t], $before) : null];
            $before = $bodies[$t] ?? $before;
        }

        return $points;
    }

    /**
     * The newest fetches of the query, decoded, with their time.
     *
     * @return list<array{body: mixed, t: int}>
     */
    private function fetches(Builder $query, int $limit): array
    {
        return array_values($query->orderByDesc('fetched_at')->limit($limit)->get(['body', 'fetched_at'])
            ->map(fn (object $row): array => ['body' => json_decode($row->body, true), 't' => CarbonImmutable::parse($row->fetched_at)->getTimestamp()])->all());
    }

    /**
     * The number at the path in a fetch from the history, a counter's against the one before.
     *
     * @param  array{body: mixed, t: int}|null  $fetch
     * @param  array{body: mixed, t: int}|null  $before
     */
    private function numberIn(DataSource $source, string $path, ?array $fetch, ?array $before): ?float
    {
        if ($fetch === null) {
            return null;
        }

        if ($source->format !== DataSourceFormat::Prometheus) {
            return JsonPath::number($fetch['body'], $path);
        }

        return Prometheus::number(Prometheus::evaluate(
            is_array($fetch['body']) ? $fetch['body'] : null,
            is_array($before['body'] ?? null) ? $before['body'] : null,
            $before !== null ? (float) ($fetch['t'] - $before['t']) : null,
            $path,
        ));
    }
}
