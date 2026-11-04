<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue sizes and ages as the agents sample them.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('queue_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->string('connection', 64);
            $table->string('queue');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('oldest_age_s')->nullable();
            $table->timestamp('sampled_at');
            $table->unsignedInteger('workers')->nullable();

            $table->index(['project_id', 'queue', 'sampled_at']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_samples');
    }
};
