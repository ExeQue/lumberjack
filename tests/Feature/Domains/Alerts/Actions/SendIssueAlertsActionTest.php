<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Drivers\DiscordDriver;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\AlertSend;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Actions\AddIssueNoteAction;
use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;

covers(SendIssueAlertsAction::class);

beforeEach(function () {
    $this->issue = Issue::factory()->create();
    $this->channel = NotificationChannel::factory()->for($this->issue->project->team)->create();
    $this->rule = AlertRule::factory()->for($this->issue->project)->create();
    $this->rule->channels()->attach($this->channel);
});

describe('sending an alert', function () {
    beforeEach(fn () => $this->ok = fn () => Http::fake(['discord.com/*' => Http::response(null, 204)]));

    it('posts a Discord embed for a new issue', function () {
        ($this->ok)();
        expect(app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production'))->toBe(1);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://discord.com/api/webhooks/')
            && str_contains($request['embeds'][0]['description'], $this->issue->title)
            && $request['username'] === 'Lumberjack'
            && $request['avatar_url'] === asset('images/lumberjack-avatar.png'));
    });

    it('sends from the name the channel sets', function () {
        ($this->ok)();
        $this->channel->update(['config' => [...$this->channel->config, 'sender_name' => 'Shop alerts']]);

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');

        Http::assertSent(fn ($request) => $request['username'] === 'Shop alerts');
    });

    it('sends at most once per issue within the throttle window', function () {
        ($this->ok)();
        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');
        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');

        Http::assertSentCount(1);
    });

    it('skips an environment the rule does not cover', function () {
        $this->rule->update(['environments' => ['production']]);

        expect(app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'staging'))->toBe(0);
    });

    it('never alerts for an ignored issue', function () {
        $this->issue->update(['status' => 'ignored']);

        expect(app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production'))->toBe(0);
    });

    it('never alerts for a browser error', function () {
        Http::fake();
        $this->issue->update(['source' => 'browser']);

        expect(app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production'))->toBe(0);
        Http::assertNothingSent();
    });

    it('records a failed delivery instead of throwing', function () {
        Http::fake(['discord.com/*' => Http::response('nope', 500)]);

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');

        expect(AlertSend::sole()->status)->toBe('failed')
            ->and($this->channel->refresh()->last_delivery_status)->toBe('failed');
    });
});

describe('notes', function () {
    beforeEach(function () {
        Http::fake(['discord.com/*' => Http::response(null, 204)]);
        $this->noteRule = AlertRule::factory()->for($this->issue->project)->create(['type' => AlertRuleType::Note]);
        $this->noteRule->channels()->attach($this->channel);
    });

    it('sends every note, by whoever wrote it, unthrottled and whatever the issue status', function () {
        $this->issue->update(['status' => 'ignored']);
        $user = User::factory()->create(['name' => 'Morten']);

        app(AddIssueNoteAction::class)->execute($this->issue, 'Looks like the payment provider again.', $user);
        app(AddIssueNoteAction::class)->execute($this->issue, 'Retried, works now.', $user);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['embeds'][0]['author']['name'] === 'Morten'
            && $request['embeds'][0]['color'] === DiscordDriver::COLOR_NOTE
            && str_contains($request['embeds'][0]['description'], 'Looks like the payment provider again.'));
    });

    it('names the MCP token an agent wrote through', function () {
        $token = McpToken::factory()->for($this->issue->project->team)->create(['name' => 'triage-bot']);

        app(AddIssueNoteAction::class)->execute($this->issue, 'Null customer on guest checkout.', token: $token);

        Http::assertSent(fn ($request) => $request['embeds'][0]['author']['name'] === 'triage-bot (MCP)');
    });

    it('sends nothing while the note rule is off', function () {
        $this->noteRule->update(['enabled' => false]);

        app(AddIssueNoteAction::class)->execute($this->issue, 'Quiet note.', User::factory()->create());

        Http::assertNothingSent();
    });

    it('puts the latest note, by a person too, on an error alert as its summary', function () {
        $this->issue->notes()->create(['body' => "Known issue, fix in #482\nMore detail", 'user_id' => User::factory()->create()->id]);

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');

        Http::assertSent(fn ($request) => isset($request['embeds'][0]['description']) && str_contains($request['embeds'][0]['description'], '> Known issue, fix in #482'));
    });
});

describe('status changes', function () {
    beforeEach(function () {
        Http::fake(['discord.com/*' => Http::response(null, 204)]);
        $this->statusRule = AlertRule::factory()->for($this->issue->project)->create(['type' => AlertRuleType::StatusChange]);
        $this->statusRule->channels()->attach($this->channel);
        $this->morten = User::factory()->inTeam($this->issue->project->team)->create(['name' => 'Morten']);
    });

    it('sends who resolved the issue and their comment, once, even with the note rule on', function () {
        AlertRule::factory()->for($this->issue->project)->create(['type' => AlertRuleType::Note])->channels()->attach($this->channel);

        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, $this->morten, 'Fixed by retrying the webhook.');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['embeds'][0]['title'] === 'Resolved by Morten in '.$this->issue->project->name
            && $request['embeds'][0]['color'] === DiscordDriver::COLOR_RESOLVED
            && str_contains($request['embeds'][0]['description'], 'Fixed by retrying the webhook.'));
        expect($this->issue->notes()->sole()->status)->toBe(IssueStatus::Resolved);
    });

    it('sends an ignore without a comment too', function () {
        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Ignored, $this->morten);

        Http::assertSent(fn ($request) => $request['embeds'][0]['title'] === 'Ignored by Morten in '.$this->issue->project->name);
    });

    it('lets the note rule carry the comment, marked, while the status rule is off', function () {
        $this->statusRule->update(['enabled' => false]);
        AlertRule::factory()->for($this->issue->project)->create(['type' => AlertRuleType::Note])->channels()->attach($this->channel);

        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, $this->morten, 'Fixed.');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['embeds'][0]['title'] === 'Resolved by Morten in '.$this->issue->project->name
            && $request['embeds'][0]['color'] === DiscordDriver::COLOR_NOTE);
    });

    it('stays quiet for bulk changes and for anything but resolving or ignoring', function () {
        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, $this->morten, announce: false);
        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::InProgress, $this->morten);

        Http::assertNothingSent();
    });
});
