<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Exceptions\WebhookNeedsProjectException;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Str;

class SaveNotificationChannelAction
{
    /** Config fields that are no secret; the edit form shows them filled in. */
    public const array VISIBLE = ['sender_name', 'chat_id'];


    /**
     * @param  array<string, string|null>  $config  validated against the driver's rules; null or empty keeps the stored value
     * @param  Project|null  $project  for a channel only that project's rules use; a webhook always has one
     */
    public function execute(Team $team, ChannelType $type, string $name, array $config, ?NotificationChannel $channel = null, ?Project $project = null): NotificationChannel
    {
        $project ??= $channel?->project;

        if ($type === ChannelType::Webhook && $project === null) {
            throw WebhookNeedsProjectException::create();
        }

        if ($project !== null && $project->team_id !== $team->id) {
            throw WebhookNeedsProjectException::create();
        }

        $existing = $channel !== null && $channel->type === $type ? $channel->config : [];
        $channel ??= new NotificationChannel(['team_id' => $team->id]);

        // An empty secret keeps what is stored, so it never has to be shown to be kept.
        // An empty setting that the form shows as it is (a sender name) is cleared.
        $cleared = array_keys(array_filter(array_intersect_key($config, array_flip(self::VISIBLE)), fn ($value): bool => $value === null || $value === ''));
        $config = array_diff_key(array_merge($existing, array_filter($config, fn ($value): bool => $value !== null && $value !== '')), array_flip($cleared));

        // A webhook signs with a secret of its own, made once and shown once.
        if ($type === ChannelType::Webhook && ! isset($config['secret'])) {
            $config['secret'] = 'whsec_'.Str::random(40);
            $channel->revealSecret = $config['secret'];
        }

        $channel->fill(['type' => $type, 'name' => $name, 'config' => $config, 'project_id' => $project?->id])->save();

        // A new channel goes straight into the rules for the kinds that page: a shared one
        // into the team's, a project's own into the rules that count for the project, as
        // an add-on where that is the team's (a team rule cannot hold one project's channel).
        if ($channel->wasRecentlyCreated) {
            $types = array_map(fn (AlertRuleType $type): string => $type->value, array_filter(AlertRuleType::cases(), fn (AlertRuleType $type): bool => $type->enabledByDefault()));

            if ($project === null) {
                $team->alertRules()->whereIn('type', $types)->get()->each(fn (AlertRule $rule) => $rule->channels()->syncWithoutDetaching([$channel->id]));
            } else {
                AlertRule::query()->whereEffectiveFor($project)->whereIn('type', $types)->get()->each(fn (AlertRule $rule) => $rule->project_id !== null
                    ? $rule->channels()->syncWithoutDetaching([$channel->id])
                    : $rule->addOnsFor($project)->syncWithoutDetaching([$channel->id]));
            }
        }

        return $channel;
    }
}
