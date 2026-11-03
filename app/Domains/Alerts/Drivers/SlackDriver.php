<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Drivers\Concerns\SendsHttp;
use App\Domains\Alerts\Models\NotificationChannel;
use Illuminate\Http\Client\PendingRequest;
use Override;

/**
 * Slack through an incoming webhook (Slack app, Incoming Webhooks): a Block Kit message.
 */
class SlackDriver implements ChannelDriver
{
    use SendsHttp;

    #[Override]
    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'url', 'starts_with:https://hooks.slack.com/'],
        ];
    }

    #[Override]
    public function send(NotificationChannel $channel, IssueAlert $alert): void
    {
        ['title' => $title, 'subject' => $subject, 'body' => $body] = $alert->summary();

        $this->post($channel, [
            'text' => $title.': '.$subject,
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr('*<'.$alert->url.'|'.self::escape($title).'>*'."\n`".self::escape($subject).'`', 0, 3000)]],
                ...($body !== [] ? [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr(self::escape(implode("\n", $body)), 0, 3000)]]] : []),
                ...($alert->facts() !== [] ? [['type' => 'section', 'fields' => array_map(
                    fn (string $label, string $value): array => ['type' => 'mrkdwn', 'text' => '*'.self::escape($label)."*\n".self::escape($value)],
                    array_keys($alert->facts()),
                    $alert->facts(),
                )]] : []),
                ['type' => 'actions', 'elements' => [['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => (string) __('alerts.open_issue')], 'url' => $alert->url]]],
            ],
        ]);
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        $this->post($channel, ['text' => __('alerts.test_message', ['channel' => $channel->name])]);
    }

    /** Slack's mrkdwn treats these three as markup. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(NotificationChannel $channel, array $payload): void
    {
        $this->deliver($channel, fn (PendingRequest $http) => $http->post((string) ($channel->config['webhook_url'] ?? ''), $payload));
    }
}
