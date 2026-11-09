<?php

declare(strict_types=1);

namespace App\Domains\Releases\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendReleaseAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Writes down a release the first time an environment reports it, and alerts the
 * channels of the new release rule. An environment's very first release is written down
 * quietly: it is whatever was running when this started counting, not a deploy.
 */
class RecordReleaseEnvironmentAction
{
    /** Batches of a release keep coming; one cache read answers them. */
    private const int REMEMBER_SECONDS = 86_400;

    public function execute(Project $project, string $release, ?string $environment, ?string $host): void
    {
        $key = 'release-environment-seen:'.$project->id.':'.hash('xxh128', $release."\0".$environment);

        if (! Cache::add($key, true, self::REMEMBER_SECONDS)) {
            return;
        }

        $rows = DB::table('project_release_environments')->where('project_id', $project->id)->where('environment', (string) $environment);
        $first = $rows->doesntExist();
        $inserted = DB::table('project_release_environments')->insertOrIgnore([
            'project_id' => $project->id,
            'release' => $release,
            'environment' => (string) $environment,
            'first_seen_at' => now(),
        ]);

        if ($inserted === 0 || $first) {
            return;
        }

        $rule = AlertRule::query()->whereEffectiveFor($project)->whereType(AlertRuleType::NewRelease)->whereEnabled()->first();

        if ($rule?->appliesToEnvironment($environment)) {
            SendReleaseAlert::dispatch($rule, $project, $release, $environment, $host);
        }
    }
}
