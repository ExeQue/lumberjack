<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Actions\AddIssueNoteAction;
use App\Domains\Issues\Models\Issue;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\MailRecipients;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\Mail\ReadsSentMails;

covers(MailRecipients::class, MailReason::class);

uses(ReadsSentMails::class);

beforeEach(function () {
    $this->issue = Issue::factory()->create();
    $this->team = $this->issue->project->team;
    $this->rule = function (AlertRuleType $type, NotificationChannel ...$channels): void {
        $rule = AlertRule::query()->firstOrCreate(['team_id' => $this->team->id, 'type' => $type]);
        $rule->update(['enabled' => true]);
        $rule->channels()->attach(collect($channels)->pluck('id'));
    };
    $this->mailChannel = fn (string $name, string $address) => NotificationChannel::factory()->for($this->team)->create(['type' => ChannelType::Mail, 'name' => $name, 'config' => ['address' => $address]]);
});

describe('one mail per address per event', function () {
    it('sends one mail when two mail channels of a rule share an address, naming both', function () {
        ($this->rule)(AlertRuleType::NewIssue, ($this->mailChannel)('Ops', 'ops@example.test'), ($this->mailChannel)('On call', ' OPS@Example.test '), ($this->mailChannel)('Dev', 'dev@example.test'));

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');

        expect($this->mailsTo('ops@example.test'))->toHaveCount(1)
            ->and($this->mailsTo('dev@example.test'))->toHaveCount(1)
            ->and($this->mailsTo('ops@example.test')->sole()->getTextBody())->toContain('the mail channel Ops', 'the mail channel On call', ' and the New issue rule of');
    });

    it('sends the mention, not the note rule mail, to an address on both, and says both', function () {
        $sofie = User::factory()->inTeam($this->team)->create(['name' => 'Sofie']);
        $morten = User::factory()->inTeam($this->team)->create(['name' => 'Morten', 'email' => 'morten@example.test']);
        ($this->rule)(AlertRuleType::Note, ($this->mailChannel)('Ops', 'Morten@example.test'), ($this->mailChannel)('Dev', 'dev@example.test'));

        app(AddIssueNoteAction::class)->execute($this->issue, "<@{$morten->id}> look", $sofie);

        $mail = $this->mailsTo('morten@example.test')->sole();
        expect($mail->getSubject())->toContain('Sofie mentioned you on')
            ->and($mail->getTextBody())->toContain('Sofie mentioned you in a note on an issue in', 'and the Note rule of', 'mail channel Ops.')
            ->and($this->mailsTo('dev@example.test'))->toHaveCount(1);
    });

    it('leaves chat channels alone', function () {
        Http::fake(['discord.com/*' => Http::response(null, 204)]);
        ($this->rule)(AlertRuleType::NewIssue, ($this->mailChannel)('Ops', 'ops@example.test'), ($this->mailChannel)('Ops 2', 'ops@example.test'), NotificationChannel::factory()->for($this->team)->create(), NotificationChannel::factory()->for($this->team)->create(['config' => ['webhook_url' => 'https://discord.com/api/webhooks/9/z']]));

        expect(app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production'))->toBe(3);

        Http::assertSentCount(2);
        expect($this->mailsTo('ops@example.test'))->toHaveCount(1);
    });

    it('keeps separate events separate', function () {
        ($this->rule)(AlertRuleType::Regression, ($this->mailChannel)('Ops', 'ops@example.test'));

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::Regression, 'production');
        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::Regression, 'production');

        expect($this->mailsTo('ops@example.test'))->toHaveCount(2);
    });

    test('the most specific offer wins, given every reason, and a failing one stops nothing', function () {
        $recipients = new MailRecipients();
        $got = [];
        $recipients->offer('a@example.test', MailRecipients::CHANNEL, MailReason::mentioned('X', 'P'), fn () => $got[] = 'channel');
        $recipients->offer(' A@example.test', MailRecipients::PERSONAL, MailReason::assigned('Y', 'P'), function (array $reasons) use (&$got): void {
            $got[] = 'personal:'.count($reasons);
        });
        $recipients->offer('b@example.test', MailRecipients::WATCHER, MailReason::watching('DEMO-1'), fn () => throw new RuntimeException('down'));
        $recipients->offer('c@example.test', MailRecipients::CHANNEL, MailReason::watching('DEMO-2'), function () use (&$got): void {
            $got[] = 'c';
        });

        $recipients->send();
        $recipients->send();

        expect($got)->toBe(['personal:2', 'c']);
    });
});
