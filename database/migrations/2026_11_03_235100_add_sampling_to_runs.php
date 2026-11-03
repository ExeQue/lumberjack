<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate a run was sampled at, and whether it was picked or only kept for being slow or
 * failed. A constant default, so Postgres adds the columns without rewriting the tables.
 */
return new class () extends Migration {
    public const array TABLES = ['request_runs', 'query_runs', 'job_runs', 'command_runs', 'scheduled_task_runs'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->float('sample_rate')->default(1);
                $table->boolean('sampled')->default(true);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['sample_rate', 'sampled']);
            });
        }
    }
};
