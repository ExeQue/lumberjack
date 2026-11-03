<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Drivers\Concerns\SendsHttp;
use App\Domains\Alerts\Exceptions\AlertDeliveryFailedException;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Shared\Rules\PublicUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use Override;

/**
 * ntfy (ntfy.sh or a server of your own): a push to everyone subscribed to a topic.
 * The topic is the URL's last part; an access token is needed for a protected topic.
 */
class NtfyDriver implements ChannelDriver
{
    use SendsHttp;

    #[Override]
    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'regex:#^https?://[^/]+/.*[A-Za-z0-9_-]+$#', new PublicUrl()],
            'access_token' => ['nullable', 'string', 'max:200'],
        ];
    }

    #[Override]
    public function send(NotificationChannel $channel, Alert $alert): void
    {
        ['title' => $title, 'subject' => $subject, 'body' => $body] = $alert->summary();

        $this->post($channel, [
            'title' => mb_substr($title, 0, 250),
            'message' => mb_substr(implode("\n", [$subject, ...$body]), 0, 4000),
            'click' => $alert->url(),
            // ntfy's 1 to 5; 4 sounds through do-not-disturb on most phones.
            'priority' => match ($alert->urgency()) {
                'high' => 4,
                'low' => 2,
                default => 3,
            },
            'tags' => [$alert->urgency() === 'high' ? 'rotating_light' : 'evergreen_tree'],
        ]);
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        $this->post($channel, ['title' => 'Lumberjack', 'message' => __('alerts.test_message', ['channel' => $channel->name]), 'tags' => ['evergreen_tree']]);
    }

    /**
     * ntfy's JSON publishing goes to the server's root with the topic in the body, which
     * keeps a title in any language intact (headers would have to be ASCII).
     *
     * @param  array<string, mixed>  $message
     */
    private function post(NotificationChannel $channel, array $message): void
    {
        $url = rtrim((string) ($channel->config['url'] ?? ''), '/');

        if (! PublicUrl::allows($url)) {
            throw AlertDeliveryFailedException::notPublic($channel->name);
        }

        $topic = Str::afterLast($url, '/');
        $server = Str::beforeLast($url, '/');
        $token = $channel->config['access_token'] ?? null;

        $this->deliver($channel, fn (PendingRequest $http) => $http->withoutRedirecting()
            ->when(is_string($token) && $token !== '')->withToken((string) $token)
            ->post($server, ['topic' => $topic, ...$message]));
    }
}
