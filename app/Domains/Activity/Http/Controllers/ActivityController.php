<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Controllers;

use App\Domains\Activity\Http\Requests\ShowActivityRequest;
use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Queries\ActivityStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController
{
    use ReadsDashboardFilters;

    public const int PER_PAGE = 50;

    /**
     * Every event in the period, by group, with how often it happened.
     */
    public function index(Request $request, Project $project, ActivityStatisticsQuery $query, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $period = $this->period($request);
        $environment = $this->environment($request);

        return Inertia::render('events/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => $this->filters($request),
            'events' => fn (): array => $query->get($project->id, $period, $environment),
        ]);
    }

    /**
     * One event's occurrences, newest first or by actor, for one actor or subject if picked.
     */
    public function show(ShowActivityRequest $request, Project $project, string $group, string $name, ActivityStatisticsQuery $query, ProjectPresenter $presenter): Response
    {
        $period = $this->period($request);
        $environment = $this->environment($request);
        $actor = $request->actor();
        $subject = $request->subject();
        $sort = $request->sort();

        $page = Activity::query()
            ->where('project_id', $project->id)
            ->whereEvent($group, $name)
            ->where('occurred_at', '>=', $period->since())
            ->when($environment !== null)->where('environment', $environment)
            ->when($actor !== null)->whereActor($actor['type'] ?? '', $actor['id'] ?? '')
            ->when($subject !== null)->whereSubject($subject['type'] ?? '', $subject['id'] ?? '')
            ->when($sort === 'actor')->orderBy('actor_type')
            ->when($sort === 'actor')->orderBy('actor_id')
            // The id breaks ties of a millisecond, so the cursor never skips or repeats one.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('events/show', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [
                ...$this->filters($request),
                'sort' => $sort,
                'actor_type' => $actor['type'] ?? null,
                'actor_id' => $actor['id'] ?? null,
                'subject_type' => $subject['type'] ?? null,
                'subject_id' => $subject['id'] ?? null,
            ],
            'event' => ['group' => $group, 'name' => $name],
            'stats' => fn (): ?array => $query->get($project->id, $period, $environment, [$group, $name])[0] ?? null,
            'occurrences' => array_map(fn (Activity $a): array => [
                'id' => $a->id,
                'at' => $a->occurred_at->format('Y-m-d\TH:i:s.vP'),
                'actor' => $a->actor_type !== null ? ['type' => $a->actor_type, 'id' => (string) $a->actor_id] : null,
                'subject' => $a->subject_type !== null ? ['type' => $a->subject_type, 'id' => (string) $a->subject_id] : null,
                'payload' => $a->payload,
                'trace_id' => $a->trace_id,
                'environment' => $a->environment,
                'release' => $a->release,
                'host' => $a->host,
            ], $page->items()),
            'next_url' => $page->nextPageUrl(),
            'prev_url' => $page->previousPageUrl(),
            'can_forget' => (bool) $request->user()?->can('update', $project),
        ]);
    }
}
