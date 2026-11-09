<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Notifications;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Data\BulkStatusChangeAlert;
use App\Domains\Alerts\Data\CustomAlertMessage;
use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Data\QueueBacklogAlert;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\SendsMailCards;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * An alert that is not about one issue (a health check, a queue backlog, a bulk resolve)
 * as mail. Sent synchronously from the queued alert job, so it is not queued itself.
 */
class AlertSummaryNotification extends Notification
{
    use SendsMailCards;

    /**
     * @param  list<MailReason>  $reasons  why the reader gets it
     */
    public function __construct(
        public readonly Alert $alert,
        public readonly array $reasons = [],
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->cardMail($this->card(), ($this->reasons[0] ?? null)?->isPersonal() ?? false);
    }

    public function card(): MailCard
    {
        return match (true) {
            $this->alert instanceof HealthCheckAlert => $this->healthCard($this->alert),
            $this->alert instanceof QueueBacklogAlert => $this->queueCard($this->alert),
            $this->alert instanceof BulkStatusChangeAlert => $this->bulkCard($this->alert),
            $this->alert instanceof CustomAlertMessage => $this->customCard($this->alert),
            default => $this->genericCard(),
        };
    }

    private function healthCard(HealthCheckAlert $alert): MailCard
    {
        $check = $alert->check;
        $project = $check->project;
        $name = $check->displayName();
        $minutes = max(1, (int) round($alert->since->diffInMinutes(now())));
        $duration = trans_choice('mail.health.minutes', $minutes);
        $state = $alert->state;
        $hosts = $alert->hosts !== [] ? implode(', ', $alert->hosts) : null;
        $tone = match ($state) {
            HealthCheckAlert::DOWN => 'red',
            HealthCheckAlert::RECOVERED => 'green',
            default => 'amber',
        };
        $preheader = __('mail.health.'.$state.'.preheader', ['duration' => $duration])
            .($hosts !== null ? ' '.__('mail.health.on_hosts', ['hosts' => $hosts]) : '')
            .($alert->message !== null && $state !== HealthCheckAlert::RECOVERED ? ': '.$alert->message : '');

        return new MailCard(
            subject: MailCard::subject($project->name, __('mail.health.'.$state.'.subject', ['check' => $name])),
            preheader: $preheader,
            title: $name,
            kind: (string) __('mail.health.'.$state.'.kind'),
            tone: $tone,
            pill: $check->environment !== '' ? (string) __('mail.pill', ['project' => $project->name, 'environment' => $check->environment]) : $project->name,
            lead: (string) __('mail.health.'.$state.'.lead', ['since' => $alert->since->format(__('mail.clock')), 'duration' => $duration]),
            box: $alert->message !== null ? ['main' => $alert->message, 'muted' => null] : null,
            facts: [
                (string) __('alerts.health_check.facts.status') => ['value' => (string) __('alerts.health_check.states.'.$state), 'tone' => $tone],
                (string) __('mail.health.since') => $alert->since->format(__('mail.time')),
                (string) __('alerts.health_check.facts.hosts') => ['value' => $hosts ?? '-', 'mono' => true],
                (string) __('alerts.health_check.facts.environment') => $check->environment !== '' ? $check->environment : '-',
            ],
            button: ['label' => (string) __('mail.health.open', ['check' => $name]), 'url' => $alert->url],
            link: ['label' => (string) __('mail.health.all'), 'url' => route('health.index', $project)],
            reasons: $this->reasons,
        );
    }

    private function queueCard(QueueBacklogAlert $alert): MailCard
    {
        $key = $alert->recovered ? 'recovered' : 'backlog';
        $summary = $alert->summary();
        $oldest = $alert->oldestAgeSeconds !== null ? trans_choice('alerts.queue_backlog.minutes', intdiv($alert->oldestAgeSeconds, 60)) : null;

        return new MailCard(
            subject: MailCard::subject($alert->project->name, __('mail.queue.'.$key.'.subject', ['queue' => $alert->subject()])),
            preheader: $oldest !== null && ! $alert->recovered
                ? (string) __('mail.queue.preheader', ['size' => number_format($alert->size), 'age' => $oldest])
                : implode(' ', $summary['body']),
            title: $alert->subject(),
            kind: (string) __('mail.queue.'.$key.'.kind'),
            tone: $alert->recovered ? 'green' : 'red',
            pill: $alert->project->name,
            lead: implode(' ', $summary['body']),
            facts: $alert->facts(),
            button: ['label' => (string) __('mail.queue.open', ['project' => $alert->project->name]), 'url' => $alert->url],
            reasons: $this->reasons,
        );
    }

    private function bulkCard(BulkStatusChangeAlert $alert): MailCard
    {
        $listed = array_slice($alert->issues, 0, BulkStatusChangeAlert::LISTED);
        $more = count($alert->issues) - count($listed);
        $title = trans_choice('mail.bulk.'.$alert->status->value.'.title', count($alert->issues), ['actor' => $alert->actor]);

        return new MailCard(
            subject: MailCard::subject($alert->project->name, $title),
            preheader: $alert->summary()['subject'],
            title: $title,
            kind: (string) __('mail.bulk.'.$alert->status->value.'.kind'),
            tone: $alert->status === IssueStatus::Resolved ? 'green' : 'gray',
            pill: $alert->project->name,
            rows: array_map(fn (Issue $issue): array => [
                'ref' => $issue->number !== null ? $issue->reference() : null,
                'title' => $issue->title,
                'url' => route('issues.show', [$alert->project, $issue]),
            ], $listed),
            more: $more > 0 ? (string) __('mail.bulk.more', ['count' => $more]) : null,
            button: ['label' => (string) __('mail.bulk.'.$alert->status->value.'.see'), 'url' => $alert->url],
            reasons: $this->reasons,
        );
    }

    private function customCard(CustomAlertMessage $message): MailCard
    {
        $alert = $message->alert;
        $links = $message->links();
        $cell = 'padding:4px 12px 4px 0;text-align:left;border-bottom:1px solid #e2e5e9;';
        $tables = array_map(fn (array $table): string => ($table['title'] !== null ? '<p><strong>'.e($table['title']).'</strong></p>' : '')
            .'<table cellpadding="0" cellspacing="0"><tr>'.implode('', array_map(fn (string $header): string => '<th style="'.$cell.'">'.e($header).'</th>', $table['headers'])).'</tr>'
            .implode('', array_map(fn (array $row): string => '<tr>'.implode('', array_map(fn (string $value): string => '<td style="'.$cell.'">'.e($value).'</td>', $row)).'</tr>', $table['rows'])).'</table>'
            .($table['omitted'] > 0 ? '<p>'.e(trans_choice('alerts.custom.rows_omitted', $table['omitted'])).'</p>' : ''), $message->tables());
        $moreLinks = array_map(fn (array $link): string => '<p><a href="'.e($link['url']).'">'.e($link['label']).'</a></p>', array_slice($links, 2));

        return new MailCard(
            subject: $alert->title,
            preheader: (string) $alert->message,
            title: $alert->title,
            titleUrl: $message->titleUrl(),
            kind: $message->context(),
            tone: $alert->level->tone(),
            lead: $alert->message,
            facts: $message->facts(),
            after: [...$tables, ...$moreLinks] !== [] ? new HtmlString(implode('', [...$tables, ...$moreLinks])) : null,
            button: isset($links[0]) ? ['label' => $links[0]['label'], 'url' => $links[0]['url']] : null,
            link: isset($links[1]) ? ['label' => $links[1]['label'], 'url' => $links[1]['url']] : null,
            reasons: $this->reasons,
        );
    }

    private function genericCard(): MailCard
    {
        ['title' => $title, 'subject' => $subject, 'body' => $body] = $this->alert->summary();

        return new MailCard(
            subject: $title.': '.$subject,
            preheader: $body !== [] ? implode(' ', $body) : $subject,
            title: $subject,
            kind: $title,
            lead: $body !== [] ? implode(' ', $body) : null,
            facts: $this->alert->facts(),
            button: ['label' => (string) __('alerts.open_issue'), 'url' => $this->alert->url()],
            reasons: $this->reasons,
        );
    }
}
