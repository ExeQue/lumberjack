<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Data\CustomAlertMessage;
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
    public function send(NotificationChannel $channel, Alert $alert): void
    {
        if ($alert instanceof CustomAlertMessage) {
            $this->sendCustom($channel, $alert);

            return;
        }

        ['title' => $title, 'subject' => $subject, 'body' => $body] = $alert->summary();

        $this->post($channel, [
            'text' => $title.': '.$subject,
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr('*<'.$alert->url().'|'.self::escape($title).'>*'."\n`".str_replace('`', 'ˋ', self::escape($subject)).'`', 0, 3000)]],
                ...($body !== [] ? [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr(self::escape(implode("\n", $body)), 0, 3000)]]] : []),
                ...($alert->facts() !== [] ? [['type' => 'section', 'fields' => array_map(
                    fn (string $label, string $value): array => ['type' => 'mrkdwn', 'text' => '*'.self::escape($label)."*\n".self::escape($value)],
                    array_keys($alert->facts()),
                    $alert->facts(),
                )]] : []),
                ['type' => 'actions', 'elements' => [['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => (string) __('alerts.open_issue')], 'url' => $alert->url()]]],
            ],
        ]);
    }

    private function sendCustom(NotificationChannel $channel, CustomAlertMessage $message): void
    {
        $alert = $message->alert;
        $title = self::escape($alert->title);
        $section = fn (string $text): array => ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr($text, 0, 3000)]];

        $this->post($channel, [
            'text' => $alert->title,
            'attachments' => [[
                'color' => $message->level()->hexColor(),
                'blocks' => [
                    $section($message->titleUrl() !== null ? '*<'.$message->titleUrl().'|'.$title.'>*' : '*'.$title.'*'),
                    ...($alert->message !== null ? [$section(self::escape($alert->message))] : []),
                    // Slack takes ten fields per section.
                    ...array_map(fn (array $fields): array => ['type' => 'section', 'fields' => array_map(fn (array $field): array => ['type' => 'mrkdwn', 'text' => '*'.self::escape($field['name'])."*\n".self::escape($field['value'])], $fields)], array_chunk($message->fields(), 10)),
                    ...array_map(fn (array $table): array => $section(($table['title'] !== null ? '*'.self::escape($table['title'])."*\n" : '')."```\n".self::escape(CustomAlertMessage::tableText($table))."\n```"), $message->tables()),
                    ...($message->links() !== [] ? [['type' => 'actions', 'elements' => array_map(fn (array $link): array => array_filter([
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => $link['label']],
                        'url' => $link['url'],
                        'style' => $link['style'],
                    ]), $message->links())]] : []),
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => self::escape($message->context())]]],
                ],
            ]],
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
