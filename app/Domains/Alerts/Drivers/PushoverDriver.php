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
 * Pushover: a push to a user's (or group's) devices, sent with an application token made
 * at pushover.net/apps.
 */
class PushoverDriver implements ChannelDriver
{
    use SendsHttp;

    public const string API = 'https://api.pushover.net/1/messages.json';

    #[Override]
    public function rules(): array
    {
        return [
            'user_key' => ['required', 'string', 'regex:/^[A-Za-z0-9]{30}$/'],
            'app_token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{30}$/'],
        ];
    }

    #[Override]
    public function send(NotificationChannel $channel, Alert $alert): void
    {
        if ($alert instanceof CustomAlertMessage) {
            // Pushover takes one link; the others go in the text.
            $this->post($channel, array_filter([
                'title' => mb_substr($alert->headline(), 0, 250),
                'message' => mb_substr(implode("\n", [...$alert->plainLines(), ...array_map(fn (array $link): string => $link['label'].': '.$link['url'], array_slice($alert->links(), 1))]), 0, 1024),
                'url' => $alert->links()[0]['url'] ?? $alert->titleUrl(),
                'url_title' => $alert->links()[0]['label'] ?? null,
                'priority' => ['high' => 1, 'normal' => 0, 'low' => -1][$alert->urgency()],
            ], fn (mixed $value): bool => $value !== null));

            return;
        }

        ['title' => $title, 'subject' => $subject, 'body' => $body] = $alert->summary();

        $this->post($channel, [
            'title' => mb_substr($title, 0, 250),
            'message' => mb_substr(implode("\n", [$subject, ...$body]), 0, 1024),
            'url' => $alert->url(),
            'url_title' => __('alerts.open_issue'),
            // Pushover's -1 (quiet) to 1 (high, bypasses quiet hours).
            'priority' => match ($alert->urgency()) {
                'high' => 1,
                'low' => -1,
                default => 0,
            },
        ]);
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        $this->post($channel, ['title' => 'Lumberjack', 'message' => __('alerts.test_message', ['channel' => $channel->name])]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function post(NotificationChannel $channel, array $message): void
    {
        $this->deliver($channel, fn (PendingRequest $http) => $http->asForm()->post(self::API, [
            'token' => (string) ($channel->config['app_token'] ?? ''),
            'user' => (string) ($channel->config['user_key'] ?? ''),
            ...$message,
        ]));
    }
}
