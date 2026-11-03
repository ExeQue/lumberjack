<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Presenters;

use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\NotificationChannel;
use Illuminate\Support\Str;

class NotificationChannelPresenter
{
    /**
     * $reveal shows where a channel delivers (a mail address, a webhook or ntfy URL whose
     * path is often the only secret); members who do not administer only see the host.
     *
     * @return array<string, mixed>
     */
    public function present(NotificationChannel $channel, bool $reveal = true): array
    {
        return [
            'id' => $channel->id,
            'type' => $channel->type->value,
            'name' => $channel->name,
            'project' => $channel->project_id !== null,
            'target' => $reveal ? $this->maskedTarget($channel) : $this->hiddenTarget($channel),
            // Config that is not a secret, shown as it is when editing.
            'settings' => $reveal ? array_intersect_key($channel->config ?? [], array_flip(SaveNotificationChannelAction::VISIBLE)) : [],
            'last_delivery_status' => $channel->last_delivery_status,
            'last_delivery_at' => $channel->last_delivery_at?->toIso8601String(),
        ];
    }

    private function hiddenTarget(NotificationChannel $channel): string
    {
        return match ($channel->type) {
            ChannelType::Mail => '••••@'.Str::after((string) ($channel->config['address'] ?? ''), '@'),
            ChannelType::Webhook, ChannelType::Ntfy => (string) (parse_url((string) ($channel->config['url'] ?? ''), PHP_URL_HOST) ?? '').'/••••',
            ChannelType::Discord => 'discord.com/••••',
            ChannelType::Telegram => 'chat ••••',
            default => $this->maskedTarget($channel),
        };
    }

    private function maskedTarget(NotificationChannel $channel): string
    {
        return match ($channel->type) {
            ChannelType::Mail => (string) ($channel->config['address'] ?? ''),
            ChannelType::Discord => preg_replace('#/webhooks/(\d+)/.*$#', '/webhooks/$1/••••', (string) ($channel->config['webhook_url'] ?? '')) ?? '',
            ChannelType::Webhook, ChannelType::Ntfy => (string) ($channel->config['url'] ?? ''),
            // The rest are secrets in a URL or a token: only where they go.
            ChannelType::Slack => 'hooks.slack.com/••••',
            ChannelType::Teams => (string) (parse_url((string) ($channel->config['webhook_url'] ?? ''), PHP_URL_HOST) ?? '').'/••••',
            ChannelType::Telegram => 'chat '.($channel->config['chat_id'] ?? '?'),
            ChannelType::Pushover => 'user ••••'.substr((string) ($channel->config['user_key'] ?? ''), -4),
        };
    }
}
