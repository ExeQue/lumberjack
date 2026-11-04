<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SDKs and agents that report for a project, one row per host and agent name.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('project_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->string('sdk_version', 32)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
            // What the agent calls itself (--name), so several on one host are told apart.
            $table->string('name', 64)->default('');
            $table->string('sdk', 64)->nullable();
            $table->string('agent', 64)->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->string('runtime', 32)->nullable();
            $table->string('runtime_version', 32)->nullable();
            $table->string('framework', 32)->nullable();
            $table->string('framework_version', 32)->nullable();

            $table->unique(['project_id', 'host', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_agents');
    }
};
