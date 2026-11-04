<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each result of a health check.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('health_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(HealthCheck::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('host')->default('');
            $table->string('status', 16);
            $table->string('message', 1000)->nullable();
            $table->json('metrics')->nullable();
            $table->unsignedBigInteger('duration_us')->nullable();
            $table->timestamp('checked_at');

            $table->index(['health_check_id', 'checked_at']);
            $table->index(['project_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_check_results');
    }
};
