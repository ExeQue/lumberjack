<?php

declare(strict_types=1);

namespace App\Domains\Screens\Presenters;

use App\Domains\Activity\Models\ActivityCount;
use App\Domains\Activity\Models\ActivityLabel;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Projects\Queries\ProjectEnvironmentsQuery;
use App\Domains\Screens\Actions\ResolveScreenDataAction;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\ScreenVersion;
use App\Domains\Screens\Support\ScreenLayout;
use App\Domains\Screens\Support\ScreenQr;
use App\Domains\Screens\Support\WidgetSchema;
use App\Domains\Screens\Support\WidgetSources;
use App\Domains\Shared\Enums\Period;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ScreenPresenter
{
    /** AI keys the designer offers to pick from per project, the latest used first. */
    public const int AI_KEYS = 200;

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
            'aspect' => $screen->aspect->value,
            'orientation' => $screen->orientation->value,
            'fit' => $screen->fit->value,
            'margin' => $screen->margin->value,
            'gap' => $screen->gap->value,
            'show_header' => $screen->show_header,
            'show_clock' => $screen->show_clock,
            'clock_format' => $screen->clock_format,
            'header' => Screen::header($screen->header),
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
            // Empty options and scope go out as {} rather than [], so the designer reads
            // a missing key as missing, not as an array method of the same name.
            'widgets' => array_map(fn (array $w): array => [
                ...$w,
                'options' => (object) ($w['options'] ?? []),
                'scope' => (object) ($w['scope'] ?? []),
            ], ScreenLayout::positioned($screen->widgets, $screen->cols, $screen->rows)),
            'updated_at' => $screen->updated_at?->toIso8601String(),
            'revision' => $screen->revision,
        ];
    }

    /**
     * What the screen can go back to, newest first.
     *
     * @return list<array{uuid: string, revision: int, author: string|null, created_at: string}>
     */
    public function versions(Screen $screen): array
    {
        return array_values(ScreenVersion::query()->whereBelongsTo($screen)->latest('id')->get(['uuid', 'revision', 'author', 'created_at'])
            ->map(fn (ScreenVersion $v): array => ['uuid' => $v->uuid, 'revision' => $v->revision, 'author' => $v->author, 'created_at' => $v->created_at->toIso8601String()])->all());
    }

    /**
     * Everything the kiosk draws from: the screen, the projects it can show, the widget
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
            // Only the projects the screen can show: a share link names no others.
            'projects' => $this->screenProjects($screen),
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
     * The projects a screen's widgets can cover: those it names, or all of its team's.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function screenProjects(Screen $screen): array
    {
        $ceiling = ResolveScreenDataAction::screenProjects($screen);
        $projects = $this->projects($screen->team);

        return $ceiling === [] ? $projects : array_values(array_filter($projects, fn (array $p): bool => in_array($p['uuid'], $ceiling, true)));
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
     * The windows showing this screen that said how large they are, to preview it at, by
     * device and window: "Office PC, window 2".
     *
     * @return list<array{name: string, w: int, h: int}>
     */
    public function previewDisplays(Screen $screen): array
    {
        $displays = Display::query()->whereTeam($screen->team)->whereNotNull('viewport_width')->with('device')->orderBy('slot')->get()
            ->filter(fn (Display $d): bool => in_array($screen->id, $d->screenIds(), true))
            ->sortBy(fn (Display $d): string => $d->device->name.'|'.$d->slot);

        return array_values($displays->map(fn (Display $d): array => ['name' => $d->fullName(), 'w' => (int) $d->viewport_width, 'h' => (int) $d->viewport_height])->all());
    }

    /**
     * The team's paired displays, to put the screen on one from the designer: only for
     * whoever may change displays (team admins).
     *
     * @return list<array{uuid: string, name: string, shows_this: bool, rotation: int}>
     */
    public function pairedDisplays(Screen $screen, User $user): array
    {
        if (! $user->canAdministerTeam($screen->team)) {
            return [];
        }

        $displays = Display::query()->whereTeam($screen->team)->with('device')->get()
            ->sortBy(fn (Display $d): string => $d->device->name.'|'.$d->slot);

        return array_values($displays->map(fn (Display $d): array => [
            'uuid' => $d->uuid,
            'name' => $d->fullName(),
            'shows_this' => in_array($screen->id, $d->screenIds(), true),
            'rotation' => (int) $d->rotation,
        ])->all());
    }

    /**
     * Each project's queue, host, domain, database check and activity (group:name) names,
     * by project uuid.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, array<string, list<string>>>
     */
    private function filterNames(Collection $projects): array
    {
        $ids = $projects->modelKeys();
        $uuids = $projects->pluck('uuid', 'id');
        $kinds = [
            'queue' => QueueSample::query()->whereIn('project_id', $ids)->where('sampled_at', '>=', now()->subDays(7))->distinct()->get(['project_id', 'queue as name']),
            'host' => ProjectAgent::query()->whereIn('project_id', $ids)->distinct()->get(['project_id', 'host as name']),
            'domain' => RequestRun::query()->whereIn('project_id', $ids)->where('occurred_at', '>=', now()->subDays(7))->whereNotNull('domain')->distinct()->get(['project_id', 'domain as name']),
            'check' => HealthCheck::query()->whereIn('project_id', $ids)->whereNotArchived()->whereReportsDatabase()->distinct()->get(['project_id', 'slug as name']),
            'activity' => ActivityCount::query()->whereIn('project_id', $ids)->where('hour', '>=', now()->subDays(30))->distinct()->selectRaw('project_id, "group" || \':\' || name AS name')->get(),
        ];
        $names = [];

        foreach ($kinds as $kind => $rows) {
            foreach ($rows as $row) {
                $names[(string) $uuids[$row->getAttribute('project_id')]][$kind][] = (string) $row->getAttribute('name');
            }
        }

        return $names;
    }

    /**
     * The shown name of each activity the filters offer, by group:name.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, array<string, list<string>>>  $filters
     * @return array<string, string>
     */
    private function activityLabels(Collection $projects, array $filters): array
    {
        $labels = ActivityLabel::query()->whereProjectIn(array_values(array_map(intval(...), $projects->modelKeys())))->toMap();
        $shown = [];

        foreach ($filters as $kinds) {
            foreach ($kinds['activity'] ?? [] as $key) {
                [$group, $name] = explode(':', $key, 2) + [1 => ''];
                $shown[$key] = ActivityLabel::shown($labels, $group).': '.ActivityLabel::shown($labels, $group, $name);
            }
        }

        return $shown;
    }

    /**
     * What the designer offers to pick from besides the catalog: the team's environments,
     * health checks and data sources, and the grid's limits.
     *
     * @return array<string, mixed>
     */
    public function designer(Team $team): array
    {
        $projects = $team->projects()->orderBy('name')->get();

        return [
            ...$this->catalog(),
            'environments' => $this->environments->forProjects($projects),
            'checks' => HealthCheck::query()->whereIn('project_id', $projects->modelKeys())->whereNotArchived()->get(['uuid', 'project_id', 'name', 'slug', 'type', 'config'])
                ->map(fn (HealthCheck $c): array => ['uuid' => $c->uuid, 'name' => $c->displayName(), 'project' => $projects->find($c->project_id)?->name, 'project_uuid' => $projects->find($c->project_id)?->uuid])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            // External values pick one; names, formats and the app's host, never a full URL or header.
            'data_sources' => DataSource::query()->whereIn('project_id', $projects->modelKeys())->whereNotArchived()->with('dataSourceConnection:id,url,kind,environment,name')->orderBy('name')->get(['uuid', 'project_id', 'name', 'group_path', 'format', 'shape', 'environment', 'data_source_connection_id'])
                ->map(fn (DataSource $s): array => ['uuid' => $s->uuid, 'name' => $s->name, 'group' => $s->pickerGroup(), 'host' => $s->pickerHost(), 'format' => $s->format->value, 'shape' => $s->shape?->value, 'environment' => $s->environment !== '' && $s->dataSourceConnection?->isFor(ConnectionKind::App) !== false ? $s->environment : null, 'project' => $projects->find($s->project_id)?->name, 'project_uuid' => $projects->find($s->project_id)?->uuid, 'periods' => $s->dataSourceConnection !== null ? array_map(fn (Period $p): string => $p->value, $s->dataSourceConnection->kind->periods()) : null])->values()->all(),
            // An AI budget's or key's widget picks one: by name, never a key's fingerprint.
            'ai_budgets' => $this->aiBudgets($projects),
            'ai_keys' => $this->aiKeys($projects),
            // Names a filter can pick from, so a queue, host, domain or database check is chosen, not
            // typed; per project uuid, so the designer offers only those of the screen's projects.
            'filters' => $filters = $this->filterNames($projects),
            // What an activity filter's names are shown as, by group:name.
            'filter_labels' => $this->activityLabels($projects, $filters),
            'limits' => ['min_cols' => Screen::MIN_COLS, 'max_cols' => Screen::MAX_COLS, 'min_rows' => Screen::MIN_ROWS, 'max_rows' => Screen::MAX_ROWS, 'max_widgets' => WidgetSchema::MAX_WIDGETS],
        ];
    }

    /**
     * The team's AI budgets by their titles, the keys those name looked up at once.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{uuid: string, name: string, project: string|null, project_uuid: string|null}>
     */
    private function aiBudgets(Collection $projects): array
    {
        $budgets = AiBudget::query()->whereProjectIn($projects)->with('targets')->get();
        $fingerprints = array_values(array_unique(array_merge([], ...$budgets->map(fn (AiBudget $b): array => $b->fingerprints())->all())));
        $keys = $fingerprints === [] ? new Collection() : AiKey::query()->whereProjectIn($projects)->whereFingerprint($fingerprints)->get();

        return array_values($budgets
            ->each(fn (AiBudget $b): AiBudget => $b->usingKeys($keys->where('project_id', $b->project_id)))
            ->map(fn (AiBudget $b): array => ['uuid' => $b->uuid, 'name' => $b->title(), 'project' => $projects->find($b->project_id)?->name, 'project_uuid' => $projects->find($b->project_id)?->uuid])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
    }

    /**
     * The keys each project used most lately, AI_KEYS of each, in one query.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{uuid: string, name: string, provider: string|null, project: string|null, project_uuid: string|null}>
     */
    private function aiKeys(Collection $projects): array
    {
        $ranked = AiKey::query()->whereProjectIn($projects)->toBase()
            ->select('*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY last_seen_at DESC NULLS LAST, id) AS rank_in_project');

        return array_values(AiKey::query()->fromSub($ranked, 'ai_keys')->where('rank_in_project', '<=', self::AI_KEYS)->orderByRaw('last_seen_at DESC NULLS LAST')->orderBy('id')->get()
            ->map(fn (AiKey $k): array => ['uuid' => $k->uuid, 'name' => $k->displayName(), 'provider' => $k->provider, 'project' => $projects->find($k->project_id)?->name, 'project_uuid' => $projects->find($k->project_id)?->uuid])->all());
    }
}
