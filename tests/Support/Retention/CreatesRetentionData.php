<?php

declare(strict_types=1);

namespace Tests\Support\Retention;

use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityCount;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\CustomAlert;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Commands\Models\CommandRun;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows of every retention data type, and the stored policies, for the retention tests.
 */
trait CreatesRetentionData
{
    /**
     * One row of the data type, aged to the given moment.
     */
    public function seedRetentionRow(RetentionCategory $category, Project $project, CarbonInterface $at): void
    {
        $time = ['occurred_at' => $at];

        match ($category) {
            RetentionCategory::Occurrences => Occurrence::factory()->for(Issue::factory()->for($project))->create($time),
            RetentionCategory::Requests => RequestRun::factory()->for($project)->create($time),
            RetentionCategory::Queries => QueryRun::factory()->for($project)->create($time),
            RetentionCategory::Spans => DB::table('span_runs')->insert(['project_id' => $project->id, 'kind' => 'http', 'name' => 'GET', 'target' => '/', 'occurred_at' => $at]),
            RetentionCategory::WebVitals => DB::table('web_vitals')->insert(['project_id' => $project->id, 'route' => '/', 'lcp_us' => 1_000, 'occurred_at' => $at]),
            RetentionCategory::JobRuns => JobRun::factory()->for($project)->create(['created_at' => $at]),
            RetentionCategory::ScheduledTaskRuns => ScheduledTaskRun::factory()->for(ScheduledTask::factory()->for($project)->state(['name' => 'artisan '.uniqid()]), 'task')->create(['created_at' => $at]),
            RetentionCategory::CommandRuns => CommandRun::factory()->for($project)->create(['created_at' => $at]),
            RetentionCategory::Logs => LogEntry::factory()->for($project->logChannels()->sole(), 'channel')->create($time),
            RetentionCategory::Activity => Activity::factory()->forProject($project)->create($time),
            RetentionCategory::ActivityCounts => ActivityCount::factory()->forProject($project)->create(['hour' => $at]),
            RetentionCategory::HealthResults => DB::table('health_check_results')->insert(['health_check_id' => HealthCheck::factory()->for($project)->create(['slug' => 'r-'.uniqid()])->id, 'project_id' => $project->id, 'status' => 'ok', 'checked_at' => $at]),
            RetentionCategory::HealthOutages => DB::table('health_outages')->insert(['health_check_id' => HealthCheck::factory()->for($project)->create(['slug' => 'o-'.uniqid()])->id, 'project_id' => $project->id, 'status' => 'down', 'started_at' => $at->copy()->subHour(), 'ended_at' => $at]),
            RetentionCategory::DataSourceFetches => DB::table('data_source_fetches')->insert(['project_id' => $project->id, 'data_source_id' => DataSource::factory()->for($project)->create()->id, 'body' => '{}', 'fetched_at' => $at]),
            RetentionCategory::QueueSamples => DB::table('queue_samples')->insert(['project_id' => $project->id, 'host' => 'web', 'connection' => 'redis', 'queue' => 'default', 'size' => 1, 'sampled_at' => $at]),
            RetentionCategory::HostSamples => DB::table('host_samples')->insert(['project_agent_id' => ProjectAgent::factory()->for($project)->create()->id, 'sampled_at' => $at]),
            RetentionCategory::AlertSends => DB::table('alert_sends')->insert([
                'alert_rule_id' => AlertRule::query()->where('project_id', $project->id)->value('id') ?? AlertRule::factory()->for($project)->create()->id,
                'issue_id' => Issue::factory()->for($project)->create()->id,
                'notification_channel_id' => NotificationChannel::factory()->create(['team_id' => $project->team_id])->id,
                'status' => 'sent',
                'sent_at' => $at,
            ]),
            RetentionCategory::CustomAlerts => CustomAlert::factory()->create(['project_id' => $project->id, 'occurred_at' => $at]),
            RetentionCategory::AuditLog => AdminAuditEntry::query()->forceCreate(['action' => 'team_created', 'created_at' => $at]),
            RetentionCategory::Notifications => DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'releases', 'notifiable_type' => (new User())->getMorphClass(), 'notifiable_id' => User::factory()->create()->id, 'data' => '{}', 'created_at' => $at, 'updated_at' => $at]),
        };
    }

    public function retentionRows(RetentionCategory $category): int
    {
        return DB::table($category->table())->count();
    }

    public function storedDays(?int $teamId, ?int $projectId, RetentionCategory $category): int|false|null
    {
        $row = RetentionPolicy::query()->where(['team_id' => $teamId, 'project_id' => $projectId, 'job_class' => null, 'log_channel_id' => null, 'category' => $category->value])->first();

        return $row === null ? false : $row->days;
    }

    public function oldPolicy(array $row): void
    {
        DB::table('retention_policies')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, int|null> category => days for one scope */
    public function policiesOf(?int $teamId, ?int $projectId): array
    {
        return DB::table('retention_policies')->where('team_id', $teamId)->where('project_id', $projectId)
            ->whereNull('job_class')->whereNull('log_channel_id')->orderBy('category')->pluck('days', 'category')->all();
    }
}
