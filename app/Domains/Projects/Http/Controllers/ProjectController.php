<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Projects\Actions\CreateProjectAction;
use App\Domains\Projects\Actions\DeleteProjectAction;
use App\Domains\Projects\Http\Requests\StoreProjectRequest;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\Percentiles;
use App\Domains\Shared\Support\TimeSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController
{
    public function index(Request $request): Response|RedirectResponse
    {
        $team = $request->user()?->currentTeam;

        if ($team === null) {
            // An administrator without a team, such as the first one, starts by creating teams.
            return redirect()->route($request->user()?->isAdmin() ? 'admin.teams.index' : 'team.show');
        }

        $since = Period::Day->since();
        $p95 = Percentiles::select('duration_us', 0.95, 'p95');

        $projects = $team->projects()->orderBy('name')->with('agents')->get()->map(function (Project $project) use ($since, $p95): array {
            $latency = DB::selectOne("WITH ranked AS (SELECT duration_us, ROW_NUMBER() OVER (ORDER BY duration_us) AS rn, COUNT(*) OVER () AS cnt FROM request_runs WHERE project_id = ? AND occurred_at >= ?) SELECT {$p95} FROM ranked", [$project->id, $since->format('Y-m-d H:i:s')]);
            $jobs = JobRun::query()->where('project_id', $project->id)->where('created_at', '>=', $since);
            $runs = (clone $jobs)->count();
            $offline = $project->agents->reject(fn (ProjectAgent $a): bool => $a->isOnline());

            return [
                'uuid' => $project->uuid,
                'slug' => $project->slug,
                'name' => $project->name,
                'open_issues' => Issue::query()->where('project_id', $project->id)->whereOpen()->count(),
                'errors' => DB::table('occurrences')->where('project_id', $project->id)->where('occurred_at', '>=', $since)->count(),
                'p95' => (int) ($latency->p95 ?? 0),
                'job_failure_rate' => $runs > 0 ? round((clone $jobs)->whereStatus(JobRunStatus::Failed)->count() / $runs, 4) : 0.0,
                'error_series' => TimeSeries::count(DB::table('occurrences')->where('project_id', $project->id), Period::Day),
                'agents' => $project->agents->count(),
                'offline_agents' => $offline->pluck('host')->values()->all(),
                'last_agent_seen_at' => $project->agents->max('last_seen_at')?->toIso8601String(),
            ];
        });

        return Inertia::render('projects/index', ['projects' => $projects]);
    }

    public function store(StoreProjectRequest $request, CreateProjectAction $action): RedirectResponse
    {
        ['project' => $project, 'token' => $token] = $action->execute($request->user()->currentTeam, $request->string('name')->toString(), $request->string('issue_prefix')->toString());

        return redirect()->route('projects.settings', $project)
            ->with('status', __('projects.created'))
            ->with('secret', ['label' => __('projects.server_token'), 'value' => $token]);
    }

    public function destroy(Request $request, Project $project, DeleteProjectAction $action): RedirectResponse
    {
        Gate::authorize('delete', $project);
        $action->execute($project);

        return redirect()->route('projects.index')->with('status', __('projects.deleted'));
    }
}
