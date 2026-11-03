<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthCheckSource;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckResult;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Projects\Models\Project;

/**
 * One health event from the SDK: the check is found by environment and slug (made on
 * first sight), takes the thresholds the app sends unless someone set its own in the
 * UI, and comes back from the archive when it reports again.
 */
class RecordReportedHealthAction
{
    public const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9:._-]{0,99}$/';

    public const int MAX_THRESHOLD = 1000;

    public function __construct(
        private readonly RecordHealthResultAction $recordHealthResultAction,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function execute(Project $project, array $event, Envelope $envelope): ?HealthCheckResult
    {
        $slug = is_string($event['slug'] ?? null) ? $event['slug'] : '';
        $result = HealthResultData::fromEvent($event, $envelope);

        if ($result === null || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        $identity = ['project_id' => $project->id, 'environment' => $envelope->environment ?? '', 'slug' => $slug];

        // firstOrCreate falls back to createOrFirst, so two hosts making it at once is fine.
        $check = HealthCheck::query()->firstOrCreate($identity, ['source' => HealthCheckSource::Reported]);

        if (! $check->thresholds_overridden) {
            $check->degrade_after = self::threshold($event['degrade_after'] ?? null, HealthCheck::DEFAULT_DEGRADE_AFTER);
            $check->recover_after = self::threshold($event['recover_after'] ?? null, HealthCheck::DEFAULT_RECOVER_AFTER);
            $check->down_after = self::threshold($event['down_after'] ?? null, HealthCheck::DEFAULT_DOWN_AFTER);
        }

        $check->archived_at = null;
        $check->save();

        return $this->recordHealthResultAction->execute($check, $result);
    }

    private static function threshold(mixed $value, int $default): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? max(1, min(self::MAX_THRESHOLD, (int) $value)) : $default;
    }
}
