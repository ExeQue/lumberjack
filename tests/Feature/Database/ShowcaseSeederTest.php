<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ShowcaseSeeder;
use Database\Seeders\Support\ShowcaseCatalog;
use Illuminate\Support\Facades\DB;

covers(ShowcaseSeeder::class);

describe('the showcase seed', function () {
    // One seed for every check: it writes several hundred thousand rows.
    it('fills every project consistently and adds nothing when run again', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 14:30:00'));
        $this->seed(DatabaseSeeder::class);
        $now = now()->toImmutable();

        $projects = Project::query()->whereIn('slug', array_column(ShowcaseCatalog::projects(), 'slug'))->get();
        expect($projects)->toHaveCount(count(ShowcaseCatalog::projects()));

        // Every project has traffic or background work, health checks and issues.
        foreach ($projects as $project) {
            $traffic = DB::table('request_counts')->where('project_id', $project->id)->exists() || DB::table('job_runs')->where('project_id', $project->id)->exists();

            expect($traffic)->toBeTrue("{$project->slug} has no traffic")
                ->and(HealthCheck::query()->where('project_id', $project->id)->exists())->toBeTrue("{$project->slug} has no health checks")
                ->and(Issue::query()->where('project_id', $project->id)->exists())->toBeTrue("{$project->slug} has no issues")
                ->and(DB::table('project_agents')->where('project_id', $project->id)->exists())->toBeTrue("{$project->slug} has no agents");
        }

        // The counters on each issue are what its occurrences say, as RecordOccurrenceAction keeps them.
        $mismatched = DB::select("
            SELECT i.id FROM issues i
            JOIN (SELECT issue_id, COUNT(*) AS n, COUNT(DISTINCT user_id) AS users, MIN(occurred_at) AS first, MAX(occurred_at) AS last FROM occurrences GROUP BY issue_id) o ON o.issue_id = i.id
            WHERE i.occurrences_count <> o.n OR i.users_count <> o.users OR date_trunc('second', i.first_seen_at) <> date_trunc('second', o.first) OR date_trunc('second', i.last_seen_at) <> date_trunc('second', o.last)");
        expect($mismatched)->toBe([])
            ->and(Issue::query()->whereDoesntHave('occurrences')->count())->toBe(0);

        // Numbered one after the other in each project, fingerprints unique.
        foreach ($projects as $project) {
            $numbers = Issue::query()->where('project_id', $project->id)->orderBy('number')->pluck('number')->all();

            expect($numbers)->toBe(range(1, count($numbers)))
                ->and($project->refresh()->last_issue_number)->toBe(count($numbers));
        }

        expect(DB::table('issues')->select('project_id', 'fingerprint')->groupBy('project_id', 'fingerprint')->havingRaw('COUNT(*) > 1')->count())->toBe(0);

        // Failed runs point at the occurrence they made.
        expect(DB::table('job_runs')->where('status', 'failed')->whereNotNull('occurrence_id')->exists())->toBeTrue()
            ->and(DB::table('scheduled_task_runs')->whereIn('status', ['failed', 'missed'])->whereNull('occurrence_id')->count())->toBe(0);

        // Nothing happened after now.
        foreach ([
            'request_counts' => 'minute', 'request_runs' => 'occurred_at', 'query_runs' => 'occurred_at', 'span_runs' => 'occurred_at',
            'web_vitals' => 'occurred_at', 'occurrences' => 'occurred_at', 'issue_events' => 'occurred_at', 'issue_notes' => 'created_at',
            'job_runs' => 'started_at', 'queue_samples' => 'sampled_at', 'scheduled_task_runs' => 'started_at', 'command_runs' => 'started_at',
            'health_check_results' => 'checked_at', 'health_outages' => 'started_at', 'host_samples' => 'sampled_at', 'log_entries' => 'occurred_at',
            'alert_sends' => 'sent_at',
        ] as $table => $column) {
            expect(DB::table($table)->where($column, '>', $now)->count())->toBe(0, "{$table} has rows after now")
                ->and(DB::table($table)->exists())->toBeTrue("{$table} is empty");
        }

        // The showcase people can sign in; the local seed is still there beside them.
        $person = User::query()->where('email', ShowcaseCatalog::teams()['Northwind Labs'][0]['email'])->sole();
        expect(Hash::check(ShowcaseCatalog::PASSWORD, $person->password))->toBeTrue()
            ->and(Project::query()->where('slug', 'demo')->exists())->toBeTrue();

        // Run again on the filled database, it adds nothing.
        $tables = ['users', 'teams', 'projects', 'issues', 'occurrences', 'request_counts', 'job_runs', 'health_checks', 'log_entries', 'screens', 'devices', 'notification_channels', 'retention_policies'];
        $before = array_map(fn (string $table): int => DB::table($table)->count(), array_combine($tables, $tables));
        $this->seed(ShowcaseSeeder::class);

        expect(array_map(fn (string $table): int => DB::table($table)->count(), array_combine($tables, $tables)))->toBe($before);
    });
});
