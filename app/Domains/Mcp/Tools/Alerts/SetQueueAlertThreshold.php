<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Actions\SetQueueAlertThresholdAction;
use App\Domains\Alerts\Http\Requests\UpdateQueueAlertThresholdRequest;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Alerts, McpLevel::Write)]
class SetQueueAlertThreshold extends Tool
{
    use UsesMcpContext;

    protected string $name = 'set_queue_alert_threshold';

    protected string $description = 'Gives one of a project\'s queues its own backlog alert thresholds, or mutes it. A threshold left out keeps the queue_backlog rule\'s value; leaving all out and muted false goes back to the rule. Returns the queue\'s own thresholds, or null.';

    public function __construct(
        private readonly SetQueueAlertThresholdAction $threshold,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'connection' => $schema->string()->description('The queue connection, as list_jobs reports it, e.g. redis.')->required(),
            'queue' => $schema->string()->description('The queue name, e.g. default.')->required(),
            'size' => $schema->integer()->description('Alert above this many jobs waiting.'),
            'oldest_minutes' => $schema->integer()->description('Alert when the oldest job has waited longer than this.'),
            'sustained_minutes' => $schema->integer()->description('For how long either has to hold.'),
            'muted' => $schema->boolean()->description('No backlog alerts for this queue.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        if (($refused = $this->authorize('update', $project)) !== null) {
            return $refused;
        }

        $input = $request->validate(UpdateQueueAlertThresholdRequest::fieldRules());
        $own = $this->threshold->execute(
            $project,
            $input['connection'],
            $input['queue'],
            isset($input['size']) ? (int) $input['size'] : null,
            isset($input['oldest_minutes']) ? (int) $input['oldest_minutes'] : null,
            isset($input['sustained_minutes']) ? (int) $input['sustained_minutes'] : null,
            (bool) $input['muted'],
        );

        return Response::json(['project' => $project->slug, 'threshold' => $own?->toSummary()]);
    }
}
