<?php

declare(strict_types=1);

namespace App\Domains\Screens\Presenters;

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Queries\ProjectEnvironmentsQuery;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\WidgetSchema;
use App\Domains\Screens\Support\WidgetSources;
use App\Domains\Teams\Models\Team;

class ScreenPresenter
{
    public function __construct(
        private readonly ProjectEnvironmentsQuery $environments,
    ) {
    }

    /**
     * What the designer and a kiosk draw a screen from.
     *
     * @return array<string, mixed>
     */
    public function present(Screen $screen): array
    {
        return [
            'uuid' => $screen->uuid,
            'name' => $screen->name,
            'cols' => $screen->cols,
            'rows' => $screen->rows,
            'theme' => $screen->theme->value,
            'light_from' => $screen->light_from,
            'light_to' => $screen->light_to,
            'timezone' => $screen->timezone,
            'current_theme' => $screen->currentTheme()->value,
            'scope' => [
                'projects' => $screen->scope['projects'] ?? [],
                'environment' => $screen->scope['environment'] ?? null,
                'period' => $screen->scope['period'] ?? '24h',
            ],
            'widgets' => $screen->widgets,
            'updated_at' => $screen->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The team's projects, by uuid and name.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function projects(Team $team): array
    {
        return array_values($team->projects()->orderBy('name')->get(['id', 'uuid', 'name'])
            ->map(fn (Project $p): array => ['uuid' => $p->uuid, 'name' => $p->name])->all());
    }

    /**
     * How widgets draw: the option schema and the sources per type.
     *
     * @return array{schema: array<string, mixed>, sources: array<string, list<array<string, mixed>>>}
     */
    public function catalog(): array
    {
        return [
            'schema' => WidgetSchema::all(),
            'sources' => array_map(fn (array $sources): array => array_values(array_map(fn ($s): array => $s->toArray(), $sources)), WidgetSources::all()),
        ];
    }

    /**
     * What the designer offers to pick from besides the catalog: the team's environments
     * and health checks, and the grid's limits.
     *
     * @return array<string, mixed>
     */
    public function designer(Team $team): array
    {
        $projects = $team->projects()->orderBy('name')->get();

        return [
            ...$this->catalog(),
            'environments' => $projects->flatMap(fn (Project $p): array => $this->environments->get($p))->unique()->sort()->values()->all(),
            'checks' => HealthCheck::query()->whereIn('project_id', $projects->modelKeys())->whereNotArchived()->orderBy('name')->orderBy('slug')->get(['uuid', 'project_id', 'name', 'slug', 'type'])
                ->map(fn (HealthCheck $c): array => ['uuid' => $c->uuid, 'name' => $c->displayName(), 'project' => $projects->find($c->project_id)?->name])->values()->all(),
            'limits' => ['min_cols' => Screen::MIN_COLS, 'max_cols' => Screen::MAX_COLS, 'min_rows' => Screen::MIN_ROWS, 'max_rows' => Screen::MAX_ROWS, 'max_widgets' => WidgetSchema::MAX_WIDGETS],
        ];
    }
}
