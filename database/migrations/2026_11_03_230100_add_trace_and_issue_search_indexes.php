<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Lets a trace find its command and scheduled runs, and an issue search match titles by
 * trigram instead of scanning every issue. Built concurrently so the tables keep taking
 * writes. Without the pg_trgm extension (it is trusted, so a database owner may create
 * it) the search keeps working, only unindexed.
 *
 * ponytail: the title only; a trigram index on message would add thousands of entries on
 * every occurrence's update of the issue row.
 */
return new class () extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS command_runs_project_id_trace_id_index ON command_runs (project_id, trace_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS scheduled_task_runs_project_id_trace_id_index ON scheduled_task_runs (project_id, trace_id)');

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (QueryException $e) {
            report($e);

            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS issues_title_trgm_index ON issues USING gin (title gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS command_runs_project_id_trace_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS scheduled_task_runs_project_id_trace_id_index');
        // The extension stays: dropping it could break anything else that came to rely on it.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS issues_title_trgm_index');
    }
};
