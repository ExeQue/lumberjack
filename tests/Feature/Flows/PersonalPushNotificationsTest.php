<?php

declare(strict_types=1);

use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Alerts\Actions\CheckQueueBacklogsAction;
use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\QueueAlertThreshold;
use App\Domains\Health\Actions\RecordHealthResultAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Actions\AddIssueNoteAction;
use App\Domains\Issues\Actions\AssignIssueAction;
use App\Domains\Issues\Actions\WatchIssueAction;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Notifications\Notifications\AgentOfflineNotification;
use App\Domains\Notifications\Notifications\AssignmentNotification;
use App\Domains\Notifications\Notifications\HealthCheckNotification;
use App\Domains\Notifications\Notifications\MentionedNotification;
use App\Domains\Notifications\Notifications\NewIssueNotification;
use App\Domains\Notifications\Notifications\QueueBacklogNotification;
use App\Domains\Notifications\Notifications\ReleaseNotification;
use App\Domains\Notifications\Notifications\ScheduledTaskNotification;
use App\Domains\Notifications\Notifications\WatchingNotification;
use App\Domains\Projects\Actions\NotifyOfflineAgentsAction;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Releases\Actions\RecordReleaseAction;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Database\Factories\PushSubscriptionFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Notifications\ChecksPushNotifications;

uses(ChecksPushNotifications::class);

beforeEach(function () {
    Http::fake(['*' => Http::response(null, 204)]);
    Notification::fake();
    $this->configurePush();
    $this->project = Project::factory()->create(['name' => 'Shop']);
    // Push is off by default for most kinds: Alice turned it on for everything.
    $this->alice = $this->subscribedMember($this->project, array_fill_keys(NotificationPreferences::KINDS, ['push' => true]));
    $this->outsider = User::factory()->inTeam(Team::factory()->create())->create();
    PushSubscriptionFactory::new()->forUser($this->outsider)->create();
    $this->unsubscribed = User::factory()->inTeam($this->project->team)->create();
});

describe('new issues and scheduled tasks', function () {
    beforeEach(function () {
        $this->bob = $this->subscribedMember($this->project, ['scheduled_tasks' => ['push' => true]]);
        $this->issue = Issue::factory()->for($this->project)->create(['title' => 'RuntimeException']);
        Occurrence::factory()->for($this->issue)->create();
    });

    it('pushes a new issue to the members who want it, and to nobody outside the team', function () {
        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');

        $this->assertPushed($this->alice, NewIssueNotification::class, fn (NewIssueNotification $n): bool => $n->message->title === 'New error: RuntimeException' && $n->message->tag === 'issue-'.$this->issue->uuid);
        $this->assertNotPushed($this->bob, NewIssueNotification::class);
        $this->assertNotPushed($this->unsubscribed, NewIssueNotification::class);
        Notification::assertNotSentTo($this->outsider, NewIssueNotification::class);
    });

    it('follows the team rule: nothing while it is off, an escalation once per throttle window', function () {
        AlertRule::query()->whereEffectiveFor($this->project)->whereType(AlertRuleType::NewIssue)->update(['enabled' => false]);
        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');
        Notification::assertNothingSent();

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::Escalation, 'production', 50);
        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::Escalation, 'production', 80);

        Notification::assertSentToTimes($this->alice, NewIssueNotification::class, 1);
    });

    it('tells of a failing scheduled task as one, not as a new issue', function () {
        $task = Issue::factory()->for($this->project)->create(['title' => 'Scheduled task missed: reports:send']);
        Occurrence::factory()->for($task)->create(['context' => ['schedule' => ['task' => 'reports:send']]]);

        app(SendIssueAlertsAction::class)->execute($task, AlertRuleType::NewIssue, 'production');

        $this->assertPushed($this->alice, ScheduledTaskNotification::class);
        $this->assertPushed($this->bob, ScheduledTaskNotification::class);
        Notification::assertNotSentTo($this->alice, NewIssueNotification::class);
    });

    it('stays quiet about browser errors', function () {
        app(SendIssueAlertsAction::class)->execute(Issue::factory()->for($this->project)->fromBrowser()->create(), AlertRuleType::NewIssue, 'production');

        Notification::assertNothingSent();
    });
});

describe('health checks', function () {
    beforeEach(function () {
        $this->report = function (HealthCheck $check, string $status): void {
            $this->travel(1)->minutes();
            app(RecordHealthResultAction::class)->execute($check->refresh(), new HealthResultData(HealthResultStatus::from($status), 'connection refused', host: 'web-1', at: now()->toImmutable()));
        };
    });

    it('pushes going down and recovering, even with no team channel', function () {
        $check = HealthCheck::factory()->for($this->project)->create(['degrade_after' => 1, 'down_after' => 1, 'recover_after' => 1]);

        ($this->report)($check, 'failed');
        ($this->report)($check, 'failed');
        ($this->report)($check, 'ok');

        Notification::assertSentToTimes($this->alice, HealthCheckNotification::class, 2);
        Notification::assertNotSentTo($this->outsider, HealthCheckNotification::class);
    });

    it('keeps a muted check quiet', function () {
        $check = HealthCheck::factory()->for($this->project)->create(['degrade_after' => 1, 'down_after' => 1, 'muted' => true]);

        ($this->report)($check, 'failed');

        Notification::assertNothingSent();
    });
});

describe('queue backlogs', function () {
    beforeEach(function () {
        AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::QueueBacklog, 'settings' => ['size' => 100, 'age_minutes' => null, 'sustain_minutes' => 2]]);
        $this->minutes = function (array $sizes): void {
            foreach ($sizes as $size) {
                $this->travel(1)->minutes();
                QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'default', 'size' => $size, 'oldest_age_s' => 60, 'sampled_at' => now()]);
                app(CheckQueueBacklogsAction::class)->execute();
            }
        };
    });

    it('pushes a backlog when it starts and when it clears', function () {
        ($this->minutes)([150, 150, 150, 150, 10]);

        Notification::assertSentToTimes($this->alice, QueueBacklogNotification::class, 2);
    });

    it('keeps a muted queue quiet', function () {
        QueueAlertThreshold::query()->create(['project_id' => $this->project->id, 'connection' => 'redis', 'queue' => 'default', 'muted' => true]);

        ($this->minutes)([150, 150, 150, 150]);

        Notification::assertNothingSent();
    });
});

describe('agents', function () {
    it('pushes once when an agent goes offline, and again only after it came back', function () {
        $agent = ProjectAgent::factory()->for($this->project)->create(['last_seen_at' => now()->subMinutes(7)]);
        ProjectAgent::factory()->for($this->project)->create(['last_seen_at' => now()->subHours(3)]);

        app(NotifyOfflineAgentsAction::class)->execute();
        app(NotifyOfflineAgentsAction::class)->execute();

        Notification::assertSentToTimes($this->alice, AgentOfflineNotification::class, 1);
        $this->assertPushed($this->alice, AgentOfflineNotification::class, fn (AgentOfflineNotification $n): bool => $n->message->title === 'An agent went offline in Shop');

        $agent->update(['last_seen_at' => now(), 'offline_notified_at' => null]);
        $this->travel(10)->minutes();
        app(NotifyOfflineAgentsAction::class)->execute();

        Notification::assertSentToTimes($this->alice, AgentOfflineNotification::class, 2);
    });
});

describe('releases', function () {
    it('writes down the first release quietly and pushes each new one once', function () {
        app(RecordReleaseAction::class)->execute($this->project, 'v1');
        Notification::assertNothingSent();

        app(RecordReleaseAction::class)->execute($this->project, 'v2');
        app(RecordReleaseAction::class)->execute($this->project, 'v2');

        Notification::assertSentToTimes($this->alice, ReleaseNotification::class, 1);
    });

    it('sends nothing to someone who turned releases off', function () {
        $this->alice->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['releases' => ['toast' => false, 'push' => false]])])->save();
        $this->unsubscribed->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['releases' => ['toast' => false]])])->save();

        app(RecordReleaseAction::class)->execute($this->project, 'v1');
        app(RecordReleaseAction::class)->execute($this->project, 'v2');

        Notification::assertNothingSent();
    });
});

describe('about you', function () {
    beforeEach(function () {
        $this->issue = Issue::factory()->for($this->project)->create();
        $this->bob = $this->subscribedMember($this->project);
    });

    it('pushes mentions and assignments next to their mail and toast', function () {
        app(AddIssueNoteAction::class)->execute($this->issue, "<@{$this->alice->id}> have a look", $this->bob);
        app(AssignIssueAction::class)->execute($this->issue, $this->alice, $this->bob);

        $this->assertPushed($this->alice, MentionedNotification::class);
        $this->assertPushed($this->alice, AssignmentNotification::class);
        Notification::assertNotSentTo($this->bob, MentionedNotification::class);
    });

    it('pushes updates on a watched issue, unless push is off for watching', function () {
        app(WatchIssueAction::class)->execute($this->issue, $this->alice);
        app(WatchIssueAction::class)->execute($this->issue, $this->unsubscribed);

        app(AddIssueNoteAction::class)->execute($this->issue, 'Deploying a fix.', $this->bob);
        $this->assertPushed($this->alice, WatchingNotification::class);

        $this->alice->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['watching' => ['toast' => false, 'push' => false]])])->save();
        app(AddIssueNoteAction::class)->execute($this->issue, 'Fixed.', $this->bob);

        Notification::assertSentToTimes($this->alice, WatchingNotification::class, 1);
    });
});
