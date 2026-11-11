<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Override;

/**
 * The past week of every project routed to one channel, each against the week before.
 * Chat and push channels get one line per project from summary(); mail and webhooks
 * the whole of $projects (WeeklySummaryQuery::get()).
 */
final readonly class WeeklySummaryAlert implements Alert
{
    /**
     * @param  list<array<string, mixed>>  $projects
     */
    public function __construct(
        public array $projects,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $url,
    ) {
    }

    #[Override]
    public function url(): string
    {
        return $this->url;
    }

    #[Override]
    public function headline(): string
    {
        return (string) __('alerts.weekly_summary.title');
    }

    #[Override]
    public function summary(): array
    {
        return [
            'title' => $this->headline(),
            'subject' => $this->period(),
            'body' => array_map(fn (array $project): string => $project['project']['name'].': '.self::line($project), $this->projects),
        ];
    }

    #[Override]
    public function facts(): array
    {
        return [];
    }

    #[Override]
    public function urgency(): string
    {
        return 'low';
    }

    #[Override]
    public function maintenance(): ?MaintenanceWindow
    {
        return null;
    }

    /**
     * "4 Nov - 11 Nov".
     */
    public function period(): string
    {
        return (string) __('alerts.weekly_summary.period', ['from' => $this->from->format(__('mail.weekly.date')), 'to' => $this->to->format(__('mail.weekly.date'))]);
    }

    /**
     * "12,345 requests (+5%), 0.4% errors, p95 230 ms, 3 new issues, 1 regression".
     *
     * @param  array<string, mixed>  $project
     */
    public static function line(array $project): string
    {
        $requests = (int) $project['requests']['current'];
        $change = self::change($project['requests']['current'], $project['requests']['previous']);

        return implode(', ', array_filter([
            trans_choice('alerts.weekly_summary.requests', $requests, ['requests' => number_format($requests)]).($change !== null ? ' ('.$change.')' : ''),
            $requests > 0 ? (string) __('alerts.weekly_summary.error_rate', ['rate' => self::percent($project['error_rate']['current'])]) : null,
            $project['p95_ms']['current'] !== null && $requests > 0 ? (string) __('alerts.weekly_summary.p95', ['ms' => number_format((float) $project['p95_ms']['current'])]) : null,
            trans_choice('alerts.weekly_summary.new_issues', (int) $project['new_issues']['current']),
            $project['regressions']['current'] > 0 ? trans_choice('alerts.weekly_summary.regressions', (int) $project['regressions']['current']) : null,
            $project['outages']['current'] > 0 ? trans_choice('alerts.weekly_summary.outages', (int) $project['outages']['current']) : null,
            $project['failed_jobs']['current'] > 0 ? trans_choice('alerts.weekly_summary.failed_jobs', (int) $project['failed_jobs']['current']) : null,
        ]));
    }

    /**
     * "+5%" or "-12%" against the week before; null without one to compare with.
     */
    public static function change(int|float|null $current, int|float|null $previous): ?string
    {
        if ($current === null || $previous === null || $previous == 0) {
            return null;
        }

        return sprintf('%+d%%', (int) round(($current / $previous - 1) * 100));
    }

    /**
     * "0.42%", or "-" for no figure.
     */
    public static function percent(int|float|null $share): string
    {
        return $share === null ? '-' : rtrim(rtrim(number_format($share * 100, 2), '0'), '.').'%';
    }
}
