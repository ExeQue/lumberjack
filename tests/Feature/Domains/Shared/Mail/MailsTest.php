<?php

declare(strict_types=1);

use App\Domains\Administration\Notifications\AccountMails;
use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Actions\SendTestAlertAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Notifications\IssueAlertNotification;
use App\Domains\Alerts\Notifications\TestAlertNotification;
use App\Domains\Issues\Actions\AddIssueNoteAction;
use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Teams\Actions\InviteMemberAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Notifications\TeamInvitationNotification;
use App\Models\User;
use Tests\Support\Mail\ReadsSentMails;

covers(MailCard::class, MailReason::class, IssueAlertNotification::class, TestAlertNotification::class, TeamInvitationNotification::class, AccountMails::class);

uses(ReadsSentMails::class);

beforeEach(function () {
    config(['lumberjack.mail.alerts_from' => ['address' => 'alerts@lumberjack.test', 'name' => 'Lumberjack'], 'lumberjack.mail.notifications_from' => ['address' => 'notifications@lumberjack.test', 'name' => 'Lumberjack']]);
    $this->issue = Issue::factory()->create(['title' => 'RuntimeException', 'message' => 'Cart total is negative.', 'last_release' => 'v1.42.5', 'occurrences_count' => 3, 'users_count' => 2]);
    $this->project = $this->issue->project;
    $this->project->update(['name' => 'Demo']);
    $this->ref = $this->issue->reference();
    $this->ops = NotificationChannel::factory()->mail()->for($this->project->team)->create(['name' => 'Ops']);
    $this->rule = function (AlertRuleType $type): void {
        $rule = AlertRule::query()->firstOrCreate(['team_id' => $this->project->team_id, 'type' => $type]);
        $rule->update(['enabled' => true]);
        $rule->channels()->attach($this->ops);
    };
});

describe('issue alert mails', function () {
    it('names the project and the issue in the subject, and lists the facts', function () {
        ($this->rule)(AlertRuleType::NewIssue);

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::NewIssue, 'production');

        $mail = $this->mailsTo('ops@example.test')->sole();
        expect($mail->getSubject())->toBe("[Demo] New error: {$this->ref} RuntimeException")
            ->and($mail->getFrom()[0]->getAddress())->toBe('alerts@lumberjack.test')
            ->and($mail->getHtmlBody())->toContain('Cart total is negative.', 'v1.42.5', 'Users affected', "Open {$this->ref}", 'All open issues in Demo', 'Demo · production', 'the mail channel <strong>Ops</strong>')
            ->and($mail->getTextBody())->toContain("Open {$this->ref}: ", 'Occurrences: 3', 'You get this because the New issue rule of', 'Team settings, Alerts (');
    });

    it('says who resolved a regressed issue and where it came back', function () {
        ($this->rule)(AlertRuleType::Regression);
        $morten = User::factory()->inTeam($this->project->team)->create(['name' => 'Morten']);
        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, $morten);
        $this->issue->update(['status' => IssueStatus::Open]);

        app(SendIssueAlertsAction::class)->execute($this->issue->refresh(), AlertRuleType::Regression, 'production');

        $mail = $this->mailsTo('ops@example.test')->last();
        expect($mail->getSubject())->toBe("[Demo] Regressed: {$this->ref} RuntimeException")
            ->and($mail->getTextBody())->toContain('Morten resolved it', 'It happened again in v1.42.5.', 'Compare releases: ');
    });

    it('counts an escalation and quotes the comment of a resolve', function () {
        ($this->rule)(AlertRuleType::Escalation);
        ($this->rule)(AlertRuleType::StatusChange);

        app(SendIssueAlertsAction::class)->execute($this->issue, AlertRuleType::Escalation, 'production', 42);
        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, User::factory()->inTeam($this->project->team)->create(['name' => 'Morten']), 'Fixed in #489.');

        [$escalation, $resolved] = $this->mailsTo('ops@example.test')->all();
        expect($escalation->getSubject())->toBe("[Demo] Escalating: {$this->ref} RuntimeException")
            ->and($escalation->getTextBody())->toContain('42 in the last 5 minutes.', 'Assignee: Nobody yet')
            ->and($resolved->getSubject())->toBe("[Demo] Morten resolved {$this->ref} RuntimeException")
            ->and($resolved->getTextBody())->toContain('> Fixed in #489.', 'RESOLVED BY MORTEN');
    });

    it('sends a test message in the same layout', function () {
        app(SendTestAlertAction::class)->execute($this->ops);

        $mail = $this->mailsTo('ops@example.test')->sole();
        expect($mail->getSubject())->toBe('Test message for Ops')
            ->and($mail->getHtmlBody())->toContain('images/lumberjack-avatar.png', 'prefers-color-scheme: dark');
    });
});

describe('personal mails', function () {
    it('quotes the note that mentions someone, from the notifications sender', function () {
        $sofie = User::factory()->inTeam($this->project->team)->create(['name' => 'Sofie']);
        $morten = User::factory()->inTeam($this->project->team)->create(['name' => 'Morten', 'email' => 'morten@example.test']);

        app(AddIssueNoteAction::class)->execute($this->issue, "<@{$morten->id}> can you check the rounding?", $sofie);

        $mail = $this->mailsTo('morten@example.test')->sole();
        expect($mail->getSubject())->toBe("[Demo] Sofie mentioned you on {$this->ref}")
            ->and($mail->getFrom()[0]->getAddress())->toBe('notifications@lumberjack.test')
            ->and($mail->getTextBody())->toContain('> @Morten can you check the rounding?', "Reply on {$this->ref}: ", 'You get this because Sofie mentioned you');
    });

    it('invites with the team, the role and the projects', function () {
        $morten = User::factory()->inTeam($this->project->team)->create(['name' => 'Morten Harders']);

        app(InviteMemberAction::class)->execute($this->project->team, 'jonas@example.test', TeamRole::Member, $morten);

        $mail = $this->mailsTo('jonas@example.test')->sole();
        expect($mail->getSubject())->toBe("Morten invited you to {$this->project->team->name} on Lumberjack")
            ->and($mail->getHtmlBody())->toContain('Accept the invitation', '<strong class="lj-ink" style="color:#15181c;">a member</strong>', $this->project->slug)
            ->and($mail->getTextBody())->toContain('You get this because Morten invited jonas@example.test.');
    });

    it('sends the password reset and the address confirmation in the layout', function () {
        $user = User::factory()->unverified()->create(['email' => 'morten@example.test']);

        $this->post(route('password.email'), ['email' => $user->email]);
        $user->sendEmailVerificationNotification();

        [$reset, $verify] = $this->mailsTo('morten@example.test')->all();
        expect($reset->getSubject())->toBe('Reset your Lumberjack password')
            ->and($reset->getTextBody())->toContain('Choose a new password: ', 'The link works for 60 minutes and only once.')
            ->and($reset->getHtmlBody())->toContain('Button not working?')
            ->and($verify->getSubject())->toBe('Confirm your email address for Lumberjack')
            ->and($verify->getTextBody())->toContain('Confirm the address: ');
    });
});
