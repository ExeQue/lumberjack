<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Controllers;

use App\Domains\Activity\Http\Requests\ActivityFiltersRequest;
use App\Domains\Activity\Http\Requests\SearchActivityReferencesRequest;
use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Queries\ActivityReferencesQuery;
use App\Domains\Activity\Queries\ActivityStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController
{
    use ReadsDashboardFilters;

    public const int PER_PAGE = 50;

    /**
     * Every activity in the period, by group, with how often it happened: of one actor or
     * subject if picked.
     */
    public function index(ActivityFiltersRequest $request, Project $project, ActivityStatisticsQuery $query, ProjectPresenter $presenter): Response
    {
        $period = $this->period($request);
        $environment = $this->environment($request);

        return Inertia::render('activity/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => $this->activityFilters($request),
            'activities' => fn (): array => $query->get($project->id, $period, $environment, null, $request->actors(), $request->subjects()),
        ]);
    }

    /**
     * One event's occurrences, newest first or by actor, for one actor or subject if picked.
     */
    public function show(ActivityFiltersRequest $request, Project $project, string $group, string $name, ActivityStatisticsQuery $query, ProjectPresenter $presenter): Response
    {
        $period = $this->period($request);
        $environment = $this->environment($request);
        $actors = $request->actors();
        $subjects = $request->subjects();
        $sort = $request->sort();

        $page = Activity::query()
            ->where('project_id', $project->id)
            ->whereEvent($group, $name)
            ->where('occurred_at', '>=', $period->since())
            ->when($environment !== null)->where('environment', $environment)
            ->when($actors !== [])->whereActorIn($actors)
            ->when($subjects !== [])->whereSubjectIn($subjects)
            // Naturally: user 2 before user 10.
            ->when($sort === 'actor')->orderByRaw('actor_type COLLATE "natural", actor_id COLLATE "natural"')
            // The id breaks ties of a millisecond, so the cursor never skips or repeats one.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('activity/show', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->activityFilters($request), 'sort' => $sort],
            'event' => ['group' => $group, 'name' => $name],
            'stats' => fn (): ?array => $query->get($project->id, $period, $environment, [$group, $name], $actors, $subjects)[0] ?? null,
            'occurrences' => array_map(fn (Activity $a): array => [
                'id' => $a->id,
                'at' => $a->occurred_at->format('Y-m-d\TH:i:s.vP'),
                'actor' => $a->actor_type !== null ? ['type' => $a->actor_type, 'id' => (string) $a->actor_id] : null,
                'subjects' => $a->subjects ?? [],
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

    /**
     * The actors or subjects matching what is typed into a filter field.
     */
    public function references(SearchActivityReferencesRequest $request, Project $project, ActivityReferencesQuery $references): JsonResponse
    {
        return response()->json($references->search(
            $project->id,
            $this->period($request),
            $request->kind(),
            $request->string('q')->toString(),
            $this->environment($request),
            $request->event(),
        ));
    }

    /**
     * @return array{period: string, environment: string|null, actors: list<string>, subjects: list<string>}
     */
    private function activityFilters(ActivityFiltersRequest $request): array
    {
        return [...$this->filters($request), 'actors' => $request->actorKeys(), 'subjects' => $request->subjectKeys()];
    }
}
