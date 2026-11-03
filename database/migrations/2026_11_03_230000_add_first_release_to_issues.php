<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The release and environment of an issue's first occurrence, kept on the issue, so the
 * releases page need not find the earliest occurrence of every new issue on each visit.
 * Existing issues take them from their earliest occurrence still kept.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->string('first_release', 64)->nullable();
            $table->string('first_environment', 64)->nullable();
        });

        DB::statement('
            UPDATE issues SET first_release = f.release, first_environment = f.environment
            FROM (
                SELECT DISTINCT ON (issue_id) issue_id, release, environment
                FROM occurrences ORDER BY issue_id, occurred_at, id
            ) f
            WHERE f.issue_id = issues.id
        ');
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropColumn(['first_release', 'first_environment']);
        });
    }
};
