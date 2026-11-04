<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bodies a data source returned, kept for history.
 */
return new class () extends Migration {
    public function up(): void
    {
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
    }
};
