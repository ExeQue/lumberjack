<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\Health\Enums\HealthCheckSource;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Projects\Models\Project;

/**
 * Adds or edits a check Lumberjack runs, or edits how a reported one is shown and
 * alerted on. A reported check with all thresholds left empty goes back to the ones
 * the app sends.
 */
class SaveHealthCheckAction
{
    /**
     * @param  array<string, mixed>  $attributes  validated
     * @param  array<string, mixed>  $config  the type's settings
     */
    public function execute(Project $project, ?HealthCheck $check, array $attributes, array $config = []): HealthCheck
    {
        $check ??= new HealthCheck([
            'project_id' => $project->id,
            'source' => HealthCheckSource::Monitored,
            'type' => HealthCheckType::from((string) $attributes['type']),
        ]);

        $check->fill([
            'name' => $attributes['name'] ?? null,
            'description' => $attributes['description'] ?? null,
            'muted' => (bool) ($attributes['muted'] ?? false),
        ]);

        $degrade = $attributes['degrade_after'] ?? null;
        $recover = $attributes['recover_after'] ?? null;
        $down = $attributes['down_after'] ?? null;

        if ($check->isReported()) {
            $check->thresholds_overridden = $degrade !== null || $recover !== null || $down !== null;
            $check->degrade_after = (int) ($degrade ?? $check->degrade_after);
            $check->recover_after = (int) ($recover ?? $check->recover_after);
            $check->down_after = $down !== null ? (int) $down : $check->down_after;
            $check->save();

            return $check;
        }

        $check->fill([
            'environment' => (string) ($attributes['environment'] ?? ''),
            'interval_minutes' => (int) $attributes['interval_minutes'],
            'degrade_after' => (int) $degrade,
            'recover_after' => (int) $recover,
            'down_after' => (int) $down,
            'config' => $config,
            // Runs on the next minute, so a new or changed check shows a result at once.
            'next_run_at' => null,
        ]);

        if ($check->type === HealthCheckType::Heartbeat && $check->ping_token === null) {
            $check->ping_token = bin2hex(random_bytes(20));
        }

        $check->save();

        return $check;
    }
}
