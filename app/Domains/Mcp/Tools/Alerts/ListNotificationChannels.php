<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Alerts;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Where alerts go, by name and type only: a channel's address, URL or keys never leave
 * the UI.
 */
#[IsReadOnly]
#[RequiresPermission(McpArea::Alerts, McpLevel::Read)]
class ListNotificationChannels extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_notification_channels';

    protected string $description = 'The team\'s shared alert channels (mail, discord, slack, teams, telegram, ntfy, pushover, webhook) by id, name and type, with their last delivery and the rules and topics that send to them. Pass a project to get the channels its rules may use, its own included, and what each sends for that project.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project slug. Omit for the team\'s shared channels.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = $this->context()->token()->team;

        if ((string) $request->get('project', '') === '') {
            $channels = $team->notificationChannels()->whereTeamWide()->orderBy('name')->get();
            $rules = $team->alertRules()->with('channels:id')->get()->mapWithKeys(fn (AlertRule $rule): array => [$rule->id => [$rule, $rule->channels]]);

            return Response::json(['channels' => $this->present($channels, $rules->all())]);
        }

        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        // The project's alert settings are shown to whoever may change the project.
        if (($refused = $this->authorize('update', $project)) !== null) {
            return $refused;
        }

        $channels = NotificationChannel::query()->whereAvailableTo($project)->orderBy('project_id')->orderBy('name')->get();
        $rules = AlertRule::query()->whereEffectiveFor($project)->with('channels:id')->get()->mapWithKeys(fn (AlertRule $rule): array => [$rule->id => [$rule, $rule->channelsFor($project)]]);

        return Response::json(['project' => $project->slug, 'channels' => $this->present($channels, $rules->all())]);
    }

    /**
     * @param  Collection<int, NotificationChannel>  $channels
     * @param  array<int, array{0: AlertRule, 1: Collection<int, NotificationChannel>}>  $rules  each rule with where it sends
     * @return list<array<string, mixed>>
     */
    private function present(Collection $channels, array $rules): array
    {
        return array_values($channels->map(fn (NotificationChannel $channel): array => [
            'id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type->value,
            'project_channel' => $channel->project_id !== null,
            'last_delivery_status' => $channel->last_delivery_status,
            'last_delivery_at' => $channel->last_delivery_at?->toIso8601String(),
            'used_by' => array_values(array_map(
                fn (array $entry): array => ['type' => $entry[0]->type->value, 'topic' => $entry[0]->topic !== '' ? $entry[0]->topic : null, 'enabled' => $entry[0]->enabled, 'project_rule' => $entry[0]->project_id !== null],
                array_filter($rules, fn (array $entry): bool => $entry[1]->contains('id', $channel->id)),
            )),
        ])->all());
    }
}
