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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IssueController
{
    use ReadsDashboardFilters;

    public const int PER_PAGE = 25;

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

        $base = Issue::query()->where('project_id', $project->id);

        $issues = (clone $base)
            ->when($status === IssueStatus::Open)->whereOpen()
            ->when($status !== IssueStatus::Open)->whereStatus($status)
            ->when($source !== null)->whereSource($source ?? IssueSource::Server)
            ->when($search !== '')->whereSearch($search)
            ->when($release !== '')->where('last_release', $release)
            ->when($assigned === 'me')->where('assigned_user_id', $request->user()->id)
            ->when($assigned === 'none')->whereNull('assigned_user_id')
            ->when($label !== '')->whereHasLabel($label)
            ->orderByDesc(match ($sort) {
                'occurrences' => 'occurrences_count',
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
            ],
            'labels' => $project->labels()->get()->map(fn (Label $l): array => $l->toSummary())->all(),
            // The open tab holds the issues in progress too.
            'counts' => tap((clone $base)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status'), function ($counts): void {
                $counts['open'] = (int) ($counts['open'] ?? 0) + (int) ($counts['in_progress'] ?? 0);
            }),
            'releases' => (clone $base)->whereNotNull('last_release')->distinct()->orderByDesc('last_release')->limit(20)->pluck('last_release'),
            'issues' => $issues->through(fn (Issue $issue): array => [
                ...$presenter->summary($issue),
                'hourly' => collect(range(0, 24))->map(fn (int $h): int => (int) ($hourly->get($issue->id)?->firstWhere('h', $h)->c ?? 0))->all(),
            ]),
        ]);
    }

    public function show(Request $request, Project $project, Issue $issue, IssuePresenter $presenter, ProjectPresenter $projects): Response
    {
        $issue->setRelation('project', $project);
        Gate::authorize('view', $issue);

        $issue->load(['assignee', 'labels']);
        $latest = $issue->occurrences()->latest('occurred_at')->first();
        $occurrences = $issue->occurrences()->latest('occurred_at')->limit(50)->get();
        $stats = DB::table('occurrences')->where('issue_id', $issue->id)
            ->selectRaw("COUNT(DISTINCT release) AS releases, STRING_AGG(DISTINCT host, ', ' ORDER BY host) AS hosts")
            ->first();

        return Inertia::render('issues/show', [
            'project' => $projects->header($project, $request->user()),
            'issue' => [
                ...$presenter->summary($issue),
                'hosts' => $stats->hosts ?? null,
                'releases' => (int) ($stats->releases ?? 0),
                'regressed_at' => $issue->regressed_at?->toIso8601String(),
            ],
            'latest' => $latest !== null ? $presenter->occurrence($latest) : null,
            'occurrences' => $occurrences->map(fn ($o): array => array_diff_key($presenter->occurrence($o), ['stacktrace' => true]))->all(),
            'series' => TimeSeries::count(DB::table('occurrences')->where('issue_id', $issue->id), Period::Day),
            'notes' => $issue->notes()->with(['user', 'mcpToken'])->latest()->get()->map(fn (IssueNote $n): array => $presenter->note($n))->all(),
            'members' => $project->team->members()->orderBy('name')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all(),
            // Agents whose MCP token covers this project, for tagging in notes.
            'agents' => $project->team->mcpTokens()->whereHas('projects', fn ($q) => $q->whereKey($project->id))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('name')->get(['mcp_tokens.id', 'mcp_tokens.name'])->map(fn ($t): array => ['id' => $t->id, 'name' => $t->name])->all(),
            'labels' => $project->labels()->get()->map(fn (Label $l): array => $l->toSummary())->all(),
            'history' => $presenter->history($issue),
            'has_repository' => $project->repository()->exists(),
        ]);
    }
}
