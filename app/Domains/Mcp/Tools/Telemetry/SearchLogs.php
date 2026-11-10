<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Logs\Enums\LogLevel;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class SearchLogs extends Tool
{
    use UsesMcpContext;

    protected string $name = 'search_logs';

    protected string $description = 'Log lines filtered by channel, minimum level, full-text search, period and trace id, newest first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'channel' => $schema->string()->description('Log channel name, e.g. default or payments.'),
            'level' => $schema->string()->enum(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])->description('Minimum level.'),
            'search' => $schema->string(),
            'trace_id' => $schema->string(),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d']),
            'limit' => $schema->integer()->min(1)->max(200),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $channel = (string) $request->get('channel', '');
        $search = (string) $request->get('search', '');
        $trace = (string) $request->get('trace_id', '');
        $channelId = $channel !== '' ? $project->logChannels()->where('name', $channel)->value('id') : null;

        $lines = LogEntry::query()
            ->where('project_id', $project->id)
            ->where('occurred_at', '>=', (Period::tryFrom((string) $request->get('period')) ?? Period::Day)->since())
            ->whereLevelAtLeast(LogLevel::fromName((string) $request->get('level', 'debug')))
            ->when($channel !== '')->where('log_channel_id', $channelId ?? 0)
            ->when($search !== '')->whereMessageMatches($search)
            ->when($trace !== '')->where('trace_id', $trace)
            ->with('channel')
            ->latest('occurred_at')
            ->limit(min(200, max(1, (int) $request->get('limit', 50))))
            ->get();

        return Response::json(['lines' => $lines->map(fn (LogEntry $l): array => [
            'at' => $l->occurred_at->format('Y-m-d\TH:i:s.vP'),
            'level' => $l->level->key(),
            'channel' => $l->channel?->name,
            'sent_channel' => $l->sent_channel,
            'message' => $l->message,
            'context' => $l->context,
            'trace_id' => $l->trace_id,
            'environment' => $l->environment,
        ])->all()]);
    }
}
