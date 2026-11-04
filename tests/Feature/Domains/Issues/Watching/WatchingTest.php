<?php

declare(strict_types=1);

use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Actions\AddIssueNoteAction;
use App\Domains\Issues\Actions\AssignIssueAction;
use App\Domains\Issues\Actions\BulkChangeIssueStatusAction;
use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Actions\NotifyWatchersAction;
use App\Domains\Issues\Actions\SendWatchDigestsAction;
use App\Domains\Issues\Actions\UnwatchIssueAction;
use App\Domains\Issues\Actions\WatchIssueAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Events\WatchedIssueUpdated;
use App\Domains\Issues\Http\Controllers\WatchingController;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueWatcher;
use App\Domains\Issues\Models\IssueWatchUpdate;
use App\Domains\Issues\Notifications\WatchedIssueNotification;
use App\Domains\Issues\Support\WatchLinks;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\Support\Mail\ReadsSentMails;

covers(NotifyWatchersAction::class, WatchIssueAction::class, UnwatchIssueAction::class, SendWatchDigestsAction::class, WatchedIssueNotification::class, WatchingController::class);

uses(ReadsSentMails::class);

beforeEach(function () {
    $this->issue = Issue::factory()->create(['title' => 'RuntimeException']);
    $this->project = $this->issue->project;
    $this->team = $this->project->team;
    $this->ref = $this->issue->reference();
    $this->morten = User::factory()->inTeam($this->team)->create(['name' => 'Morten', 'email' => 'morten@example.test']);
    $this->sofie = User::factory()->inTeam($this->team)->create(['name' => 'Sofie', 'email' => 'sofie@example.test']);
    $this->watching = fn (User $user): bool => $this->issue->watchers()->whereKey($user->id)->exists();
    $this->rule = function (AlertRuleType $type, string $address): void {
        $rule = AlertRule::query()->firstOrCreate(['team_id' => $this->team->id, 'type' => $type]);
        $rule->update(['enabled' => true]);
        $rule->channels()->attach(NotificationChannel::factory()->for($this->team)->create(['type' => ChannelType::Mail, 'name' => 'Ops', 'config' => ['address' => $address]]));
    };
    $this->flush = function (): int {
        $this->travel(SendWatchDigestsAction::WINDOW_MINUTES + 1)->minutes();

        return app(SendWatchDigestsAction::class)->execute();
    };
});

describe('becoming a watcher', function () {
    it('watches an issue someone is assigned to, writes a note on, is mentioned on or resolves', function () {
        $anna = User::factory()->inTeam($this->team)->create();
        $jonas = User::factory()->inTeam($this->team)->create();

        app(AssignIssueAction::class)->execute($this->issue, $this->sofie, $this->morten);
        app(AddIssueNoteAction::class)->execute($this->issue, "Looking, <@{$anna->id}>", $this->morten);
        app(ChangeIssueStatusAction::class)->execute(Issue::factory()->for($this->project)->create(), IssueStatus::Resolved, $jonas);

        expect(IssueWatcher::query()->where('issue_id', $this->issue->id)->pluck('reason', 'user_id')->map->value->all())
            ->toEqual([$this->sofie->id => 'assigned', $this->morten->id => 'noted', $anna->id => 'mentioned'])
            ->and(IssueWatcher::query()->where('user_id', $jonas->id)->sole()->reason)->toBe(WatchReason::Resolved);
    });

    it('keeps an explicit unwatch, until they choose to watch again', function () {
        app(AddIssueNoteAction::class)->execute($this->issue, 'First look.', $this->morten);
        app(UnwatchIssueAction::class)->execute($this->issue, $this->morten);
        app(AddIssueNoteAction::class)->execute($this->issue, 'Second look.', $this->morten);
        app(AssignIssueAction::class)->execute($this->issue, $this->morten, $this->sofie);

        expect(($this->watching)($this->morten))->toBeFalse();

        app(WatchIssueAction::class)->execute($this->issue, $this->morten);

        expect(($this->watching)($this->morten))->toBeTrue();
    });

    it('watches and stops watching from the issue page, and many from the list', function () {
        $other = Issue::factory()->for($this->project)->create();

        $this->actingAs($this->morten)->put(route('issues.watch', [$this->project, $this->issue]), ['watching' => true])->assertRedirect();
        expect(($this->watching)($this->morten))->toBeTrue();

        $this->actingAs($this->morten)->get(route('issues.show', [$this->project, $this->issue]))
            ->assertInertia(fn ($page) => $page->where('watching', true)->where('watchers.0.name', 'Morten'));

        $this->actingAs($this->morten)->put(route('issues.watch', [$this->project, $this->issue]), ['watching' => false]);
        expect(($this->watching)($this->morten))->toBeFalse();

        $this->actingAs($this->sofie)->post(route('issues.bulk-watch', $this->project), ['issues' => [$this->issue->uuid, $other->uuid]])
            ->assertInertiaFlash('toast.message', 'You watch 2 issues.');
        $this->actingAs($this->sofie)->get(route('issues.index', ['project' => $this->project, 'watching' => 1]))
            ->assertInertia(fn ($page) => $page->has('issues.data', 2)->where('filters.watching', true));
        $this->actingAs($this->morten)->get(route('issues.index', ['project' => $this->project, 'watching' => 1]))
            ->assertInertia(fn ($page) => $page->has('issues.data', 0));
    });

    it('lets nobody outside the team watch', function () {
        $this->actingAs(User::factory()->create())->put(route('issues.watch', [$this->project, $this->issue]), ['watching' => true])->assertForbidden();
    });
});

describe('what watchers hear', function () {
    beforeEach(fn () => app(WatchIssueAction::class)->execute($this->issue, $this->morten));

    it('mails a regression right away, with a one-click unsubscribe', function () {
        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::Regression, 'production');

        $mail = $this->mailsTo('morten@example.test')->sole();
        expect($mail->getSubject())->toBe("[{$this->project->name}] Regressed: {$this->ref} RuntimeException")
            ->and($mail->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
            ->and($mail->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toContain('/watch/'.$this->issue->uuid.'/stop')
            ->and($mail->getTextBody())->toContain("You get this because you watch {$this->ref}.");
    });

    it('groups notes, assignee and status changes into one mail at the end of the window', function () {
        Event::fake([WatchedIssueUpdated::class]);
        app(AddIssueNoteAction::class)->execute($this->issue, 'The fix is ready for review.', $this->sofie);
        app(AssignIssueAction::class)->execute($this->issue, $this->sofie, $this->sofie);
        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, $this->sofie, 'Deployed in v1.42.6.');

        expect($this->mailsTo('morten@example.test'))->toHaveCount(0)
            ->and(IssueWatchUpdate::query()->where('user_id', $this->morten->id)->count())->toBe(3)
            ->and(app(SendWatchDigestsAction::class)->execute())->toBe(0);
        Event::assertDispatchedTimes(WatchedIssueUpdated::class, 3);

        expect(($this->flush)())->toBe(1);

        $mail = $this->mailsTo('morten@example.test')->sole();
        expect($mail->getSubject())->toBe("[{$this->project->name}] 3 updates on {$this->ref} RuntimeException")
            ->and($mail->getTextBody())->toContain('Sofie wrote a note', '> The fix is ready for review.', 'Sofie took it', 'Sofie resolved it', '> Deployed in v1.42.6.', 'Stop watching: ')
            ->and(IssueWatchUpdate::query()->count())->toBe(0)
            ->and(($this->flush)())->toBe(0);
    });

    it('names a single update in the subject', function () {
        app(AddIssueNoteAction::class)->execute($this->issue, 'Looking.', $this->sofie);
        ($this->flush)();

        expect($this->mailsTo('morten@example.test')->sole()->getSubject())->toBe("[{$this->project->name}] Sofie wrote a note on {$this->ref}");
    });

    it('tells nobody about their own actions', function () {
        Event::fake([WatchedIssueUpdated::class]);
        app(AddIssueNoteAction::class)->execute($this->issue, 'My own note.', $this->morten);
        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Ignored, $this->morten);

        expect(IssueWatchUpdate::query()->count())->toBe(0);
        Event::assertNotDispatched(WatchedIssueUpdated::class);
    });

    it('respects someone who wants no watching mail, or no toast', function () {
        Event::fake([WatchedIssueUpdated::class]);
        $this->morten->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['watching' => ['mail' => false]])])->save();

        app(AddIssueNoteAction::class)->execute($this->issue, 'Looking.', $this->sofie);

        expect(IssueWatchUpdate::query()->count())->toBe(0);
        Event::assertDispatchedTimes(WatchedIssueUpdated::class, 1);

        $this->morten->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['watching' => ['toast' => false]])])->save();
        app(AddIssueNoteAction::class)->execute($this->issue, 'Again.', $this->sofie);

        expect(IssueWatchUpdate::query()->count())->toBe(1);
        Event::assertDispatchedTimes(WatchedIssueUpdated::class, 1);
    });

    it('saves the preferences from the profile page', function () {
        $prefs = NotificationPreferences::DEFAULTS;
        $prefs['watching']['mail'] = false;
        $prefs['mentions']['toast'] = false;

        $this->actingAs($this->morten)->put(route('notifications.update'), $prefs)->assertRedirect(route('notifications.edit'));

        expect($this->morten->refresh()->notification_preferences->toArray())->toBe($prefs);
    });
});

describe('one mail per address with watchers', function () {
    beforeEach(fn () => app(WatchIssueAction::class)->execute($this->issue, $this->morten));

    it('sends a watcher on the regression rule one mail, naming both', function () {
        ($this->rule)(AlertRuleType::Regression, ' MORTEN@example.test');

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::Regression, 'production');

        expect($this->mailsTo('morten@example.test')->sole()->getTextBody())->toContain("you watch {$this->ref} and the Regression rule of");
    });

    it('holds back the status rule mail for a watcher and names the rule in the grouped mail', function () {
        ($this->rule)(AlertRuleType::StatusChange, 'morten@example.test');

        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, $this->sofie);

        expect($this->mailsTo('morten@example.test'))->toHaveCount(0);
        ($this->flush)();
        expect($this->mailsTo('morten@example.test')->sole()->getTextBody())->toContain("you watch {$this->ref} and the Resolved or ignored rule of");
    });

    it('sends a mentioned watcher the mention only', function () {
        app(AddIssueNoteAction::class)->execute($this->issue, "<@{$this->morten->id}> please check", $this->sofie);
        ($this->flush)();

        expect($this->mailsTo('morten@example.test')->sole()->getSubject())->toContain('Sofie mentioned you on');
    });

    it('lets the summary of a bulk resolve stand in for the watcher mails', function () {
        ($this->rule)(AlertRuleType::StatusChange, 'morten@example.test');
        $other = Issue::factory()->for($this->project)->create();

        app(BulkChangeIssueStatusAction::class)->execute($this->project, [$this->issue->uuid, $other->uuid], IssueStatus::Resolved, $this->sofie);
        ($this->flush)();

        $mail = $this->mailsTo('morten@example.test')->sole();
        expect($mail->getSubject())->toContain('Sofie resolved 2 issues')
            ->and($mail->getTextBody())->toContain("you watch {$this->ref}");
    });
});

describe('the stop link', function () {
    beforeEach(fn () => app(WatchIssueAction::class)->execute($this->issue, $this->morten));

    it('asks first, then stops watching without signing in', function () {
        $url = WatchLinks::stop($this->issue, $this->morten);

        $this->get($url)->assertOk()->assertSee('Stop watching');
        expect(($this->watching)($this->morten))->toBeTrue();

        $this->post($url)->assertOk()->assertSee('You no longer watch it');
        expect(($this->watching)($this->morten))->toBeFalse();
    });

    it('rejects a tampered link', function () {
        $url = str_replace($this->morten->uuid, $this->sofie->uuid, WatchLinks::stop($this->issue, $this->morten));

        $this->post($url)->assertForbidden();
        $this->get(route('watching.stop', ['issue' => $this->issue->uuid, 'user' => $this->morten->uuid]))->assertForbidden();
        expect(($this->watching)($this->morten))->toBeTrue();
    });
});
