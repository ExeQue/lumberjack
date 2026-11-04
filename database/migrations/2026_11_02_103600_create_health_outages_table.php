<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The periods a health check was degraded or down.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('health_outages', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(HealthCheck::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('first_message', 1000)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['health_check_id', 'started_at']);
            $table->index(['project_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_outages');
    }
};
