<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Actions\RecordCustomAlertAction;
use App\Domains\Alerts\Enums\CustomAlertLevel;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * A custom alert as an app sends one, so it takes the same topic, cut-down and per-project
 * rate limit and dedupe.
 */
#[IsOpenWorld]
#[RequiresPermission(McpArea::Alerts, McpLevel::Write)]
class SendProjectAlert extends Tool
{
    use UsesMcpContext;

    protected string $name = 'send_project_alert';

    protected string $description = 'Sends a custom alert for a project to the channels of its topic (see list_alert_topics), as the app\'s own code would. Returns the alert, with suppressed set when it was a duplicate (dedupe_key) or over the project\'s limit of '.RecordCustomAlertAction::PER_MINUTE.' a minute.';

    public function __construct(
        private readonly RecordCustomAlertAction $record,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'title' => $schema->string()->required(),
            'message' => $schema->string(),
            'level' => $schema->string()->enum(array_map(fn (CustomAlertLevel $level): string => $level->value, CustomAlertLevel::cases()))->description('Sets color and urgency. Defaults to info.'),
            'topic' => $schema->string()->description('Defaults to "default". A new topic appears without channels.'),
            'environment' => $schema->string()->description('A topic limited to some environments only sends for those.'),
            'url' => $schema->string()->description('An http(s) link for the title.'),
            'fields' => $schema->array()->items($schema->object())->description('Each {name, value, inline}.'),
            'links' => $schema->array()->items($schema->object())->description('Each {label, url, style}; style is primary or danger. At most 5.'),
            'dedupe_key' => $schema->string()->description('Alerts with the same key within dedupe_minutes are kept but not sent.'),
            'dedupe_minutes' => $schema->integer(),
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

        $input = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'level' => ['nullable', Rule::enum(CustomAlertLevel::class)],
            'topic' => ['nullable', 'string', 'max:64'],
            'environment' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:512'],
            'fields' => ['nullable', 'array'],
            'links' => ['nullable', 'array'],
            'dedupe_key' => ['nullable', 'string', 'max:255'],
            'dedupe_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $alert = $this->record->execute($project, $input, new Envelope($input['environment'] ?? null, null, null));

        if ($alert === null) {
            return Response::error('The alert needs a title.');
        }

        return Response::json([
            'project' => $project->slug,
            'id' => $alert->id,
            'topic' => $alert->topic,
            'level' => $alert->level->value,
            'environment' => $alert->environment,
            'suppressed' => $alert->suppressed,
        ]);
    }
}
