<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Issues\Models\Issue;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController
{
    public const int CARDS_SECONDS = 60;

    public function index(Request $request): Response|RedirectResponse
    {
        $team = $request->user()?->currentTeam;

        if ($team === null) {
            // An administrator without a team, such as the first one, starts by creating teams.
            return redirect()->route($request->user()?->isAdmin() ? 'admin.teams.index' : 'team.show');
        }

        $projects = $team->projects()->orderBy('name')->with('agents')->get();
        $stats = $this->cardStats($projects->map(fn (Project $p): int => $p->id)->all());

        $cards = $projects->map(function (Project $project) use ($stats): array {
            $offline = $project->agents->reject(fn (ProjectAgent $a): bool => $a->isOnline());

            return [
                'uuid' => $project->uuid,
                'slug' => $project->slug,
                'name' => $project->name,
                ...$stats[$project->id],
                'agents' => $project->agents->count(),
                'offline_agents' => $offline->pluck('host')->values()->all(),
                'last_agent_seen_at' => $project->agents->max('last_seen_at')?->toIso8601String(),
            ];
        });

        return Inertia::render('projects/index', ['projects' => $cards]);
    }

    /**
     * The day's figures of every card, a few grouped queries for all projects, reused for
     * a minute. The project list and its agents are read fresh, so a new project shows.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{open_issues: int, errors: int, p95: int|null, job_failure_rate: float|null, error_series: list<array{t: string, v: int|float}>}>
     */
    private function cardStats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        sort($ids);

        return Cache::remember('projects:cards:'.implode(',', $ids), self::CARDS_SECONDS, function () use ($ids): array {
            $since = Period::Day->since();
            $p95 = DB::table('request_runs')->whereIn('project_id', $ids)->where('occurred_at', '>=', $since)
                ->groupBy('project_id')->selectRaw('project_id, '.Percentiles::select('duration_us', '0.95', 'p95'))->pluck('p95', 'project_id');
            $jobs = DB::table('job_runs')->whereIn('project_id', $ids)->where('created_at', '>=', $since)
                ->groupBy('project_id')->selectRaw("project_id, COUNT(*) AS runs, COUNT(*) FILTER (WHERE status = 'failed') AS failed")->get()->keyBy('project_id');
            $open = Issue::query()->whereIn('project_id', $ids)->whereOpen()->groupBy('project_id')->selectRaw('project_id, COUNT(*) AS c')->pluck('c', 'project_id');
            $series = TimeSeries::countBy(DB::table('occurrences')->whereIn('project_id', $ids), Period::Day, 'project_id', $ids);

            return array_combine($ids, array_map(function (int $id) use ($p95, $jobs, $open, $series): array {
                $runs = (int) ($jobs[$id]->runs ?? 0);

                return [
                    'open_issues' => (int) ($open[$id] ?? 0),
                    // The series covers the same day, bucket by bucket.
                    'errors' => (int) array_sum(array_column($series[$id], 'v')),
                    // Null, not 0, where nothing was measured: no requests has no p95.
                    'p95' => isset($p95[$id]) ? (int) $p95[$id] : null,
                    'job_failure_rate' => $runs > 0 ? round((int) $jobs[$id]->failed / $runs, 4) : null,
                    'error_series' => $series[$id],
                ];
            }, $ids));
        });
    }

    public function store(StoreProjectRequest $request, CreateProjectAction $action): RedirectResponse
    {
        ['project' => $project, 'token' => $token] = $action->execute($request->user()->currentTeam, $request->string('name')->toString(), $request->string('issue_prefix')->toString());

        // A new project starts on how to install the SDK, with its keys.
        // (A query, not #install: a redirect drops the fragment.)
        return redirect()->route('projects.settings', [$project, 'section' => 'install'])
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
