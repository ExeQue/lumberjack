<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every release a project's server has reported, and when it first did. */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('project_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('release');
            $table->timestamp('first_seen_at');
            $table->unique(['project_id', 'release']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_releases');
    }
};
