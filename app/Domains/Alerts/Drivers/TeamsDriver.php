<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Data\CustomAlertMessage;
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
        if ($alert instanceof CustomAlertMessage) {
            $this->sendCustom($channel, $alert);

            return;
        }

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

    private function sendCustom(NotificationChannel $channel, CustomAlertMessage $message): void
    {
        $alert = $message->alert;
        $text = fn (string $value, array $style = []): array => ['type' => 'TextBlock', 'text' => self::escape($value), 'wrap' => true, ...$style];
        $row = fn (array $cells): array => ['type' => 'TableRow', 'cells' => array_map(fn (string $cell): array => ['type' => 'TableCell', 'items' => [$text($cell)]], $cells)];

        $this->post($channel, $this->card([
            $text($alert->title, ['weight' => 'Bolder', 'size' => 'Medium', 'color' => $message->level()->teamsColor()]),
            ...($alert->message !== null ? [$text($alert->message, ['isSubtle' => true])] : []),
            ...($message->fields() !== [] ? [['type' => 'FactSet', 'facts' => array_map(fn (array $field): array => ['title' => $field['name'], 'value' => self::escape($field['value'])], $message->fields())]] : []),
            ...collect($message->tables())->flatMap(fn (array $table): array => [
                ...($table['title'] !== null ? [$text($table['title'], ['weight' => 'Bolder'])] : []),
                ['type' => 'Table', 'firstRowAsHeader' => true, 'columns' => array_fill(0, count($table['headers']), ['width' => 1]), 'rows' => [$row($table['headers']), ...array_map(fn (array $cells): array => $row(array_pad($cells, count($table['headers']), '')), $table['rows'])]],
            ])->all(),
            $text($message->context(), ['isSubtle' => true, 'size' => 'Small']),
        ], actions: array_map(fn (array $link): array => array_filter([
            'type' => 'Action.OpenUrl',
            'title' => $link['label'],
            'url' => $link['url'],
            'style' => ['primary' => 'positive', 'danger' => 'destructive'][$link['style']] ?? null,
        ]), $message->links()), version: '1.5'));
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
     * @param  list<array<string, mixed>>|null  $actions  in place of the one link to $url
     * @return array<string, mixed>
     */
    private function card(array $body, ?string $url = null, ?array $actions = null, string $version = '1.4'): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => array_filter([
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => $version,
                    'body' => $body,
                    'actions' => $actions ?? ($url !== null ? [['type' => 'Action.OpenUrl', 'title' => __('alerts.open_issue'), 'url' => $url]] : null),
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
