<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every request counted per minute and route, sampled or not. No foreign keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('request_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            // Empty, not null, so the upsert key matches a batch without an environment.
            $table->string('environment', 64)->default('');
            $table->timestamp('minute');
            $table->string('method', 10);
            $table->string('route', 255);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedBigInteger('count');
            $table->unsignedBigInteger('duration_us');
            // Empty, not null, so the upsert key matches counts sent without one.
            $table->string('domain')->default('');

            $table->unique(['project_id', 'environment', 'minute', 'method', 'domain', 'route', 'status_code']);
            $table->index(['project_id', 'minute']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_counts');
    }
};
