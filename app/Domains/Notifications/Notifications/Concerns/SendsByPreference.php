<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications\Concerns;

use App\Domains\Alerts\Jobs\SendIssueAlerts;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Support\VapidKeys;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\SendsMailCards;
use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A personal notification of one kind (KIND, a key of NotificationPreferences), sent by
 * the channels the user chose for it: mail, in app (kept in the notification center and
 * shown as a toast in an open Lumberjack) and browser push (every browser they
 * subscribed, when the server has VAPID keys). All of them carry the same payload().
 */
trait SendsByPreference
{
    use SendsMailCards;

    public function __construct(
        public readonly PushMessage $message,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $preferences = $notifiable->notification_preferences;
        $channels = [];

        if ($this->mailsHere() && $preferences->mail(self::KIND)) {
            $channels[] = 'mail';
        }

        if ($preferences->toast(self::KIND)) {
            $channels[] = 'database';

            if ($this->toastsHere()) {
                $channels[] = 'broadcast';
            }
        }

        if ($preferences->push(self::KIND) && self::pushConfigured() && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Whether this kind's mail goes out from here. A kind whose mail already had a path
     * of its own (grouped, or one per event for several reasons) keeps it.
     */
    public function mailsHere(): bool
    {
        return true;
    }

    /**
     * Whether this kind's toast comes through here. A kind that already had a toast of
     * its own keeps it; it is still kept in the notification center.
     */
    public function toastsHere(): bool
    {
        return true;
    }

    /**
     * What every channel shows, so they never differ.
     *
     * @return array{kind: string, title: string, body: string, url: string, tag: string, project: string|null, project_uuid: string|null, team_uuid: string|null}
     */
    public function payload(): array
    {
        return [
            'kind' => self::KIND,
            'title' => mb_substr($this->message->title, 0, 200),
            'body' => mb_substr($this->message->body, 0, 500),
            'url' => $this->message->url,
            'tag' => $this->message->tag,
            'project' => $this->message->project,
            'project_uuid' => $this->message->projectUuid,
            'team_uuid' => $this->message->teamUuid,
        ];
    }

    /**
     * @return array{kind: string, title: string, body: string, url: string, tag: string, project: string|null, project_uuid: string|null, team_uuid: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function databaseType(object $notifiable): string
    {
        return self::KIND;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    public function broadcastType(): string
    {
        return 'lumberjack.notification';
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $payload = $this->payload();

        return (new WebPushMessage())
            ->title($payload['title'])
            ->body($payload['body'])
            ->icon('/favicon.svg')
            ->tag($payload['tag'])
            ->data($payload)
            ->options(['TTL' => 86_400, 'topic' => substr(hash('sha256', $payload['tag']), 0, 32)]);
    }

    /**
     * A short mail: the title, the line under it and a button to the page.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $payload = $this->payload();
        $kind = (string) __('ui/settings.notifications.'.self::KIND.'.label');

        return $this->cardMail(new MailCard(
            subject: $payload['project'] !== null ? MailCard::subject($payload['project'], $payload['title']) : $payload['title'],
            preheader: $payload['body'],
            title: $payload['title'],
            kind: $kind,
            pill: $payload['project'],
            lead: $payload['body'],
            button: ['label' => (string) __('notifications.mail.open'), 'url' => $payload['url']],
            reasons: [MailReason::chosen($kind)],
        ), true);
    }

    /**
     * Without both VAPID keys there is no push at all; they are made on first need.
     */
    public static function pushConfigured(): bool
    {
        app(VapidKeys::class)->ensure();

        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }
}
