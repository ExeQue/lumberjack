<?php

declare(strict_types=1);

namespace App\Domains\Search\Queries;

use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\LikePattern;
use Illuminate\Support\Facades\DB;

/**
 * What the command palette finds in one project: issues, routes, jobs, commands and
 * scheduled tasks by name, and a trace when the term looks like a trace id.
 */
class ProjectSearchQuery
{
    private const int LIMIT = 5;

    /**
     * @return list<array{group: string, label: string, detail: ?string, href: string}>
     */
    public function get(Project $project, string $term): array
    {
        $like = LikePattern::contains($term);
        // ponytail: scans the last week of runs by name; a names table if this gets slow.
        $since = now()->subDays(7);
        $results = [];

        if (preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $term) === 1 && $this->traceExists($project, $term)) {
            $results[] = ['group' => 'traces', 'label' => $term, 'detail' => null, 'href' => route('traces.show', [$project, $term])];
        }

        foreach ($project->issues()->whereSearch($term)->orderByDesc('last_seen_at')->limit(self::LIMIT)->get() as $issue) {
            /** @var Issue $issue */
            $results[] = [
                'group' => 'issues',
                'label' => $issue->title,
                'detail' => $issue->number !== null ? $issue->reference() : null,
                'href' => route('issues.show', [$project, $issue]),
            ];
        }

        $routes = DB::table('request_runs')
            ->where('project_id', $project->id)
            ->where('occurred_at', '>=', $since)
            ->where(fn ($q) => $q->whereRaw('COALESCE(route, url) ILIKE ?', [$like])->orWhere('route_name', 'ILIKE', $like))
            ->selectRaw('method, COALESCE(route, url) AS route, MAX(route_name) AS route_name, COUNT(*) AS c')
            ->groupByRaw('method, COALESCE(route, url)')
            ->orderByDesc('c')
            ->limit(self::LIMIT)
            ->get();

        foreach ($routes as $row) {
            $results[] = [
                'group' => 'routes',
                'label' => $row->route,
                'detail' => $row->route_name !== null ? "{$row->method} {$row->route_name}" : $row->method,
                'href' => route('routes.index', [$project, 'route' => $row->route, 'method' => $row->method]),
            ];
        }

        foreach (['job_runs' => ['jobs', 'jobs.index', 'job'], 'command_runs' => ['commands', 'commands.index', 'command']] as $table => [$group, $name, $param]) {
            $names = DB::table($table)
                ->where('project_id', $project->id)
                ->where('started_at', '>=', $since)
                ->where('name', 'ilike', $like)
                ->distinct()
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->pluck('name');

            foreach ($names as $found) {
                $results[] = ['group' => $group, 'label' => $found, 'detail' => null, 'href' => route($name, [$project, $param => $found])];
            }
        }

        $tasks = DB::table('scheduled_tasks')
            ->where('project_id', $project->id)
            ->where('name', 'ilike', $like)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['name', 'expression']);

        foreach ($tasks as $task) {
            $results[] = ['group' => 'schedule', 'label' => $task->name, 'detail' => $task->expression, 'href' => route('schedule.index', $project)];
        }

        return $results;
    }

    private function traceExists(Project $project, string $trace): bool
    {
        return collect(['request_runs', 'job_runs', 'command_runs', 'scheduled_task_runs'])
            ->contains(fn (string $table): bool => DB::table($table)->where('project_id', $project->id)->where('trace_id', $trace)->exists());
    }
}
