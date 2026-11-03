<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Drivers\Concerns\SendsHttp;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Enums\IssueStatus;
use Illuminate\Http\Client\PendingRequest;
use Override;

class DiscordDriver implements ChannelDriver
{
    use SendsHttp;

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
    public function send(NotificationChannel $channel, Alert $alert): void
    {
        if (! $alert instanceof IssueAlert) {
            $this->sendSummary($channel, $alert);

            return;
        }

        $issue = $alert->issue;

        if ($alert->statusChange !== null) {
            $this->post($channel, [
                ...$this->sender($channel),
                'embeds' => [[
                    'title' => mb_substr($alert->headline(), 0, 256),
                    'url' => $alert->url,
                    'color' => $alert->statusChange === IssueStatus::Resolved ? self::COLOR_RESOLVED : self::COLOR_IGNORED,
                    'description' => mb_substr(self::code($issue->title).($alert->note !== null ? "\n\n".$alert->noteBody() : ''), 0, 4000),
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
                    'description' => mb_substr(self::code($issue->title)."\n\n".$alert->noteBody(), 0, 4000),
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
                    ($issue->number !== null ? $issue->reference().' · ' : '').self::code($issue->title),
                    self::escape($issue->message),
                    $alert->triageSummary !== null ? '> '.self::escape($alert->triageSummary) : null,
                ])), 0, 3900)."\n\n[".__('alerts.open_issue').']('.$alert->url.')',
                'fields' => array_map(
                    fn (string $name, string $value): array => ['name' => $name, 'value' => self::escape($value), 'inline' => $name !== __('alerts.discord.location')],
                    array_keys($alert->facts()),
                    $alert->facts(),
                ),
                'timestamp' => $issue->last_seen_at->toIso8601String(),
            ]],
        ]);
    }

    /**
     * An alert that is not about an issue, e.g. a queue backlog: the same embed, from its parts.
     */
    private function sendSummary(NotificationChannel $channel, Alert $alert): void
    {
        ['title' => $title, 'subject' => $subject, 'body' => $body] = $alert->summary();

        $this->post($channel, [
            ...$this->sender($channel),
            'embeds' => [[
                'title' => mb_substr($title, 0, 256),
                'url' => $alert->url(),
                'color' => match ($alert->urgency()) {
                    'high' => self::COLOR_ESCALATION,
                    'low' => self::COLOR_RESOLVED,
                    default => self::COLOR_NEW,
                },
                'description' => mb_substr(implode("\n", [self::code($subject), ...array_map(self::escape(...), $body)]), 0, 3900)."\n\n[".__('alerts.open_issue').']('.$alert->url().')',
                'fields' => array_map(
                    fn (string $name, string $value): array => ['name' => $name, 'value' => self::escape($value), 'inline' => true],
                    array_keys($alert->facts()),
                    $alert->facts(),
                ),
                'timestamp' => now()->toIso8601String(),
            ]],
        ]);
    }

    /**
     * Text from the app (an issue's message, a file name) shown as text: Discord's
     * markdown would otherwise turn [text](url) into a link nobody wrote here.
     */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_~|\[\]<])/', '\\\\$1', $text);
    }

    /**
     * Inline code that a backtick in the text cannot end early.
     */
    public static function code(string $text): string
    {
        return '`'.str_replace('`', 'ˋ', $text).'`';
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
        // An @everyone in an issue's title pings nobody.
        $this->deliver($channel, fn (PendingRequest $http) => $http->post((string) ($channel->config['webhook_url'] ?? ''), ['allowed_mentions' => ['parse' => []], ...$payload]));
    }
}
