<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Metrics\Http\Requests\ReadMetricsRequest;
use App\Domains\Metrics\Http\Requests\ReadSeriesRequest;
use App\Domains\Metrics\Queries\MetricSeriesQuery;
use App\Domains\Metrics\Queries\MetricsTablesQuery;
use App\Domains\Metrics\Queries\ProjectSummaryQuery;
use App\Domains\Metrics\Support\MetricsCredential;
use App\Domains\Metrics\Support\PrometheusExporter;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Prometheus\RenderTextFormat;

/**
 * The read-only metrics API under /api/v1, with a token or a public stats key. Every
 * answer is cached for 30 seconds per identical question.
 */
class MetricsApiController
{
    public function __construct(
        private readonly MetricsCredential $credential,
    ) {
    }

    public function projects(): JsonResponse
    {
        return response()->json(['projects' => $this->credential->projects()->map(fn (Project $p): array => [
            'uuid' => $p->uuid,
            'slug' => $p->slug,
            'name' => $p->name,
        ])->values()->all()]);
    }

    public function summary(ReadMetricsRequest $request, string $project, ProjectSummaryQuery $summary): JsonResponse
    {
        $project = $this->project($project);

        return response()->json($this->credential->remember(
            'summary',
            [$project->id, $request->period()->value, $request->environment()],
            fn (): array => $summary->get($project, $request->period(), $request->environment())
        ));
    }

    public function series(ReadSeriesRequest $request, string $project, MetricSeriesQuery $series): JsonResponse
    {
        $project = $this->project($project);
        $metric = $request->metric();
        $window = $request->window();

        return response()->json($this->credential->remember('series', [$project->id, ...array_values($request->cacheArgs())], fn (): array => [
            'metric' => $metric->value,
            'unit' => $metric->unit(),
            'interval' => $window->interval(),
            'from' => $window->from->toIso8601ZuluString(),
            'to' => $window->to->toIso8601ZuluString(),
            'points' => $series->series($metric, $request->filter($project), $window, $request->top()),
        ]));
    }

    public function routes(ReadMetricsRequest $request, string $project, MetricsTablesQuery $tables): JsonResponse
    {
        $project = $this->project($project);

        return response()->json($this->credential->remember(
            'routes',
            [$project->id, $request->period()->value, $request->environment(), $request->limit()],
            fn (): array => $tables->routes($project, $request->period(), $request->environment(), $request->limit(), $this->credential->isPublic())
        ));
    }

    public function issues(ReadMetricsRequest $request, string $project, MetricsTablesQuery $tables): JsonResponse
    {
        $project = $this->project($project);
        abort_unless($this->credential->readsIssues($project), 403, 'This token may not read issues in this project.');

        return response()->json($this->credential->remember(
            'issues',
            [$project->id, $request->limit()],
            fn (): array => $tables->issues($project, $request->limit(), $this->credential->includesTitles())
        ));
    }

    public function jobs(ReadMetricsRequest $request, string $project, MetricsTablesQuery $tables): JsonResponse
    {
        $project = $this->project($project);

        return response()->json($this->credential->remember(
            'jobs',
            [$project->id, $request->period()->value, $request->environment(), $request->limit()],
            fn (): array => $tables->jobs($project, $request->period(), $request->environment(), $request->limit())
        ));
    }

    public function queues(ReadMetricsRequest $request, string $project, MetricsTablesQuery $tables): JsonResponse
    {
        $project = $this->project($project);

        return response()->json($this->credential->remember(
            'queues',
            [$project->id, $request->period()->value, $request->environment()],
            fn (): array => $tables->queues($project, $request->period(), $request->environment())
        ));
    }

    public function hosts(string $project, MetricsTablesQuery $tables): JsonResponse
    {
        $project = $this->project($project);

        return response()->json($this->credential->remember(
            'hosts',
            [$project->id],
            fn (): array => $tables->hosts($project, $this->credential->isPublic())
        ));
    }

    public function releases(ReadMetricsRequest $request, string $project, MetricsTablesQuery $tables): JsonResponse
    {
        $project = $this->project($project);

        return response()->json($this->credential->remember(
            'releases',
            [$project->id, $request->period()->value, $request->environment(), $request->limit(50)],
            fn (): array => $tables->releases($project, $request->period(), $request->environment(), $request->limit(50))
        ));
    }

    public function prometheus(Request $request, PrometheusExporter $exporter): Response
    {
        $projects = $this->credential->projects();

        return response($this->credential->remember('prometheus', $projects->modelKeys(), fn (): string => $exporter->render($projects)))
            ->header('Content-Type', RenderTextFormat::MIME_TYPE);
    }

    /**
     * A project the credential reads; any other is not found, whether it exists or not.
     */
    private function project(string $reference): Project
    {
        return $this->credential->project($reference) ?? abort(404, 'Unknown project.');
    }
}
