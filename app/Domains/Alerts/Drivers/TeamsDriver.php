<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Drivers\Concerns\SendsHttp;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Shared\Rules\PublicUrl;
use Illuminate\Http\Client\PendingRequest;
use Override;

/**
 * Microsoft Teams through a Workflows webhook ("Post to a channel when a webhook request
 * is received"), which replaced the Office 365 connectors: an Adaptive Card.
 */
class TeamsDriver implements ChannelDriver
{
    use SendsHttp;

    #[Override]
    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'url', 'starts_with:https://', new PublicUrl()],
        ];
    }

    #[Override]
    public function send(NotificationChannel $channel, Alert $alert): void
    {
        ['title' => $title, 'subject' => $subject, 'body' => $body] = $alert->summary();

        $this->post($channel, $this->card([
            ['type' => 'TextBlock', 'text' => self::escape($title), 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true, 'color' => $alert->urgency() === 'high' ? 'Attention' : 'Default'],
            ['type' => 'TextBlock', 'text' => self::escape($subject), 'fontType' => 'Monospace', 'wrap' => true],
            ...array_map(fn (string $line): array => ['type' => 'TextBlock', 'text' => self::escape($line), 'wrap' => true, 'isSubtle' => true], $body),
            ...($alert->facts() !== [] ? [['type' => 'FactSet', 'facts' => array_map(
                fn (string $title, string $value): array => ['title' => $title, 'value' => self::escape($value)],
                array_keys($alert->facts()),
                $alert->facts(),
            )]] : []),
        ], $alert->url()));
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        $this->post($channel, $this->card([
            ['type' => 'TextBlock', 'text' => __('alerts.test_message', ['channel' => $channel->name]), 'wrap' => true],
        ]));
    }

    /**
     * Adaptive Cards render a markdown subset, links included; a zero-width space keeps
     * [text](url) in an issue's title or message from becoming one.
     */
    public static function escape(string $text): string
    {
        return str_replace(['](', '**'], [']'."\u{200B}".'(', '*'."\u{200B}".'*'], $text);
    }

    /**
     * @param  list<array<string, mixed>>  $body
     * @return array<string, mixed>
     */
    private function card(array $body, ?string $url = null): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => array_filter([
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => $body,
                    'actions' => $url !== null ? [['type' => 'Action.OpenUrl', 'title' => __('alerts.open_issue'), 'url' => $url]] : null,
                ]),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(NotificationChannel $channel, array $payload): void
    {
        $url = (string) ($channel->config['webhook_url'] ?? '');

        // Checked again on every send, and pinned: the host may resolve somewhere else by now.
        $this->deliver($channel, fn (PendingRequest $http) => $http->post($url, $payload), pinTo: $url);
    }
}
