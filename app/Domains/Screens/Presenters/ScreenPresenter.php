<?php

declare(strict_types=1);

namespace App\Domains\Screens\Presenters;

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Projects\Queries\ProjectEnvironmentsQuery;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenLayout;
use App\Domains\Screens\Support\ScreenQr;
use App\Domains\Screens\Support\WidgetSchema;
use App\Domains\Screens\Support\WidgetSources;
use App\Domains\Teams\Models\Team;
use App\Models\User;

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
            'widgets' => ScreenLayout::positioned($screen->widgets, $screen->cols, $screen->rows),
            'updated_at' => $screen->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Everything the kiosk draws from: the screen, its team's projects, the widget
     * catalog, and where it reads data and hears about changes, which differ for a
     * signed-in kiosk, a paired display and a share link. A theme forces the display's.
     *
     * @param  array{data_url: string, channel: string, share?: bool}  $source
     * @return array<string, mixed>
     */
    public function kiosk(Screen $screen, array $source, ?ScreenTheme $theme = null): array
    {
        $shown = $screen;

        if ($theme !== null) {
            $shown = clone $screen;
            $shown->theme = $theme;
        }

        return [
            'screen' => $this->present($shown),
            'team' => ['name' => $screen->team->name],
            'projects' => $this->projects($screen->team),
            ...$this->catalog(),
            'data_url' => $source['data_url'],
            'channel' => $source['channel'],
            'share' => $source['share'] ?? false,
        ];
    }

    /**
     * The share link panel of the designer.
     *
     * @return array{url: string|null, include_titles: bool, qr: string|null, can_manage: bool}
     */
    public function sharing(Screen $screen, User $user): array
    {
        $url = $screen->shareUrl();

        return [
            'url' => $url,
            'include_titles' => $screen->share_titles,
            'qr' => $url !== null ? ScreenQr::svg($url) : null,
            'can_manage' => $user->can('share', $screen),
        ];
    }

    /**
     * How to put a screen on a TV: the address to open on it, and where to confirm its code.
     *
     * @return array{url: string, pair_url: string, qr: string}
     */
    public function tvInstructions(): array
    {
        return ['url' => route('tv'), 'pair_url' => route('pair'), 'qr' => ScreenQr::svg(route('tv'))];
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
            // Names a filter can pick from, so a queue, host or database check is chosen, not typed.
            'filters' => [
                'queue' => QueueSample::query()->whereIn('project_id', $projects->modelKeys())->where('sampled_at', '>=', now()->subDays(7))
                    ->distinct()->orderBy('queue')->pluck('queue')->all(),
                'host' => ProjectAgent::query()->whereIn('project_id', $projects->modelKeys())->distinct()->orderBy('host')->pluck('host')->all(),
                'check' => HealthCheck::query()->whereIn('project_id', $projects->modelKeys())->whereNotArchived()->whereReportsDatabase()
                    ->distinct()->orderBy('slug')->pluck('slug')->all(),
            ],
            'limits' => ['min_cols' => Screen::MIN_COLS, 'max_cols' => Screen::MAX_COLS, 'min_rows' => Screen::MIN_ROWS, 'max_rows' => Screen::MAX_ROWS, 'max_widgets' => WidgetSchema::MAX_WIDGETS],
        ];
    }
}
