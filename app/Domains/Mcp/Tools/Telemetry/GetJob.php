<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Jobs\Models\JobRun;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetJob extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_job';

    protected string $description = 'Recent runs of one job class, every attempt with status, timings, host, the parameters it was queued with and the issue a failed attempt became. Filter by uuid to follow one job across attempts.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'name' => $schema->string()->description('Fully qualified job class.')->required(),
            'uuid' => $schema->string()->description('Only the attempts of this job uuid.'),
            'status' => $schema->string()->enum(['queued', 'processing', 'processed', 'released', 'failed', 'stuck']),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $uuid = (string) $request->get('uuid', '');
        $status = (string) $request->get('status', '');

        $runs = JobRun::query()
            ->where('project_id', $project->id)
            ->where('name', (string) $request->get('name'))
            ->when($uuid !== '')->where('job_uuid', $uuid)
            ->when($status !== '')->where('status', $status)
            ->with('occurrence.issue')
            ->latest('id')
            ->limit(50)
            ->get();

        return Response::json(['runs' => $runs->map(fn (JobRun $r): array => [
            'uuid' => $r->job_uuid,
            'attempt' => $r->attempt,
            'status' => $r->status->value,
            'queue' => $r->queue,
            'queued_at' => $r->queued_at?->toIso8601String(),
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'duration_us' => $r->duration_us,
            'host' => $r->host,
            'trace_id' => $r->trace_id,
            'parameters' => $r->parameters,
            'issue' => $r->occurrence?->issue?->uuid,
        ])->all()]);
    }
}
