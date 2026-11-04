<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Commands\Enums\CommandRunStatus;
use App\Domains\Commands\Models\CommandRun;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Shared\Support\Translations;
use App\Domains\Teams\Models\Team;
use Illuminate\Http\Request;
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
            'translations' => fn (): array => Translations::forFrontend(app()->getLocale()),
            'auth' => [
                'user' => $user,
            ],
            // Read when rendering: a route may have switched team in the meantime.
            'team' => fn (): ?array => ($team = $user?->currentTeam) === null ? null : [
                'uuid' => $team->uuid,
                'name' => $team->name,
                'role' => $user->roleIn($team)?->value,
                'can_administer' => $user->canAdministerTeam($team),
                // A system administrator looking into a team they are not in.
                'visiting' => $user->isAdmin() && ! $team->members()->whereKey($user->id)->exists(),
                'projects' => $team->projects()->orderBy('name')->get(['uuid', 'slug', 'name'])->toArray(),
            ],
            'sidebar_project' => fn (): ?array => $this->sidebarProject($request),
            // An administrator can open every team.
            'teams' => fn (): array => ($user?->isAdmin() ? Team::query() : $user?->teams())?->orderBy('name')->get(['teams.uuid', 'teams.name'])->map(fn ($t): array => ['uuid' => $t->uuid, 'name' => $t->name])->all() ?? [],
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
     * @return array{uuid: string, name: string, can_administer: bool, counts: array{issues: int, jobs: int, schedule: int, health: int, commands: int}}|null
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
            // Reused for half a minute, and forgotten when a live broadcast says they changed.
            'counts' => Cache::remember(self::sidebarCountsKey($project->id), self::SIDEBAR_COUNTS_SECONDS, fn (): array => [
                'issues' => Issue::query()->where('project_id', $project->id)->whereOpen()->count(),
                // Runs that need a look: failed or stuck in the last day.
                'jobs' => JobRun::query()->where('project_id', $project->id)->where('created_at', '>=', now()->subDay())
                    ->whereStatus(JobRunStatus::Failed, JobRunStatus::Stuck)->count(),
                'schedule' => ScheduledTask::query()->where('project_id', $project->id)->whereNeedsAttention()->count(),
                'health' => HealthCheck::query()->where('project_id', $project->id)->whereNotArchived()->whereUnhealthy()->count(),
                'commands' => CommandRun::query()->where('project_id', $project->id)->where('created_at', '>=', now()->subDay())
                    ->where('status', CommandRunStatus::Failed->value)->count(),
            ]),
        ];
    }
}
