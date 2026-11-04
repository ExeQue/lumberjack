<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Drivers\MailDriver;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\AlertSend;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Support\ChannelDriverRegistry;
use App\Domains\Issues\Actions\NotifyWatchersAction;
use App\Domains\Issues\Data\WatchUpdate;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Support\NoteMentions;
use App\Domains\Notifications\Actions\NotifySurfacedIssueAction;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\MailRecipients;
use App\Models\User;
use Closure;
use Throwable;

/**
 * Sends one alert per enabled rule and channel, at most once per issue, rule and
 * channel within the throttle window (regressions always). Ignored issues never alert.
 */
class SendIssueAlertsAction
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
        private readonly NotifyWatchersAction $watchers,
        private readonly NoteMentions $mentions,
        private readonly NotifySurfacedIssueAction $notifySurfacedIssueAction,
    ) {
    }

    /**
     * @param  int|null  $burst  for an escalation: occurrences in the escalation window
     * @param  MailRecipients|null  $recipients  the event's mail recipients; none sends right away
     */
    public function execute(Issue $issue, AlertRuleType $type, ?string $environment, ?int $burst = null, ?MailRecipients $recipients = null): int
    {
        // Browser errors are too noisy to page anyone; they stay on the issues list.
        if ($issue->status === IssueStatus::Ignored || $issue->source === IssueSource::Browser) {
            return 0;
        }

        // The latest note someone wrote about the issue, not the comment left when resolving
        // or ignoring it ("won't fix"): on a regression that would read as if it were news.
        $note = $issue->notes()->with(['user', 'mcpToken'])->whereNull('status')->latest('id')->first();
        $summary = $note !== null ? strtok($note->body, "\n") : null;
        $author = match (true) {
            $note?->mcpToken !== null => (string) __('alerts.messages.via_mcp', ['token' => $note->mcpToken->name]),
            default => $note?->user?->name,
        };
        $resolved = $type === AlertRuleType::Regression
            ? IssueEvent::query()->with(['user', 'mcpToken'])->where('issue_id', $issue->id)
                ->where('type', IssueEventType::StatusChanged)->where('data->to', IssueStatus::Resolved->value)
                ->latest('occurred_at')->first()
            : null;
        $alert = new IssueAlert(
            $issue,
            $type,
            $environment,
            route('issues.show', [$issue->project, $issue]),
            $summary ?: null,
            burst: $burst,
            triageAuthor: $author,
            resolvedBy: $resolved?->mcpToken !== null ? (string) __('alerts.messages.via_mcp', ['token' => $resolved->mcpToken->name]) : $resolved?->user?->name,
            resolvedAt: $resolved?->occurred_at,
        );

        $own = $recipients === null;
        $recipients ??= new MailRecipients();

        // Members who want it by push hear of it whether or not the rule has channels.
        $this->notifySurfacedIssueAction->execute($issue, $type, $environment);

        // Watchers hear of a regression or an escalation right away, not grouped.
        if ($type === AlertRuleType::Regression || $type === AlertRuleType::Escalation) {
            $kind = $type === AlertRuleType::Regression ? WatchUpdate::REGRESSION : WatchUpdate::ESCALATION;
            $this->watchers->execute([new WatchUpdate($issue, $kind, (string) __('alerts.messages.unknown_author'), alert: $alert)], null, $recipients);
        }

        // A regression only follows someone resolving the issue, so each one is news; the
        // throttle is for escalations and the like that can repeat by themselves.
        return $this->deliver($issue, $type, $environment, $alert, $type !== AlertRuleType::Regression, $recipients, $own);
    }

    /**
     * A note someone wrote on an issue, sent to the channels of the project's note rule.
     * Every note goes out, unthrottled and whatever the issue's status: writing one is deliberate.
     */
    public function executeForNote(IssueNote $note, ?MailRecipients $recipients = null): int
    {
        $issue = $note->issue;

        if ($issue === null) {
            return 0;
        }

        $own = $recipients === null;
        $recipients ??= new MailRecipients();

        // A comment given on resolving or ignoring reaches watchers with the status change.
        if ($note->status === null) {
            $note->loadMissing(['user', 'mcpToken']);
            $this->watchers->execute(
                [new WatchUpdate($issue, WatchUpdate::NOTE, $this->mentions->author($note), body: $this->mentions->render($note->body, $issue->project->team), at: $note->created_at)],
                $note->mcp_token_id === null ? $note->user : null,
                $recipients,
            );
        }

        // A comment given on resolving or ignoring goes out with the status-change alert instead.
        if ($note->status !== null && AlertRule::query()->whereEffectiveFor($issue->project)->whereType(AlertRuleType::StatusChange)->whereEnabled()->exists()) {
            if ($own) {
                $recipients->send();
            }

            return 0;
        }

        $environment = $issue->occurrences()->latest('occurred_at')->value('environment');
        $environment = is_string($environment) ? $environment : null;
        $alert = new IssueAlert($issue, AlertRuleType::Note, $environment, route('issues.show', [$issue->project, $issue]), note: $note);

        return $this->deliver($issue, AlertRuleType::Note, $environment, $alert, false, $recipients, $own);
    }

    /**
     * Someone changed the issue's status, with their comment if they gave one. Watchers
     * hear of every change; the rule's channels of resolving and ignoring. Sent
     * unthrottled, like notes: it is a deliberate action.
     *
     * @param  User|null  $by  who did it, who then does not hear of it as a watcher
     */
    public function executeForStatusChange(Issue $issue, IssueStatus $status, string $actor, ?IssueNote $note = null, ?MailRecipients $recipients = null, ?User $by = null): int
    {
        $own = $recipients === null;
        $recipients ??= new MailRecipients();
        $body = $note !== null ? $this->mentions->render($note->body, $issue->project->team) : null;
        $this->watchers->execute([new WatchUpdate($issue, WatchUpdate::STATUS, $actor, ['status' => $status->value], $body)], $by, $recipients);

        if ($status !== IssueStatus::Resolved && $status !== IssueStatus::Ignored) {
            if ($own) {
                $recipients->send();
            }

            return 0;
        }

        $environment = $issue->occurrences()->latest('occurred_at')->value('environment');
        $environment = is_string($environment) ? $environment : null;
        $alert = new IssueAlert($issue, AlertRuleType::StatusChange, $environment, route('issues.show', [$issue->project, $issue]), note: $note, statusChange: $status, actor: $actor);

        return $this->deliver($issue, AlertRuleType::StatusChange, $environment, $alert, false, $recipients, $own);
    }

    /**
     * Chat channels get the alert right away; a mail channel offers it to the event's
     * recipients, which send it unless the address hears about this event another way.
     * When this call is the whole event ($own), it sends the event's mails too.
     *
     * @return int channels reached, mail channels counted once sent
     */
    private function deliver(Issue $issue, AlertRuleType $type, ?string $environment, IssueAlert $alert, bool $throttle, MailRecipients $recipients, bool $own): int
    {
        $sent = 0;

        $rules = AlertRule::query()
            ->whereEffectiveFor($issue->project)
            ->whereType($type)
            ->whereEnabled()
            ->with('channels')
            ->get();

        foreach ($rules as $rule) {
            if (! $rule->appliesToEnvironment($environment)) {
                continue;
            }

            foreach ($rule->channelsFor($issue->project) as $channel) {
                $recent = $throttle && AlertSend::query()
                    ->where('alert_rule_id', $rule->id)
                    ->where('issue_id', $issue->id)
                    ->where('notification_channel_id', $channel->id)
                    ->where('sent_at', '>=', now()->subMinutes(AlertRule::THROTTLE_MINUTES))
                    ->exists();

                if ($recent) {
                    continue;
                }

                $driver = $this->drivers->for($channel->type);

                if ($driver instanceof MailDriver) {
                    $recipients->offer(MailDriver::address($channel), MailRecipients::CHANNEL, MailReason::rule($rule, $channel, $issue->project), function (array $reasons) use ($driver, $channel, $alert, $rule, $issue, &$sent): void {
                        $sent += $this->record($rule, $issue, $channel, fn () => $driver->sendWithReasons($channel, $alert, $reasons));
                    });

                    continue;
                }

                $sent += $this->record($rule, $issue, $channel, fn () => $driver->send($channel, $alert));
            }
        }

        if ($own) {
            $recipients->send();
        }

        return $sent;
    }

    /**
     * Sends to one channel and writes down how it went.
     *
     * @return int 1 when it went out
     */
    private function record(AlertRule $rule, Issue $issue, NotificationChannel $channel, Closure $send): int
    {
        $error = null;

        try {
            $send();
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        AlertSend::create([
            'alert_rule_id' => $rule->id,
            'issue_id' => $issue->id,
            'notification_channel_id' => $channel->id,
            'status' => $error === null ? 'sent' : 'failed',
            'error' => $error,
            'sent_at' => now(),
        ]);

        $channel->forceFill(['last_delivery_status' => $error === null ? 'sent' : 'failed', 'last_delivery_at' => now()])->save();

        return $error === null ? 1 : 0;
    }
}
