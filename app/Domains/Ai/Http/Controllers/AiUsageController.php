<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Http\Requests\AiReferenceRequest;
use App\Domains\Ai\Http\Requests\AiUsageFiltersRequest;
use App\Domains\Ai\Http\Requests\LookUpAiKeyRequest;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Presenters\AiBudgetPresenter;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Ai\Queries\AiBudgetQuery;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Ai\Support\AiPriceBook;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the project's AI calls used and cost: in all, per key owner and per actor.
 */
class AiUsageController
{
    use ReadsDashboardFilters;

    public function __construct(
        private readonly AiBudgetQuery $budgets,
        private readonly AiBudgetPresenter $budgetPresenter,
    ) {
    }

    /** Cost is read over weeks more than hours. */
    public const Period DEFAULT_PERIOD = Period::Month;

    public function index(AiUsageFiltersRequest $request, Project $project, AiUsagePresenter $usage, ProjectPresenter $presenter): Response
    {
        $period = $this->period($request, self::DEFAULT_PERIOD);
        $environment = $this->environment($request);
        $book = AiPriceBook::for($project);
        $now = now()->toImmutable();

        return Inertia::render('ai/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [
                ...$this->filters($request, self::DEFAULT_PERIOD),
                'keys_search' => $request->search('keys'),
                'owners_search' => $request->search('owners'),
                'actors_search' => $request->search('actors'),
            ],
            'has_data' => AiUsageDaily::query()->where('project_id', $project->id)->exists(),
            'docs_url' => rtrim((string) config('lumberjack.docs_url'), '/').'/docs/ai',
            // What Find a key hashes the key with.
            'lookup_drivers' => LookUpAiKeyRequest::drivers($project),
            'summary' => Inertia::defer(fn (): array => $usage->summary($project, $period, $environment, book: $book, now: $now)),
            'chart' => Inertia::defer(fn (): array => $usage->chart($project, $period, $environment, book: $book, now: $now)),
            'keys' => Inertia::defer(fn (): array => $this->top($request, $project, $period, $environment, 'keys', $usage, $book, $now), 'tables'),
            'owners' => Inertia::defer(fn (): array => $this->top($request, $project, $period, $environment, 'owners', $usage, $book, $now), 'tables'),
            'actors' => Inertia::defer(fn (): array => $this->top($request, $project, $period, $environment, 'actors', $usage, $book, $now), 'tables'),
            'can_update' => (bool) $request->user()?->can('update', $project),
        ]);
    }

    /**
     * @param  'keys'|'owners'|'actors'  $table
     * @return array<string, mixed>
     */
    private function top(AiUsageFiltersRequest $request, Project $project, Period $period, ?string $environment, string $table, AiUsagePresenter $usage, AiPriceBook $book, CarbonImmutable $now): array
    {
        return $usage->top($project, $period, $environment, $table, $request->search($table), max(1, $request->integer($table.'_page', 1)), $request->url(), $book, $now);
    }

    /**
     * One key owner's usage: over time per model, and per key.
     */
    public function owner(AiReferenceRequest $request, Project $project, AiUsagePresenter $usage, ProjectPresenter $presenter): Response
    {
        return $this->reference($request, $project, 'owner', $usage, $presenter);
    }

    /**
     * One actor's usage: over time per model, and per key.
     */
    public function actor(AiReferenceRequest $request, Project $project, AiUsagePresenter $usage, ProjectPresenter $presenter): Response
    {
        return $this->reference($request, $project, 'actor', $usage, $presenter);
    }

    /**
     * @param  'owner'|'actor'  $kind
     */
    private function reference(AiReferenceRequest $request, Project $project, string $kind, AiUsagePresenter $usage, ProjectPresenter $presenter): Response
    {
        $period = $this->period($request, self::DEFAULT_PERIOD);
        $environment = $this->environment($request);
        $only = [$kind => [$request->reference()]];
        $book = AiPriceBook::for($project);
        $now = now()->toImmutable();
        // Actors are only in the daily totals; owners are in the hourly ones too, priced by the hour.
        $table = $kind === 'actor' ? AiCostQuery::DAILY : AiCostQuery::HOURLY;

        return Inertia::render('ai/reference', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => $this->filters($request, self::DEFAULT_PERIOD),
            'kind' => $kind,
            'reference' => $request->reference(),
            'summary' => Inertia::defer(fn (): array => $usage->summary($project, $period, $environment, $table, $only, $book, $now)),
            'chart' => Inertia::defer(fn (): array => $usage->chart($project, $period, $environment, $table, $only, $book, $now)),
            'by_key' => Inertia::defer(fn (): array => $usage->byKey($project, $period, $environment, $table, $only, $book, $now)),
            'budgets' => Inertia::defer(fn (): array => $this->budgetPresenter->presentAll($this->budgets->covering($project, AiBudgetScope::from($kind), $request->reference()['type'], $request->reference()['id']), $book)),
        ]);
    }
}
