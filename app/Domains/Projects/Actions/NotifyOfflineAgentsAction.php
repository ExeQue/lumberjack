<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Notifications\Actions\NotifyProjectMembersAction;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\AgentOfflineNotification;
use App\Domains\Projects\Models\ProjectAgent;
use Illuminate\Database\Eloquent\Collection;

/**
 * Runs every minute: an agent whose heartbeat stopped for OFFLINE_AFTER_MINUTES is
 * offline, and the members of its project who want it hear of that once, one
 * notification per project however many agents went. Its next heartbeat clears the
 * mark (RecordAgentHeartbeatAction), so going offline again is told again.
 */
class NotifyOfflineAgentsAction
{
    /**
     * Agents gone longer than this when first noticed (Lumberjack itself was down, or
     * this just shipped) are marked without telling anyone: that is old news.
     */
    public const int NEWS_FOR_MINUTES = 15;

    public function __construct(
        private readonly NotifyProjectMembersAction $notifyProjectMembersAction,
    ) {
    }

    /**
     * @return int projects whose members were told
     */
    public function execute(): int
    {
        $offline = ProjectAgent::query()
            ->with('project.team')
            ->whereNull('offline_notified_at')
            ->where('last_seen_at', '<', now()->subMinutes(ProjectAgent::OFFLINE_AFTER_MINUTES))
            ->get();

        if ($offline->isEmpty()) {
            return 0;
        }

        ProjectAgent::query()->whereKey($offline->modelKeys())->update(['offline_notified_at' => now()]);
        $told = 0;

        /** @var Collection<int, ProjectAgent> $agents */
        foreach ($offline->groupBy('project_id') as $agents) {
            $news = $agents->filter(fn (ProjectAgent $a): bool => $a->last_seen_at->greaterThanOrEqualTo(now()->subMinutes(ProjectAgent::OFFLINE_AFTER_MINUTES + self::NEWS_FOR_MINUTES)));
            $project = $agents->first()?->project;

            if ($news->isEmpty() || $project === null) {
                continue;
            }

            $this->notifyProjectMembersAction->execute($project, new AgentOfflineNotification(PushMessage::about(
                $project,
                trans_choice('notifications.push.agent_offline', $news->count(), ['project' => $project->name]),
                $news->map(fn (ProjectAgent $a): string => (string) ($a->name !== ''
                    ? __('ui/projects.agent_on_host', ['agent' => $a->name, 'host' => $a->host])
                    : __('ui/projects.unnamed_agent_on_host', ['host' => $a->host])))->implode(', '),
                route('projects.index'),
                'agents-'.$project->uuid,
            )));
            $told++;
        }

        return $told;
    }
}
