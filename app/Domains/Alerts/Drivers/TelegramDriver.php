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
 * Telegram through a bot (made with @BotFather) that is a member of the chat, group or
 * channel it posts to.
 */
class TelegramDriver implements ChannelDriver
{
    use SendsHttp;

    public const string API = 'https://api.telegram.org';

    #[Override]
    public function rules(): array
    {
        return [
            'bot_token' => ['required', 'string', 'regex:/^\d+:[A-Za-z0-9_-]{30,}$/'],
            // A numeric chat id (groups are negative) or a public channel's @name.
            'chat_id' => ['required', 'string', 'regex:/^(-?\d+|@[A-Za-z0-9_]{5,})$/'],
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

        $this->post($channel, mb_substr(implode("\n", array_filter([
            '<b>'.self::escape($title).'</b>',
            '<code>'.self::escape($subject).'</code>',
            $body !== [] ? self::escape(implode("\n", $body)) : null,
            $alert->facts() !== [] ? implode("\n", array_map(fn (string $label, string $value): string => '<b>'.self::escape($label).':</b> '.self::escape($value), array_keys($alert->facts()), $alert->facts())) : null,
            '<a href="'.self::escape($alert->url()).'">'.self::escape((string) __('alerts.open_issue')).'</a>',
        ])), 0, 4000), $alert->urgency() === 'low');
    }

    private function sendCustom(NotificationChannel $channel, CustomAlertMessage $message): void
    {
        $alert = $message->alert;
        $buttons = array_map(fn (array $link): array => ['text' => $link['label'], 'url' => $link['url']], $message->links());

        $this->post($channel, mb_substr(implode("\n", array_filter([
            $message->level()->emoji().' <b>'.self::escape($alert->title).'</b>',
            $alert->message !== null ? self::escape($alert->message) : null,
            ...array_map(fn (array $field): string => '<b>'.self::escape($field['name']).':</b> '.self::escape($field['value']), $message->fields()),
            ...array_map(fn (array $table): string => ($table['title'] !== null ? '<b>'.self::escape($table['title'])."</b>\n" : '').'<pre>'.self::escape(CustomAlertMessage::tableText($table)).'</pre>', $message->tables()),
            '<i>'.self::escape($message->context()).'</i>',
        ])), 0, 4000), $alert->level->urgency() === 'low', $buttons !== [] ? ['inline_keyboard' => array_chunk($buttons, 2)] : null);
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        $this->post($channel, self::escape((string) __('alerts.test_message', ['channel' => $channel->name])));
    }

    /** Telegram's HTML mode needs these escaped. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    private function post(NotificationChannel $channel, string $html, bool $quiet = false, ?array $replyMarkup = null): void
    {
        $token = (string) ($channel->config['bot_token'] ?? '');

        $this->deliver($channel, fn (PendingRequest $http) => $http->post(self::API.'/bot'.$token.'/sendMessage', array_filter([
            'chat_id' => (string) ($channel->config['chat_id'] ?? ''),
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'disable_notification' => $quiet,
            'reply_markup' => $replyMarkup,
        ], fn (mixed $value): bool => $value !== null)));
    }
}
