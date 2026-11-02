<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Commands\Enums\CommandRunStatus;
use App\Domains\Commands\Models\CommandRun;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Shared\Support\Translations;
use App\Domains\Teams\Models\Team;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
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
        $team = $user?->currentTeam;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'translations' => fn (): array => Translations::forFrontend(app()->getLocale()),
            'auth' => [
                'user' => $user,
            ],
            'team' => fn (): ?array => $team === null ? null : [
                'uuid' => $team->uuid,
                'name' => $team->name,
                'role' => $user->roleIn($team)?->value,
                'can_administer' => $user->canAdministerTeam($team),
                'projects' => $team->projects()->orderBy('name')->get(['uuid', 'slug', 'name'])->toArray(),
            ],
            'sidebar_project' => fn (): ?array => $this->sidebarProject($request),
            // An administrator can open every team.
            'teams' => fn (): array => ($user?->isAdmin() ? Team::query() : $user?->teams())?->orderBy('name')->get(['teams.uuid', 'teams.name'])->map(fn ($t): array => ['uuid' => $t->uuid, 'name' => $t->name])->all() ?? [],
            'is_admin' => fn (): bool => $user?->isAdmin() ?? false,
            'flash' => fn (): array => [
                'status' => $request->session()->get('status'),
                'secret' => $request->session()->get('secret'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The project the sidebar keeps showing on pages that are not about one
     * project: the one being viewed, else the last one visited, else the first.
     *
     * @return array{uuid: string, name: string, can_administer: bool, counts: array{issues: int, jobs: int, schedule: int, commands: int}}|null
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

        $project = $routed instanceof Project ? $routed : $team->projects()->where('uuid', $request->session()->get('last_project'))->first();
        $project ??= $team->projects()->orderBy('name')->first();

        return $project === null ? null : [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'can_administer' => $user->can('update', $project),
            'counts' => [
                'issues' => Issue::query()->where('project_id', $project->id)->whereOpen()->count(),
                // Runs that need a look: failed or stuck in the last day.
                'jobs' => JobRun::query()->where('project_id', $project->id)->where('created_at', '>=', now()->subDay())
                    ->whereStatus(JobRunStatus::Failed, JobRunStatus::Stuck)->count(),
                'schedule' => ScheduledTask::query()->where('project_id', $project->id)->whereNeedsAttention()->count(),
                'commands' => CommandRun::query()->where('project_id', $project->id)->where('created_at', '>=', now()->subDay())
                    ->where('status', CommandRunStatus::Failed->value)->count(),
            ],
        ];
    }
}
