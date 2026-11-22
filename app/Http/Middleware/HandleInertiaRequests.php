<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Commands\Enums\CommandRunStatus;
use App\Domains\Commands\Models\CommandRun;
use App\Domains\Connectors\Enums\Connector;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Shared\Support\AppVersion;
use App\Domains\Shared\Support\Translations;
use App\Domains\Teams\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** The project a browser tab showed last, for its sidebar on team pages. */
    public const string PROJECT_HEADER = 'X-Lumberjack-Project';

    public const int SIDEBAR_COUNTS_SECONDS = 30;

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'app_version' => fn (): string => AppVersion::version(),
            'repository_url' => AppVersion::REPOSITORY_URL,
            'update_available' => fn (): ?string => $user?->isAdmin() ? AppVersion::updateAvailable() : null,
            // The strings themselves (about 75 KB) are a cached file of their own; the page
            // gets where it is, and the layout preloads it.
            'translations_url' => Translations::url(app()->getLocale()),
            'auth' => [
                'user' => $user,
            ],
            // Read when rendering: a route may have switched team in the meantime.
            'team' => fn (): ?array => ($team = $user?->currentTeam) === null ? null : [
                'uuid' => $team->uuid,
                'name' => $team->name,
                // What AI cost is shown in.
                'currency' => $team->currency,
                'role' => $user->roleIn($team)?->value,
                'can_administer' => $user->canAdministerTeam($team),
                // A system administrator looking into a team they are not in.
                'visiting' => $user->isAdmin() && ! $team->members()->whereKey($user->id)->exists(),
                // `attention`: what needs a look in it, the sidebar's counts added up.
                'projects' => ($projects = $team->projects()->orderBy('name')->get(['id', 'uuid', 'slug', 'name']))
                    ->each(self::withAttention($projects))->map(fn (Project $project): array => $project->only('uuid', 'slug', 'name', 'attention'))->all(),
            ],
            'sidebar_project' => fn (): ?array => $this->sidebarProject($request),
            // An administrator can open every team.
            'teams' => function () use ($user): array {
                $teams = ($user?->isAdmin() ? Team::query() : $user?->teams())?->with('projects:id,team_id')->orderBy('name')->get(['teams.id', 'teams.uuid', 'teams.name']);

                if ($teams === null) {
                    return [];
                }

                $projects = $teams->flatMap(fn (Team $t): Collection => $t->projects);
                $projects->each(self::withAttention($projects));

                return $teams->map(fn (Team $t): array => ['uuid' => $t->uuid, 'name' => $t->name, 'attention' => $t->projects->sum('attention')])->all();
            },
            'is_admin' => fn (): bool => $user?->isAdmin() ?? false,
            // The bell's badge; the list itself loads when it opens.
            'notifications_unread' => fn (): int => $user?->unreadNotifications()->count() ?? 0,
            'flash' => fn (): array => [
                'status' => $request->session()->get('status'),
                'secret' => $request->session()->get('secret'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    public static function sidebarCountsKey(int $projectId): string
    {
        return 'sidebar:'.$projectId.':counts';
    }

    /**
     * The project the sidebar keeps showing on pages that are not about one
     * project: the one being viewed, else the last one visited, else the first.
     *
     * @return array{uuid: string, name: string, can_administer: bool, connectors: array<string, list<array{uuid: string, name: string}>>, counts: array{issues: int, jobs: int, schedule: int, health: int, commands: int}}|null
     */
    private function sidebarProject(Request $request): ?array
    {
        $user = $request->user();
        $team = $user?->currentTeam;

        if ($team === null) {
            return null;
        }

        $routed = $request->route('project');

        if ($routed instanceof Project) {
            $request->session()->put('last_project', $routed->uuid);
        }

        // The tab's own last project first (see UseTabTeam), then the session's.
        $last = $request->header(self::PROJECT_HEADER) ?: $request->session()->get('last_project');
        $project = $routed instanceof Project ? $routed : $team->projects()->where('uuid', $last)->first();
        $project ??= $team->projects()->orderBy('name')->first();

        return $project === null ? null : [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'can_administer' => $user->can('update', $project),
            // A connector is under Connectors only once connected, its connections below it when several.
            'connectors' => $this->sidebarConnectors($project),
            // The team's other projects at once: the switchers want theirs too.
            'counts' => self::projectCounts($team->projects()->pluck('id')->all())[$project->id],
        ];
    }

    /**
     * Sets `attention` on a project: what needs a look in it, its sidebar counts added up,
     * counting all the given projects together.
     *
     * @param  Collection<int, Project>  $projects
     * @return Closure(Project): void
     */
    private static function withAttention(Collection $projects): Closure
    {
        $counts = self::projectCounts($projects->map(fn (Project $project): int => $project->id)->all());

        return fn (Project $project) => $project->setAttribute('attention', array_sum($counts[$project->id]));
    }

    /**
     * What needs a look in each project, by sidebar entry. Reused for half a minute, and
     * forgotten when a live broadcast says they changed; the ones not cached are counted
     * together, a few queries for all of them.
     *
     * @param  array<int>  $projectIds
     * @return array<int, array{issues: int, jobs: int, schedule: int, health: int, commands: int}>
     */
    private static function projectCounts(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $keys = array_combine($projectIds, array_map(self::sidebarCountsKey(...), $projectIds));
        $cached = Cache::many(array_values($keys));
        $missing = array_values(array_filter($projectIds, fn (int $id): bool => $cached[$keys[$id]] === null));

        if ($missing !== []) {
            $count = fn ($query): array => $query->whereIn('project_id', $missing)->groupBy('project_id')
                ->selectRaw('project_id, count(*) as aggregate')->pluck('aggregate', 'project_id')->all();

            $issues = $count(Issue::query()->whereOpen());
            // Runs that need a look: failed or stuck in the last day.
            $jobs = $count(JobRun::query()->where('created_at', '>=', now()->subDay())->whereStatus(JobRunStatus::Failed, JobRunStatus::Stuck));
            $schedule = $count(ScheduledTask::query()->whereNeedsAttention());
            $health = $count(HealthCheck::query()->whereNotArchived()->whereUnhealthy());
            // Commands whose last run failed: one that runs fine again drops off.
            $commands = $count(CommandRun::query()->whereLatestOfItsCommand()->where('status', CommandRunStatus::Failed->value));

            $fresh = [];

            foreach ($missing as $id) {
                $fresh[$keys[$id]] = [
                    'issues' => $issues[$id] ?? 0,
                    'jobs' => $jobs[$id] ?? 0,
                    'schedule' => $schedule[$id] ?? 0,
                    'health' => $health[$id] ?? 0,
                    'commands' => $commands[$id] ?? 0,
                ];
            }

            Cache::putMany($fresh, self::SIDEBAR_COUNTS_SECONDS);
            $cached = [...$cached, ...$fresh];
        }

        // A cache other than the array one hands the numbers back as strings.
        return array_map(fn (string $key): array => [
            'issues' => (int) $cached[$key]['issues'],
            'jobs' => (int) $cached[$key]['jobs'],
            'schedule' => (int) $cached[$key]['schedule'],
            'health' => (int) $cached[$key]['health'],
            'commands' => (int) $cached[$key]['commands'],
        ], $keys);
    }

    /**
     * Each connected connector's connections by its key, in name order; none for one not connected.
     *
     * @return array<string, list<array{uuid: string, name: string}>>
     */
    private function sidebarConnectors(Project $project): array
    {
        return $project->dataSourceConnections()
            ->whereIn('kind', collect(Connector::cases())->map(fn (Connector $connector): ConnectionKind => $connector->kind()))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'uuid', 'kind', 'name', 'environment'])
            ->groupBy(fn (DataSourceConnection $connection): string => Connector::forKind($connection->kind)->value ?? '')
            ->map(fn (Collection $connections): array => array_values($connections->map(fn (DataSourceConnection $connection): array => [
                'uuid' => $connection->uuid,
                'name' => $connection->displayName(),
            ])->all()))
            ->all();
    }
}
