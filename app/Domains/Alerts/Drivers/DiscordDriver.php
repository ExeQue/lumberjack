<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Exceptions\AlertDeliveryFailedException;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Enums\IssueStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Override;
use Throwable;

class DiscordDriver implements ChannelDriver
{
    public const int COLOR_NEW = 0xF2705F;

    public const int COLOR_REGRESSION = 0xF0A93B;

    public const int COLOR_NOTE = 0x5B9BD5;

    public const int COLOR_ESCALATION = 0xE8590C;

    public const int COLOR_RESOLVED = 0x4CB782;

    public const int COLOR_IGNORED = 0x8A939C;

    public const string DEFAULT_SENDER = 'Lumberjack';

    public const string AVATAR = 'images/lumberjack-avatar.png';

    #[Override]
    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'url', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'],
            // Discord refuses a webhook name with these in it.
            'sender_name' => ['nullable', 'string', 'max:80', 'not_regex:/discord|clyde|[@#:]|```/i'],
        ];
    }

    #[Override]
    public function send(NotificationChannel $channel, IssueAlert $alert): void
    {
        $issue = $alert->issue;

        if ($alert->statusChange !== null) {
            $this->post($channel, [
                ...$this->sender($channel),
                'embeds' => [[
                    'title' => mb_substr($alert->headline(), 0, 256),
                    'url' => $alert->url,
                    'color' => $alert->statusChange === IssueStatus::Resolved ? self::COLOR_RESOLVED : self::COLOR_IGNORED,
                    'description' => mb_substr('`'.$issue->title.'`'.($alert->note !== null ? "\n\n".$alert->noteBody() : ''), 0, 4000),
                    'timestamp' => now()->toIso8601String(),
                ]],
            ]);

            return;
        }

        if ($alert->note !== null) {
            $this->post($channel, [
                ...$this->sender($channel),
                'embeds' => [[
                    'title' => mb_substr($alert->headline(), 0, 256),
                    'url' => $alert->url,
                    'color' => self::COLOR_NOTE,
                    'author' => ['name' => mb_substr((string) $alert->noteAuthor(), 0, 256)],
                    'description' => mb_substr('`'.$issue->title."`\n\n".$alert->noteBody(), 0, 4000),
                    'timestamp' => ($alert->note->created_at ?? now())->toIso8601String(),
                ]],
            ]);

            return;
        }

        $this->post($channel, [
            ...$this->sender($channel),
            'embeds' => [[
                'title' => mb_substr($alert->headline(), 0, 256),
                'url' => $alert->url,
                'color' => match ($alert->type) {
                    AlertRuleType::Regression => self::COLOR_REGRESSION,
                    AlertRuleType::Escalation => self::COLOR_ESCALATION,
                    default => self::COLOR_NEW,
                },
                'description' => mb_substr(implode("\n", array_filter([
                    $alert->burstLine() !== null ? '**'.$alert->burstLine().'**' : null,
                    ($issue->number !== null ? $issue->reference().' · ' : '').'`'.$issue->title.'`',
                    $issue->message,
                    $alert->triageSummary !== null ? '> '.$alert->triageSummary : null,
                ])), 0, 3900)."\n\n[".__('alerts.open_issue').']('.$alert->url.')',
                'fields' => array_map(
                    fn (string $name, string $value): array => ['name' => $name, 'value' => $value, 'inline' => $name !== __('alerts.discord.location')],
                    array_keys($alert->facts()),
                    $alert->facts(),
                ),
                'timestamp' => $issue->last_seen_at->toIso8601String(),
            ]],
        ]);
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        $this->post($channel, [...$this->sender($channel), 'content' => __('alerts.test_message', ['channel' => $channel->name])]);
    }

    /**
     * Who the message is from: the channel's sender name, with Lumberjack's logo.
     * Discord fetches the logo itself, so it shows once APP_URL is public.
     *
     * @return array{username: string, avatar_url: string}
     */
    private function sender(NotificationChannel $channel): array
    {
        $name = $channel->config['sender_name'] ?? null;

        return [
            'username' => is_string($name) && trim($name) !== '' ? trim($name) : self::DEFAULT_SENDER,
            'avatar_url' => asset(self::AVATAR),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(NotificationChannel $channel, array $payload): void
    {
        $response = $this->http()->post((string) ($channel->config['webhook_url'] ?? ''), $payload);

        if ($response->failed()) {
            throw AlertDeliveryFailedException::status($channel->name, $response->status());
        }
    }

    private function http(): PendingRequest
    {
        return Http::timeout(10)->retry(
            times: 3,
            sleepMilliseconds: 200,
            when: fn (Throwable $e): bool => $e instanceof ConnectionException,
            throw: false,
        );
    }
}
