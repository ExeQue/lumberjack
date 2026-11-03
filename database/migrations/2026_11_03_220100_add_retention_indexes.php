<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the daily prune and the retention tables find old job runs and alert sends of one
 * project by index. Built concurrently so a large job_runs keeps taking writes meanwhile.
 */
return new class () extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS job_runs_project_id_created_at_index ON job_runs (project_id, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS alert_sends_issue_id_sent_at_index ON alert_sends (issue_id, sent_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS job_runs_project_id_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS alert_sends_issue_id_sent_at_index');
    }
};
