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
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one place a result moves a check: the host's streaks decide its status (down or
 * degraded after `degrade_after` bad results in a row, ok again after `recover_after`
 * good ones), the check takes the worst of its hosts, an outage opens or closes, and
 * the health check rule is told once per change.
 */
class RecordHealthResultAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    public function execute(HealthCheck $check, HealthResultData $result): HealthCheckResult
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
            $alert = $this->alert($check, $status, $result, array_values(array_map(fn (HealthCheckHost $h): string => $h->host, $live->filter(fn (HealthCheckHost $h): bool => $h->status !== null && $h->status !== HealthStatus::Ok && $h->host !== '')->all())));
            $check->save();

            return [$row, $alert];
        });

        if ($alert !== null) {
            SendHealthCheckAlert::dispatch(...$alert);
        }

        $this->broadcast->execute($check->project_id, ChangeKind::Health);

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

            if ($host->status === null || $host->ok_streak >= $check->recover_after) {
                $host->status = HealthStatus::Ok;
            }
        } else {
            $host->fail_streak++;
            $host->ok_streak = 0;

            // A warning is not ok, but it degrades; only a failure takes it down.
            if ($host->fail_streak >= $check->degrade_after) {
                $host->status = $result->status === HealthResultStatus::Failed ? HealthStatus::Down : HealthStatus::Degraded;
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

        if ($bad && $open === null) {
            HealthOutage::query()->create(['health_check_id' => $check->id, 'project_id' => $check->project_id, 'status' => $status, 'first_message' => $result->message, 'started_at' => $at]);
        } elseif ($bad && $status->worseThan($open->status)) {
            $open->update(['status' => $status]);
        } elseif (! $bad && $open !== null && $status === HealthStatus::Ok) {
            $open->update(['ended_at' => $at]);
        }
    }

    /**
     * What to tell the rule, if anything: a check getting worse than what was last sent,
     * or one that was reported bad and is ok again. A check that turns bad again within
     * the throttle window of its last alert stays quiet; one already told about may
     * still escalate from degraded to down.
     *
     * @param  list<string>  $hosts
     * @return array{AlertRule, HealthCheck, string, ?string, list<string>, string}|null
     */
    private function alert(HealthCheck $check, ?HealthStatus $status, HealthResultData $result, array $hosts): ?array
    {
        if ($check->muted || $check->archived_at !== null || $status === null) {
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
            $throttled = $check->alerted_status === null && $check->alerted_at?->gt(now()->subMinutes(HealthCheck::ALERT_THROTTLE_MINUTES));

            if (! $status->worseThan($check->alerted_status) || $throttled) {
                return null;
            }

            $check->alerted_status = $status;
            $check->alerted_at = now();
            [$type, $kind] = $status === HealthStatus::Down
                ? [AlertRuleType::HealthDown, HealthCheckAlert::DOWN]
                : [AlertRuleType::HealthDegraded, HealthCheckAlert::DEGRADED];
        }

        $rule = AlertRule::query()
            ->where('project_id', $check->project_id)
            ->where('type', $type->value)
            ->where('enabled', true)
            ->whereHas('channels')
            ->first();

        if ($rule === null || ! $rule->appliesToEnvironment($check->environment !== '' ? $check->environment : null)) {
            return null;
        }

        return [$rule, $check, $kind, $result->message, $hosts, $since];
    }
}
