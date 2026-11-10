<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Http\Controllers;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;
use App\Domains\Connectors\Umami\Enums\UmamiHosting;
use App\Domains\Connectors\Umami\Queries\UmamiDashboardQuery;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Releases\Queries\ReleaseHealthQuery;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project's visitors as its Umami website counted them, beside what Lumberjack saw of
 * the same pages and the releases that went out meanwhile. Every row filters the page.
 */
class UmamiController
{
    use ReadsDashboardFilters;

    /** The breakdowns of each card, loaded after the page. */
    private const array CARDS = [
        'pages' => ['path', 'entry', 'exit', 'hostname'],
        'sources' => ['referrer', 'channel', 'utmSource', 'utmMedium', 'utmCampaign'],
        'audience' => ['country', 'device', 'browser', 'os', 'language'],
        'events' => ['event'],
    ];

    public function __invoke(Request $request, Project $project, UmamiDashboardQuery $query, ReleaseHealthQuery $releases, ProjectPresenter $presenter, ?DataSourceConnection $dataSourceConnection = null): Response
    {
        Gate::authorize('view', $project);
        abort_if($dataSourceConnection?->isFor(ConnectionKind::Umami) === false, 404);
        $websites = $project->umamiConnections()->get();
        $connection = $dataSourceConnection ?? $websites->first();
        $period = $this->period($request);
        $filters = $this->umamiFilters($request);
        $error = null;
        $summary = null;

        if ($connection !== null) {
            try {
                $summary = $query->summary($connection, $period, $filters);
            } catch (ConnectorsExceptionInterface $e) {
                $error = $e->getMessage();
            } catch (DecryptException) {
                $error = __('connectors.umami.errors.secret_unreadable');
            }
        }

        // Once Umami failed for the totals, nothing else is asked of it.
        $read = fn (Closure $compute, mixed $default): Closure => function () use ($compute, $default, $connection, $error): mixed {
            if ($connection === null || $error !== null) {
                return $default;
            }

            try {
                return $compute();
            } catch (ConnectorsExceptionInterface) {
                return $default;
            }
        };

        $cards = [];
        // What each card's rows are searched for, as search[card]=text.
        $searches = array_map(fn (mixed $text): string => is_string($text) ? mb_substr(trim($text), 0, 100) : '', array_intersect_key((array) $request->query('search', []), self::CARDS));

        // How many rows each card shows, as rows[card]=n, grown by its Load more.
        $rowCounts = array_map(fn (mixed $n): int => is_numeric($n) ? min(max((int) $n, UmamiDashboardQuery::ROWS), UmamiDashboardQuery::MAX_ROWS) : UmamiDashboardQuery::ROWS, array_intersect_key((array) $request->query('rows', []), self::CARDS));

        foreach (self::CARDS as $card => $dimensions) {
            $search = $searches[$card] ?? '';
            $shown = $rowCounts[$card] ?? UmamiDashboardQuery::ROWS;
            $cards[$card] = Inertia::defer($read(fn (): array => array_combine($dimensions, array_map(
                fn (string $dimension): array => $query->breakdown($connection, $dimension, $period, $filters, $search, $shown),
                $dimensions,
            )), []), $card);
        }

        return Inertia::render('connectors/umami/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => ['period' => $period->value, 'environment' => null, 'filter' => (object) $filters],
            'searches' => (object) array_filter($searches),
            'rows' => (object) array_filter($rowCounts, fn (int $n): bool => $n > UmamiDashboardQuery::ROWS),
            'connection' => $connection === null ? null : [
                'uuid' => $connection->uuid,
                'website_id' => $connection->umamiWebsiteId(),
                'website_name' => $connection->name,
                'hosting' => UmamiHosting::fromApiUrl($connection->url)->value,
                'instance' => UmamiHosting::instanceUrl($connection->url),
                'timezone' => $connection->timezone,
            ],
            // Every website the project reads, to switch between.
            'websites' => $websites->map(fn (DataSourceConnection $c): array => ['uuid' => $c->uuid, 'name' => $c->displayName()])->all(),
            // The filter a row of each breakdown sets when clicked; null for one that sets none.
            'dimensions' => UmamiDashboardQuery::DIMENSIONS,
            'error' => $error,
            'summary' => $summary,
            'active' => $read(fn (): int => $query->active($connection), null),
            'series' => $read(fn (): array => $query->series($connection, $period, $filters), []),
            'releases' => fn (): array => $this->releases($releases, $project, $period),
            'annotations' => $read(fn (): array => $query->annotations($connection, $period), []),
            'heatmap' => Inertia::defer($read(fn (): array => $query->heatmap($connection, $filters), []), 'heatmap'),
            ...$cards,
        ]);
    }

    /**
     * The filters the page was opened with, those Umami knows, as filter[name]=value.
     *
     * @return array<string, string>
     */
    private function umamiFilters(Request $request): array
    {
        $given = $request->query('filter');
        $known = array_filter(array_unique(UmamiDashboardQuery::DIMENSIONS));

        return array_map(
            fn (string $value): string => mb_substr($value, 0, 500),
            array_filter(array_intersect_key(is_array($given) ? $given : [], array_flip($known)), fn (mixed $v): bool => is_string($v) && $v !== ''),
        );
    }

    /**
     * The releases first seen in the period, to mark on the chart.
     *
     * @return list<array{release: string, at: string}>
     */
    private function releases(ReleaseHealthQuery $releases, Project $project, Period $period): array
    {
        $since = $period->since();

        return array_values(array_map(
            fn (array $r): array => ['release' => $r['release'], 'at' => $r['first_seen_at']],
            array_filter($releases->get($project->id, $period), fn (array $r): bool => CarbonImmutable::parse($r['first_seen_at'])->gte($since)),
        ));
    }
}
