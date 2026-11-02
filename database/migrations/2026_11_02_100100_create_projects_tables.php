<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->char('token_hash', 64)->unique();
            $table->char('token_last_four', 4);
            $table->string('public_key', 40)->unique();
            $table->json('allowed_origins')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'slug']);
        });

        Schema::create('log_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('project_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->string('version', 32)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['project_id', 'host']);
        });

        Schema::create('project_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('repository');
            $table->text('token');
            $table->date('token_expires_at')->nullable();
            $table->json('labels')->nullable();
            $table->boolean('allow_public')->default(false);
            $table->timestamps();
        });

        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Team::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Project::class)->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 16);
            $table->string('job_class')->nullable();
            $table->unsignedBigInteger('log_channel_id')->nullable();
            $table->unsignedSmallInteger('days');
            $table->timestamps();

            $table->index(['project_id', 'category']);
            $table->index(['team_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('project_repositories');
        Schema::dropIfExists('project_agents');
        Schema::dropIfExists('log_channels');
        Schema::dropIfExists('projects');
    }
};
