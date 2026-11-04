<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Controllers;

use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Models\Label;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use App\Domains\Shared\Support\TimeSeries;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IssueController
{
    use ReadsDashboardFilters;

    public const int PER_PAGE = 25;

    /** Seconds the release and host summary of an issue is reused. */
    public const int STATS_SECONDS = 300;

    public function index(Request $request, Project $project, IssuePresenter $presenter, ProjectPresenter $projects): Response
    {
        Gate::authorize('view', $project);

        $status = IssueStatus::tryFrom((string) $request->query('status')) ?? IssueStatus::Open;
        $source = IssueSource::tryFrom((string) $request->query('source'));
        $search = trim((string) $request->query('search', ''));
        $release = (string) $request->query('release', '');
        $assigned = (string) $request->query('assigned', '');
        $sort = (string) $request->query('sort', 'last_seen');
        $label = (string) $request->query('label', '');
        $watching = $request->boolean('watching');

        $base = Issue::query()->where('project_id', $project->id);

        return Inertia::render('issues/index', [
            'project' => $projects->header($project, $request->user()),
            'filters' => [
                'status' => $status->value,
                'source' => $source?->value,
                'search' => $search,
                'release' => $release,
                'assigned' => $assigned,
                'sort' => $sort,
                'label' => $label,
                'watching' => $watching,
            ],
            // Closures, so a live reload of the list builds only what it asks for.
            'labels' => fn (): array => $project->labels()->get()->map(fn (Label $l): array => $l->toSummary())->all(),
            'members' => fn (): array => $project->team->members()->orderBy('name')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all(),
            // The open tab holds the issues in progress too.
            'counts' => fn () => tap((clone $base)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status'), function ($counts): void {
                $counts['open'] = (int) ($counts['open'] ?? 0) + (int) ($counts['in_progress'] ?? 0);
            }),
            'releases' => fn () => (clone $base)->whereNotNull('last_release')->distinct()->orderByDesc('last_release')->limit(20)->pluck('last_release'),
            'issues' => function () use ($base, $status, $source, $search, $release, $assigned, $label, $watching, $sort, $request, $presenter, $project) {
                $issues = (clone $base)
                    ->when($status === IssueStatus::Open)->whereOpen()
                    ->when($status !== IssueStatus::Open)->whereStatus($status)
                    ->when($source !== null)->whereSource($source ?? IssueSource::Server)
                    ->when($search !== '')->whereSearch($search)
                    ->when($release !== '')->where('last_release', $release)
                    ->when($assigned === 'me')->where('assigned_user_id', $request->user()->id)
                    ->when($assigned === 'none')->whereNull('assigned_user_id')
                    ->when($label !== '')->whereHasLabel($label)
                    ->when($watching)->whereWatchedBy($request->user())
                    ->orderByDesc(match ($sort) {
                        'occurrences' => 'occurrences_count',
                        'users' => 'users_count',
                        'first_seen' => 'first_seen_at',
                        default => 'last_seen_at',
                    })
                    ->with(['assignee', 'labels'])
                    ->withCount('notes')
                    ->paginate(self::PER_PAGE)
                    ->withQueryString();

                $since = Period::Day->since();
                $ids = $issues->getCollection()->modelKeys();
                $hourly = DB::table('occurrences')
                    ->whereIn('issue_id', $ids)
                    ->where('occurred_at', '>=', $since)
                    // Clock hours, like every other chart: the first one holds the start of the day.
                    ->selectRaw('issue_id, FLOOR(EXTRACT(EPOCH FROM occurred_at) / 3600) - ? AS h, COUNT(*) AS c', [intdiv($since->getTimestamp(), 3600)])
                    ->groupBy('issue_id', 'h')
                    ->get()
                    ->groupBy('issue_id');

                return $issues->through(fn (Issue $issue): array => [
                    ...$presenter->summary($issue->setRelation('project', $project)),
                    'hourly' => collect(range(0, 24))->map(fn (int $h): int => (int) ($hourly->get($issue->id)?->firstWhere('h', $h)->c ?? 0))->all(),
                ]);
            },
        ]);
    }

    public function show(Request $request, Project $project, Issue $issue, IssuePresenter $presenter, ProjectPresenter $projects): Response
    {
        $issue->setRelation('project', $project);
        Gate::authorize('view', $issue);

        $issue->load(['assignee', 'labels']);

        // Closures, so a live reload builds only the props it asks for.
        return Inertia::render('issues/show', [
            'project' => $projects->header($project, $request->user()),
            'issue' => function () use ($issue, $presenter): array {
                // Over every occurrence the issue ever had, so counted at most every five minutes.
                $stats = Cache::remember('issue:'.$issue->id.':stats', self::STATS_SECONDS, fn (): array => (array) DB::table('occurrences')->where('issue_id', $issue->id)
                    ->selectRaw("COUNT(DISTINCT release) AS releases, STRING_AGG(DISTINCT host, ', ' ORDER BY host) AS hosts")
                    ->first());

                return [
                    ...$presenter->summary($issue),
                    'hosts' => $stats['hosts'] ?? null,
                    'releases' => (int) ($stats['releases'] ?? 0),
                    'regressed_at' => $issue->regressed_at?->toIso8601String(),
                ];
            },
            'latest' => function () use ($issue, $presenter): ?array {
                $latest = $issue->occurrences()->latest('occurred_at')->first();

                return $latest !== null ? $presenter->occurrence($latest) : null;
            },
            'occurrences' => fn (): array => $issue->occurrences()->latest('occurred_at')->limit(50)->get()
                ->map(fn ($o): array => array_diff_key($presenter->occurrence($o), ['stacktrace' => true]))->all(),
            'period' => $this->period($request)->value,
            'series' => fn (): array => TimeSeries::count(DB::table('occurrences')->where('issue_id', $issue->id), $this->period($request)),
            // The notes and the history share their notes and the people and agents who wrote them.
            'notes' => fn (): array => $presenter->loadHistory($issue)->notes->map(fn (IssueNote $n): array => $presenter->note($n))->all(),
            'members' => fn (): array => $project->team->members()->orderBy('name')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all(),
            // Agents whose MCP token covers this project, for tagging in notes.
            'agents' => fn (): array => $project->team->mcpTokens()->whereHas('projects', fn ($q) => $q->whereKey($project->id))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('name')->get(['mcp_tokens.id', 'mcp_tokens.name'])->map(fn ($t): array => ['id' => $t->id, 'name' => $t->name])->all(),
            'labels' => fn (): array => $project->labels()->get()->map(fn (Label $l): array => $l->toSummary())->all(),
            'history' => fn () => $presenter->history($issue),
            'has_repository' => fn (): bool => $project->repository()->exists(),
            'watchers' => fn (): array => $issue->watchers()->orderBy('name')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all(),
            'watching' => fn (): bool => $issue->watchers()->whereKey($request->user()->id)->exists(),
        ]);
    }
}
