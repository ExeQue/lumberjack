<?php

declare(strict_types=1);

namespace App\Domains\Shared\Mail;

use App\Domains\Administration\Notifications\AccountMails;
use App\Domains\Alerts\Data\BulkStatusChangeAlert;
use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Data\QueueBacklogAlert;
use App\Domains\Alerts\Data\QueueBacklogSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Notifications\AlertSummaryNotification;
use App\Domains\Alerts\Notifications\IssueAlertNotification;
use App\Domains\Alerts\Notifications\TestAlertNotification;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Data\WatchUpdate;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Notifications\IssueAssignmentNotification;
use App\Domains\Issues\Notifications\MentionedInNoteNotification;
use App\Domains\Issues\Notifications\WatchedIssueNotification;
use App\Domains\Issues\Support\WatchLinks;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Domains\Teams\Models\TeamInvitation;
use App\Domains\Teams\Notifications\TeamInvitationNotification;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * Every mail Lumberjack sends, filled with sample data from unsaved models, for the
 * local preview at /dev/mail and for the test that renders them all. Nothing here
 * touches the database.
 */
final class MailPreviews
{
    private Team $team;

    private Project $project;

    private User $morten;

    private User $sofie;

    private NotificationChannel $ops;

    public function __construct()
    {
        $this->team = new Team(['name' => 'Local Team']);
        $this->team->forceFill(['id' => 1, 'uuid' => (string) Str::uuid()]);
        $this->project = new Project(['name' => 'Demo', 'slug' => 'demo']);
        $this->project->forceFill(['id' => 1, 'uuid' => (string) Str::uuid(), 'team_id' => 1, 'issue_prefix' => 'DEMO']);
        $this->project->setRelation('team', $this->team);
        $this->team->setRelation('projects', new Collection([$this->project, new Project(['name' => 'Acme Shop', 'slug' => 'acme-shop'])]));
        $this->morten = $this->user('Morten Harders', 'morten@example.com');
        $this->sofie = $this->user('Sofie Admin', 'sofie@example.com');
        $this->ops = new NotificationChannel(['name' => 'Ops', 'type' => ChannelType::Mail, 'config' => ['address' => 'ops@example.com']]);
        $this->ops->forceFill(['id' => 1, 'team_id' => 1]);
    }

    /**
     * Name => what it is and how to build it.
     *
     * @return array<string, array{label: string, mail: Closure(): MailMessage}>
     */
    public function all(): array
    {
        $issue = $this->issue(12, 'RuntimeException', 'Cart total is negative after applying the discount.');
        $stock = $this->issue(7, 'Stock service refused the reservation', 'RuntimeException: Stock service refused the reservation.', 'app/Services/StockClient.php', 41, 212, 48);
        $broken = $this->issue(1, 'Command failed: demo:broken', 'Exited with code 1.', null, null, 24, 0);
        $mail = fn (IssueAlertNotification|AlertSummaryNotification|TestAlertNotification|MentionedInNoteNotification|IssueAssignmentNotification|TeamInvitationNotification|WatchedIssueNotification $notification): MailMessage => $notification->toMail(new AnonymousNotifiable());

        return [
            'new-error' => ['label' => 'New error', 'mail' => fn () => $mail(new IssueAlertNotification(
                new IssueAlert($issue, AlertRuleType::NewIssue, 'production', $this->issueUrl($issue), 'Rounding changed in #482; a refund can now exceed the subtotal.', triageAuthor: 'triage-bot (MCP)'),
                [$this->rule(AlertRuleType::NewIssue)],
            ))],
            'regression' => ['label' => 'Regression', 'mail' => fn () => $mail(new IssueAlertNotification(
                new IssueAlert($broken, AlertRuleType::Regression, 'workbench', $this->issueUrl($broken), resolvedBy: 'Morten Harders', resolvedAt: now()->subHours(2)),
                [$this->rule(AlertRuleType::Regression)],
            ))],
            'escalation' => ['label' => 'Escalation', 'mail' => fn () => $mail(new IssueAlertNotification(
                new IssueAlert($stock, AlertRuleType::Escalation, 'production', $this->issueUrl($stock), burst: 30),
                [$this->rule(AlertRuleType::Escalation, 'On call')],
            ))],
            'note' => ['label' => 'Note (rule)', 'mail' => fn () => $mail(new IssueAlertNotification(
                new IssueAlert($issue, AlertRuleType::Note, 'production', $this->issueUrl($issue), note: $this->note($issue, 'The rounding fix in #489 is ready for review.')),
                [$this->rule(AlertRuleType::Note)],
            ))],
            'resolved' => ['label' => 'Resolved, with a comment', 'mail' => fn () => $mail(new IssueAlertNotification(
                new IssueAlert(tap(clone $issue)->forceFill(['resolved_at' => now(), 'first_seen_at' => now()->subMinutes(41)]), AlertRuleType::StatusChange, 'production', $this->issueUrl($issue), note: $this->note($issue, 'Fixed in #489: the discount is applied before rounding. Deployed in v1.42.6.', IssueStatus::Resolved), statusChange: IssueStatus::Resolved, actor: 'Morten Harders'),
                [$this->rule(AlertRuleType::StatusChange)],
            ))],
            'bulk-resolved' => ['label' => 'Bulk resolve', 'mail' => fn () => $mail(new AlertSummaryNotification(
                new BulkStatusChangeAlert($this->project, IssueStatus::Resolved, 'Morten', [$broken, $this->issue(2, 'LogicException'), $this->issue(3, 'Voucher could not be applied to the basket.'), $this->issue(4, 'Payment provider timed out')], ['production'], route('issues.index', ['project' => $this->project, 'status' => 'resolved'])),
                [$this->rule(AlertRuleType::StatusChange)],
            ))],
            'health-down' => ['label' => 'Health check down', 'mail' => fn () => $mail(new AlertSummaryNotification(
                $this->health(HealthCheckAlert::DOWN, 'Connection refused'),
                [$this->rule(AlertRuleType::HealthDown, 'On call')],
            ))],
            'health-recovered' => ['label' => 'Health check recovered', 'mail' => fn () => $mail(new AlertSummaryNotification(
                $this->health(HealthCheckAlert::RECOVERED, null),
                [$this->rule(AlertRuleType::HealthRecovered, 'On call')],
            ))],
            'queue-backlog' => ['label' => 'Queue backlog', 'mail' => fn () => $mail(new AlertSummaryNotification(
                new QueueBacklogAlert($this->project, 'redis', 'emails', 1240, 720, new QueueBacklogSettings(), now()->subMinutes(6)->toImmutable(), false, route('jobs.index', $this->project)),
                [$this->rule(AlertRuleType::QueueBacklog, 'On call')],
            ))],
            'test' => ['label' => 'Test message', 'mail' => fn () => $mail(new TestAlertNotification($this->ops))],
            'mention' => ['label' => 'Mention', 'mail' => fn () => $mail(new MentionedInNoteNotification(
                $this->note($issue, 'x'),
                'Sofie Admin',
                '@Morten can you check whether the discount rounding changed in the last deploy? It started right after v1.42.5.',
                [MailReason::mentioned('Sofie Admin', 'Demo'), $this->rule(AlertRuleType::Note)],
            ))],
            'assigned' => ['label' => 'Assigned, several issues', 'mail' => fn () => $mail(new IssueAssignmentNotification(
                new Collection([tap($stock)->forceFill(['escalated_at' => now()]), $this->issue(9, 'Payment provider timed out'), $issue]),
                'Morten',
                true,
            ))],
            'unassigned-resolved' => ['label' => 'Taken off on resolve', 'mail' => fn () => $mail(new IssueAssignmentNotification(new Collection([tap(clone $issue)->forceFill(['status' => IssueStatus::Resolved])]), 'Morten', false, true))],
            'watching' => ['label' => 'Watching, grouped updates', 'mail' => fn () => $mail(new WatchedIssueNotification($issue, $this->morten, [
                new WatchUpdate($issue, WatchUpdate::NOTE, 'Sofie', body: 'The rounding fix in #489 is ready for review.', at: now()->setTime(11, 2)->toImmutable()),
                new WatchUpdate($issue, WatchUpdate::ASSIGNEE, 'Morten', ['from' => 'Sofie', 'to' => 'Morten', 'self' => '1'], at: now()->setTime(11, 4)->toImmutable()),
                new WatchUpdate($issue, WatchUpdate::STATUS, 'Morten', ['status' => 'resolved'], 'Fixed in #489, deployed in v1.42.6.', at: now()->setTime(11, 6)->toImmutable()),
            ], WatchReason::Noted))],
            'watching-one' => ['label' => 'Watching, one update', 'mail' => fn () => $mail(new WatchedIssueNotification($issue, $this->morten, [
                new WatchUpdate($issue, WatchUpdate::NOTE, 'Sofie', body: 'Can you check whether the discount rounding changed?', at: now()->setTime(11, 2)->toImmutable()),
            ], WatchReason::Assigned, [$this->rule(AlertRuleType::Note)]))],
            'watching-regression' => ['label' => 'Watching, regression', 'mail' => fn () => $mail(new IssueAlertNotification(
                new IssueAlert($broken, AlertRuleType::Regression, 'workbench', $this->issueUrl($broken), resolvedBy: 'Morten Harders', resolvedAt: now()->subHours(2)),
                [MailReason::watching('DEMO-1', WatchReason::Resolved->phrase(), WatchLinks::stop($broken, $this->morten)), $this->rule(AlertRuleType::Regression)],
            ))],
            'invitation' => ['label' => 'Invitation', 'mail' => fn () => $mail(new TeamInvitationNotification($this->invitation(), Str::random(48)))],
            'password-reset' => ['label' => 'Password reset', 'mail' => fn () => app(AccountMails::class)->resetMail($this->morten, Str::random(64))],
            'verify-email' => ['label' => 'Email confirmation', 'mail' => fn () => app(AccountMails::class)->verifyMail($this->morten, url('/email/verify/1/'.sha1('morten@example.com').'?expires=1&signature=preview'))],
        ];
    }

    private function user(string $name, string $email): User
    {
        $user = new User(['name' => $name, 'email' => $email]);
        $user->forceFill(['id' => crc32($email), 'uuid' => (string) Str::uuid()]);

        return $user;
    }

    private function issue(int $number, string $title, ?string $message = null, ?string $file = 'app/Http/Controllers/CartController.php', ?int $line = 88, int $occurrences = 3, int $users = 2): Issue
    {
        $issue = new Issue();
        $issue->forceFill([
            'id' => $number,
            'uuid' => (string) Str::uuid(),
            'project_id' => 1,
            'number' => $number,
            'source' => IssueSource::Server,
            'status' => IssueStatus::Open,
            'title' => $title,
            'message' => $message ?? $title,
            'file' => $file,
            'line' => $line,
            'last_release' => 'v1.42.5',
            'occurrences_count' => $occurrences,
            'users_count' => $users,
            'first_seen_at' => now()->setTime(10, 42),
            'last_seen_at' => now(),
        ]);
        $issue->setRelation('project', $this->project);
        $issue->setRelation('assignee', null);

        return $issue;
    }

    private function issueUrl(Issue $issue): string
    {
        return route('issues.show', [$this->project, $issue]);
    }

    private function note(Issue $issue, string $body, ?IssueStatus $status = null): IssueNote
    {
        $note = new IssueNote(['body' => $body]);
        $note->forceFill(['status' => $status, 'created_at' => now()->setTime(11, 2)]);
        $note->setRelation('issue', $issue);
        $note->setRelation('user', $this->sofie);
        $note->setRelation('mcpToken', null);

        return $note;
    }

    private function rule(AlertRuleType $type, string $channel = 'Ops'): MailReason
    {
        $rule = new AlertRule(['type' => $type]);
        $rule->forceFill(['team_id' => 1]);

        return MailReason::rule($rule, $channel === 'Ops' ? $this->ops : new NotificationChannel(['name' => $channel]), $this->project);
    }

    private function health(string $state, ?string $message): HealthCheckAlert
    {
        $check = new HealthCheck();
        $check->forceFill(['uuid' => (string) Str::uuid(), 'project_id' => 1, 'environment' => 'production', 'slug' => 'payments-api']);
        $check->setRelation('project', $this->project);

        return new HealthCheckAlert($check, $state, $message, ['web-1', 'web-2'], now()->subMinutes(5)->toImmutable(), route('health.index', ['project' => $this->project, 'check' => $check->uuid]));
    }

    private function invitation(): TeamInvitation
    {
        $invitation = new TeamInvitation(['email' => 'jonas@example.com', 'role' => TeamRole::Member, 'expires_at' => now()->addDays(7)]);
        $invitation->setRelation('team', $this->team);
        $invitation->setRelation('invitedBy', $this->morten);

        return $invitation;
    }
}
