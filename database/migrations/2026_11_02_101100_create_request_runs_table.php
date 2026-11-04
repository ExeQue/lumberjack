<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sampled HTTP requests. High volume: no foreign keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('request_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('environment', 64)->nullable();
            $table->string('release', 64)->nullable();
            $table->string('host')->nullable();
            $table->string('method', 10);
            $table->string('route')->nullable();
            $table->text('url');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_us');
            $table->unsignedBigInteger('memory_peak')->nullable();
            $table->timestamp('occurred_at', 3);
            $table->float('sample_rate')->default(1);
            $table->boolean('sampled')->default(true);
            $table->string('domain')->nullable();
            $table->string('route_name')->nullable();

            $table->index(['project_id', 'occurred_at']);
            $table->index(['project_id', 'route', 'occurred_at']);
            $table->index(['project_id', 'domain', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_runs');
    }
};
