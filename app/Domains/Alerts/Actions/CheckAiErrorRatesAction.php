<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Alerts\Data\AiErrorRateSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendAiErrorRateAlert;
use App\Domains\Alerts\Models\AiErrorRate;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Runs every minute: per key, the share of its calls in the rule's window (and the
 * rule's environments) that failed. Alerts once when a key goes over, once when it is back
 * under (or quiet), and starts again at most once per throttle window, as queue backlogs
 * do. Any maintenance window of the project mutes them all.
 */
class CheckAiErrorRatesAction
{
    /** The longest window a rule can look back over, in minutes. */
    public const int MAX_MINUTES = 1440;

    /**
     * @return int alerts queued
     */
    public function execute(): int
    {
        $queued = 0;

        $projects = Project::query()
            ->whereIn('id', AiKey::query()->whereSeenSince(now()->subMinutes(self::MAX_MINUTES))->select('project_id'))
            ->orWhereIn('id', AiErrorRate::query()->select('project_id'))
            ->get();

        foreach ($projects as $project) {
            $rule = AlertRule::query()->whereEffectiveFor($project)->whereType(AlertRuleType::AiErrorRate)->whereEnabled()->first();

            // Where another rule (or one since turned off) stood is no longer this project's.
            if ($rule === null) {
                AiErrorRate::query()->whereProject($project)->delete();

                continue;
            }

            AiErrorRate::query()->whereProject($project)->whereNotRule($rule)->delete();

            $states = AiErrorRate::query()->whereRule($rule)->whereProject($project)->get()->keyBy('fingerprint');

            if (MaintenanceWindow::query()->whereProject($project)->whereExcusing()->exists()) {
                AiErrorRate::query()->whereKey($states->modelKeys())->delete();

                continue;
            }

            $settings = $rule->aiErrorRateSettings();
            $counts = $this->counts($project, $settings, $rule->environments ?: null);

            foreach (array_unique([...array_keys($counts), ...$states->keys()->all()]) as $fingerprint) {
                $fingerprint = (string) $fingerprint;
                [$calls, $errors] = $counts[$fingerprint] ?? [0, 0];
                $state = $states->get($fingerprint)
                    ?? new AiErrorRate(['alert_rule_id' => $rule->id, 'project_id' => $project->id, 'fingerprint' => $fingerprint]);

                if ($settings->breached($calls, $errors)) {
                    $state->breaching_since ??= now();
                    $throttled = $state->last_fired_at !== null && $state->last_fired_at->greaterThan(now()->subMinutes(AlertRule::THROTTLE_MINUTES));

                    if ($state->fired_at === null && ! $throttled) {
                        $state->fired_at = $state->last_fired_at = now();
                        SendAiErrorRateAlert::dispatch($rule, $project, $fingerprint, $calls, $errors, $state->breaching_since->toIso8601String(), false)->afterCommit();
                        $queued++;
                    }
                } else {
                    if ($state->fired_at !== null) {
                        SendAiErrorRateAlert::dispatch($rule, $project, $fingerprint, $calls, $errors, ($state->breaching_since ?? $state->fired_at)->toIso8601String(), true)->afterCommit();
                        $queued++;
                    }

                    $state->breaching_since = $state->fired_at = null;
                }

                $throttling = $state->last_fired_at !== null && $state->last_fired_at->greaterThan(now()->subMinutes(AlertRule::THROTTLE_MINUTES));

                // A key back to normal needs its row only while it holds back the next alert.
                if ($state->breaching_since === null && ! $throttling) {
                    if ($state->exists) {
                        $state->delete();
                    }
                } else {
                    $state->save();
                }
            }
        }

        return $queued;
    }

    /**
     * Calls and failed calls per key in the rule's window. A window of an hour or more is
     * counted from the hourly totals for its whole hours, and from the calls themselves for
     * the parts of an hour at either end, through the covering (project, occurred_at) index.
     *
     * @param  list<string>|null  $environments  null for all
     * @return array<string, array{int, int}>
     */
    private function counts(Project $project, AiErrorRateSettings $settings, ?array $environments): array
    {
        $now = now();
        $from = $now->subMinutes(min($settings->minutes, self::MAX_MINUTES));
        // Whole hours in the window: from the first hour starting in it to the current one.
        [$hoursFrom, $hoursUntil] = $settings->minutes >= 60
            ? [$from->equalTo($from->startOfHour()) ? $from : $from->startOfHour()->addHour(), $now->startOfHour()]
            : [$now, $now];
        $counts = [];

        $calls = DB::table('ai_calls')
            ->where('project_id', $project->id)
            ->where(fn ($query) => $query
                ->where(fn ($edge) => $edge->where('occurred_at', '>=', $from)->where('occurred_at', '<', $hoursFrom))
                ->orWhere('occurred_at', '>=', $hoursUntil))
            ->whereNotNull('fingerprint')
            ->when($environments !== null)->whereIn('environment', $environments ?? [])
            ->groupBy('fingerprint')
            ->selectRaw('fingerprint, COUNT(*) AS calls, COUNT(*) FILTER (WHERE status = ?) AS errors', [AiCallStatus::Error->value]);

        $hours = DB::table('ai_usage_hourly')
            ->where('project_id', $project->id)
            ->where('hour', '>=', $hoursFrom)
            ->where('hour', '<', $hoursUntil)
            ->where('fingerprint', '!=', '')
            ->when($environments !== null)->whereIn('environment', $environments ?? [])
            ->groupBy('fingerprint')
            ->selectRaw('fingerprint, SUM(calls) AS calls, SUM(errors) AS errors');

        foreach ([...$calls->get(), ...($hoursFrom < $hoursUntil ? $hours->get() : [])] as $row) {
            [$total, $failed] = $counts[(string) $row->fingerprint] ?? [0, 0];
            $counts[(string) $row->fingerprint] = [$total + (int) $row->calls, $failed + (int) $row->errors];
        }

        return $counts;
    }
}
