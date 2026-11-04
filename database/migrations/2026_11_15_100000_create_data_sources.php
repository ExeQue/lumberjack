<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External JSON data sources of a project, fetched by Lumberjack for screen widgets: the
 * source with its latest raw body, and the parsed body of each fetch that fits the
 * history, which charts read (pruned by retention).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('url', 2000);
            // Encrypted: header values are often secrets.
            $table->text('headers')->nullable();
            $table->unsignedSmallInteger('interval_minutes');
            $table->unsignedSmallInteger('timeout_s');
            $table->timestamp('next_fetch_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->text('last_body')->nullable();
            $table->timestamps();

            $table->index('next_fetch_at');
        });

        Schema::create('data_source_fetches', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(DataSource::class)->constrained()->cascadeOnDelete();
            $table->jsonb('body');
            $table->timestamp('fetched_at');

            $table->index(['data_source_id', 'fetched_at']);
            $table->index(['project_id', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_fetches');
        Schema::dropIfExists('data_sources');
    }
};
