<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendHealthCheckAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Health\Models\HealthCheckResult;
use App\Domains\Health\Models\HealthOutage;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one place a result moves a check: the host's streaks decide its status (degraded
 * after `degrade_after` bad results in a row, down after `down_after` failed ones,
 * recovering on the first good result after that and ok again after `recover_after`
 * good ones), the check takes the worst of its hosts, an outage opens or closes, and
 * the health check rule is told once per change. Recovering keeps the outage open and
 * sends nothing; a bad result while recovering goes back to where it was.
 */
class RecordHealthResultAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    /**
     * @param  bool  $announce  false when the caller forgets the cached states and broadcasts itself, once for many results
     */
    public function execute(HealthCheck $check, HealthResultData $result, bool $announce = true): HealthCheckResult
    {
        $at = $result->at ?? now()->toImmutable();

        [$row, $alert] = DB::transaction(function () use ($check, $result, $at): array {
            // Several hosts report the same slug at the same minute; one at a time.
            $check = HealthCheck::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();

            $host = HealthCheckHost::query()->firstOrNew(['health_check_id' => $check->id, 'host' => $result->host]);
            $this->advance($check, $host, $result, $at);
            $host->save();

            $row = HealthCheckResult::query()->create([
                'health_check_id' => $check->id,
                'project_id' => $check->project_id,
                'host' => $result->host,
                'status' => $result->status,
                'message' => $result->message,
                'metrics' => $result->metrics !== [] ? $result->metrics : null,
                'duration_us' => $result->durationUs,
                'checked_at' => $at,
            ]);

            $hosts = $check->hosts()->get();
            $live = $hosts->reject(fn (HealthCheckHost $h): bool => $h->id !== $host->id && $check->isReported() && $h->isStale());
            $status = HealthStatus::worst($live->map(fn (HealthCheckHost $h): ?HealthStatus => $h->status));

            $this->trackOutage($check, $status, $result, $at);

            if ($status !== $check->status) {
                $check->status = $status;
                $check->status_changed_at = $at;
            }

            $check->last_result_at = $check->last_result_at === null || $at->gt($check->last_result_at) ? $at : $check->last_result_at;
            $alert = $this->alert($check, $status, $result, array_values(array_map(fn (HealthCheckHost $h): string => $h->host, $live->filter(fn (HealthCheckHost $h): bool => $h->status?->worseThan(HealthStatus::Recovering) === true && $h->host !== '')->all())));
            $check->save();

            return [$row, $alert];
        });

        if ($alert !== null) {
            SendHealthCheckAlert::dispatch(...$alert);
        }

        if ($announce) {
            HealthChecksQuery::forgetStates($check->project_id);
            $this->broadcast->execute($check->project_id, ChangeKind::Health);
        }

        return $row;
    }

    private function advance(HealthCheck $check, HealthCheckHost $host, HealthResultData $result, CarbonImmutable $at): void
    {
        // The usual spacing of results, smoothed, so staleness follows the app's own schedule.
        if ($host->last_result_at !== null && $at->gt($host->last_result_at)) {
            $spacing = (int) max(10, min(86_400, $host->last_result_at->diffInSeconds($at)));
            $host->interval_s = $host->interval_s === null ? $spacing : (int) round(0.7 * $host->interval_s + 0.3 * $spacing);
        }

        if ($result->status === HealthResultStatus::Ok) {
            $host->ok_streak++;
            $host->fail_streak = 0;
            $host->failed_streak = 0;

            if ($host->status === null || $host->ok_streak >= $check->recover_after) {
                $host->status = HealthStatus::Ok;
                $host->recovering_from = null;
            } elseif ($host->status === HealthStatus::Degraded || $host->status === HealthStatus::Down) {
                $host->recovering_from = $host->status;
                $host->status = HealthStatus::Recovering;
            }
        } else {
            $failed = $result->status === HealthResultStatus::Failed;
            $host->fail_streak++;
            $host->failed_streak = $failed ? $host->failed_streak + 1 : 0;
            $host->ok_streak = 0;

            // The bad streak restarts at one, so it goes back to where it was rather than dropping to degraded.
            if ($host->status === HealthStatus::Recovering) {
                $host->status = $host->recovering_from ?? HealthStatus::Degraded;
                $host->recovering_from = null;
            }

            // A warning is not ok, but it only degrades; only failures in a row take it down, and keep it there.
            if ($host->failed_streak >= $check->downAfter() || ($failed && $host->status === HealthStatus::Down)) {
                $host->status = HealthStatus::Down;
            } elseif ($host->fail_streak >= $check->degrade_after) {
                $host->status = HealthStatus::Degraded;
            }
        }

        $host->fill([
            'last_status' => $result->status,
            'last_message' => $result->message,
            'last_duration_us' => $result->durationUs,
            'last_metrics' => $result->metrics,
            'last_result_at' => $at,
        ]);
    }

    private function trackOutage(HealthCheck $check, ?HealthStatus $status, HealthResultData $result, CarbonImmutable $at): void
    {
        $bad = $status !== null && $status !== HealthStatus::Ok;
        $open = HealthOutage::query()->where('health_check_id', $check->id)->whereNull('ended_at')->latest('started_at')->first();

        // Recovering keeps an open outage open, at its worst status; it never opens one.
        if ($bad && $open === null && $status !== HealthStatus::Recovering) {
            HealthOutage::query()->create(['health_check_id' => $check->id, 'project_id' => $check->project_id, 'status' => $status, 'first_message' => $result->message, 'started_at' => $at]);
        } elseif ($bad && $open !== null && $status->worseThan($open->status)) {
            $open->update(['status' => $status]);
        } elseif (! $bad && $open !== null && $status === HealthStatus::Ok) {
            $open->update(['ended_at' => $at]);
        }
    }

    /**
     * What to tell the rule, if anything: a check getting worse than what was last sent,
     * or one that was reported bad and is ok again. Each step for the worse is told at
     * once (ok to degraded, degraded to down); one back from recovering to the state it
     * was already told about stays quiet.
     *
     * @param  list<string>  $hosts
     * @return array{AlertRule, HealthCheck, string, ?string, list<string>, string}|null
     */
    private function alert(HealthCheck $check, ?HealthStatus $status, HealthResultData $result, array $hosts): ?array
    {
        // Recovering is not news; only reaching ok again is.
        if ($check->muted || $check->archived_at !== null || $status === null || $status === HealthStatus::Recovering) {
            return null;
        }

        $since = ($check->status_changed_at ?? now())->toIso8601String();

        // What happened is worked out whether or not a rule listens, so turning one of
        // the three rules off never confuses what the others are told later.
        if ($status === HealthStatus::Ok) {
            if ($check->alerted_status === null) {
                return null;
            }

            $check->alerted_status = null;
            [$type, $kind, $hosts, $since] = [AlertRuleType::HealthRecovered, HealthCheckAlert::RECOVERED, [], ($check->alerted_at ?? now())->toIso8601String()];
        } else {
            // Every step for the worse is told at once: ok to degraded, degraded to down.
            // The thresholds already keep a flapping check quiet; a time window on top
            // would hide a real failure.
            if (! $status->worseThan($check->alerted_status)) {
                return null;
            }

            $check->alerted_status = $status;
            $check->alerted_at = now();
            [$type, $kind] = $status === HealthStatus::Down
                ? [AlertRuleType::HealthDown, HealthCheckAlert::DOWN]
                : [AlertRuleType::HealthDegraded, HealthCheckAlert::DEGRADED];
        }

        $rule = AlertRule::query()
            ->whereEffectiveFor($check->project)
            ->whereType($type)
            ->whereEnabled()
            ->first();

        if ($rule === null || $rule->channelsFor($check->project)->isEmpty() || ! $rule->appliesToEnvironment($check->environment !== '' ? $check->environment : null)) {
            return null;
        }

        return [$rule, $check, $kind, $result->message, $hosts, $since];
    }
}
