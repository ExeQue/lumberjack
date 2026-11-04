<?php

declare(strict_types=1);

use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\AgentOfflineNotification;
use App\Domains\Notifications\Notifications\AssignmentNotification;
use App\Domains\Notifications\Notifications\Concerns\SendsByPreference;
use App\Domains\Notifications\Notifications\HealthCheckNotification;
use App\Domains\Notifications\Notifications\MentionedNotification;
use App\Domains\Notifications\Notifications\NewIssueNotification;
use App\Domains\Notifications\Notifications\QueueBacklogNotification;
use App\Domains\Notifications\Notifications\ReleaseNotification;
use App\Domains\Notifications\Notifications\ScheduledTaskNotification;
use App\Domains\Notifications\Notifications\WatchingNotification;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Database\Factories\PushSubscriptionFactory;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\Notifications\ChecksPushNotifications;

covers(SendsByPreference::class);

uses(ChecksPushNotifications::class);

dataset('project kinds', [
    'new issues' => [NewIssueNotification::class],
    'health checks' => [HealthCheckNotification::class],
    'queue backlogs' => [QueueBacklogNotification::class],
    'scheduled tasks' => [ScheduledTaskNotification::class],
    'agents' => [AgentOfflineNotification::class],
    'releases' => [ReleaseNotification::class],
]);

dataset('personal kinds', [
    'watching' => [WatchingNotification::class],
    'mentions' => [MentionedNotification::class],
    'assignments' => [AssignmentNotification::class],
]);

dataset('switches', function () {
    foreach ([true, false] as $mail) {
        foreach ([true, false] as $toast) {
            foreach ([true, false] as $push) {
                yield ($mail ? 'mail' : 'no mail').', '.($toast ? 'in app' : 'not in app').', '.($push ? 'push' : 'no push') => [$mail, $toast, $push];
            }
        }
    }
});

beforeEach(function () {
    $this->configurePush();
    $this->user = User::factory()->create();
    PushSubscriptionFactory::new()->forUser($this->user)->create();
    $this->prefer = fn (string $kind, bool $mail, bool $toast, bool $push) => $this->user->forceFill(['notification_preferences' => NotificationPreferences::fromArray([$kind => ['mail' => $mail, 'toast' => $toast, 'push' => $push]])]);
    $project = Project::factory()->create(['name' => 'Shop']);
    $this->message = PushMessage::about($project, 'Shop is down', 'web-1 - connection refused', 'https://lumberjack.test/health', 'health-1');
});

describe('the channels a notification takes', function () {
    it('follows the three switches of its kind', function (string $class, bool $mail, bool $toast, bool $push) {
        ($this->prefer)($class::KIND, $mail, $toast, $push);

        expect((new $class($this->message))->via($this->user))->toBe(array_values(array_filter([
            $mail ? 'mail' : null,
            $toast ? 'database' : null,
            $toast ? 'broadcast' : null,
            $push ? WebPushChannel::class : null,
        ])));
    })->with('project kinds')->with('switches');

    it('leaves the mail and the toast of a personal kind to the paths it already had, and keeps it in the center', function (string $class, bool $mail, bool $toast, bool $push) {
        ($this->prefer)($class::KIND, $mail, $toast, $push);

        expect((new $class($this->message))->via($this->user))->toBe(array_values(array_filter([
            $toast ? 'database' : null,
            $push ? WebPushChannel::class : null,
        ])));
    })->with('personal kinds')->with('switches');

    it('pushes nothing to someone without a subscribed browser', function () {
        $user = User::factory()->create();
        $user->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['releases' => ['push' => true]])]);

        expect((new ReleaseNotification($this->message))->via($user))->toBe(['database', 'broadcast']);
    });
});

describe('what a notification says', function () {
    it('says the same by mail, in app, in the center and by push', function (string $class) {
        $notification = new $class($this->message);
        $push = $notification->toWebPush($this->user)->toArray();
        $mail = $notification->toMail($this->user);

        expect($notification->toBroadcast($this->user)->data)->toBe($push['data'])
            ->and($notification->toArray($this->user))->toBe($push['data'])
            ->and($notification->databaseType($this->user))->toBe($class::KIND)
            ->and($push['data'])->toMatchArray(['kind' => $class::KIND, 'title' => 'Shop is down', 'body' => 'web-1 - connection refused', 'url' => 'https://lumberjack.test/health', 'tag' => 'health-1', 'project' => 'Shop'])
            ->and([$push['title'], $push['body'], $push['tag']])->toBe(['Shop is down', 'web-1 - connection refused', 'health-1'])
            ->and($mail->subject)->toContain('Shop is down')
            ->and((string) $mail->render())->toContain('web-1 - connection refused')->toContain('https://lumberjack.test/health');
    })->with('project kinds');

    it('broadcasts on the user\'s own channel', function () {
        expect($this->user->receivesBroadcastNotificationsOn())->toBe('user.'.$this->user->uuid);
    });
});

test('every kind of the preferences has a notification class', function () {
    $classes = [NewIssueNotification::class, HealthCheckNotification::class, QueueBacklogNotification::class, ScheduledTaskNotification::class, AgentOfflineNotification::class, ReleaseNotification::class, WatchingNotification::class, MentionedNotification::class, AssignmentNotification::class];

    expect(collect($classes)->map(fn (string $c): string => $c::KIND)->sort()->values()->all())->toBe(collect(NotificationPreferences::KINDS)->sort()->values()->all());
});
