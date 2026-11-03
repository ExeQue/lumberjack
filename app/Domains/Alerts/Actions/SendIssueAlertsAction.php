<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Drivers\MailDriver;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\AlertSend;
use App\Domains\Alerts\Support\ChannelDriverRegistry;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Shared\Mail\MailReason;
use Throwable;

/**
 * Sends one alert per enabled rule and channel, at most once per issue, rule and
 * channel within the throttle window (regressions always). Ignored issues never alert.
 */
class SendIssueAlertsAction
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
    ) {
    }

    /**
     * @param  int|null  $burst  for an escalation: occurrences in the escalation window
     */
    public function execute(Issue $issue, AlertRuleType $type, ?string $environment, ?int $burst = null): int
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

        // A regression only follows someone resolving the issue, so each one is news; the
        // throttle is for escalations and the like that can repeat by themselves.
        return $this->deliver($issue, $type, $environment, $alert, throttle: $type !== AlertRuleType::Regression);
    }

    /**
     * A note someone wrote on an issue, sent to the channels of the project's note rule.
     * Every note goes out, unthrottled and whatever the issue's status: writing one is deliberate.
     */
    public function executeForNote(IssueNote $note): int
    {
        $issue = $note->issue;

        if ($issue === null) {
            return 0;
        }

        // A comment given on resolving or ignoring goes out with the status-change alert instead.
        if ($note->status !== null && AlertRule::query()->whereEffectiveFor($issue->project)->whereType(AlertRuleType::StatusChange)->whereEnabled()->exists()) {
            return 0;
        }

        $environment = $issue->occurrences()->latest('occurred_at')->value('environment');
        $environment = is_string($environment) ? $environment : null;
        $alert = new IssueAlert($issue, AlertRuleType::Note, $environment, route('issues.show', [$issue->project, $issue]), note: $note);

        return $this->deliver($issue, AlertRuleType::Note, $environment, $alert, throttle: false);
    }

    /**
     * Someone resolved or ignored the issue, with their comment if they gave one.
     * Sent unthrottled, like notes: it is a deliberate action.
     */
    public function executeForStatusChange(Issue $issue, IssueStatus $status, string $actor, ?IssueNote $note = null): int
    {
        $environment = $issue->occurrences()->latest('occurred_at')->value('environment');
        $environment = is_string($environment) ? $environment : null;
        $alert = new IssueAlert($issue, AlertRuleType::StatusChange, $environment, route('issues.show', [$issue->project, $issue]), note: $note, statusChange: $status, actor: $actor);

        return $this->deliver($issue, AlertRuleType::StatusChange, $environment, $alert, throttle: false);
    }

    private function deliver(Issue $issue, AlertRuleType $type, ?string $environment, IssueAlert $alert, bool $throttle): int
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

                $error = null;

                try {
                    $driver = $this->drivers->for($channel->type);
                    $driver instanceof MailDriver
                        ? $driver->sendWithReasons($channel, $alert, [MailReason::rule($rule, $channel, $issue->project)])
                        : $driver->send($channel, $alert);
                    $sent++;
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
            }
        }

        return $sent;
    }
}
