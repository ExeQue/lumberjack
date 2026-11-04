<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long each category of data is kept, for the application, a team or a project; no days means forever.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Team::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Project::class)->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('job_class')->nullable();
            $table->unsignedBigInteger('log_channel_id')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'category']);
            $table->index(['team_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
