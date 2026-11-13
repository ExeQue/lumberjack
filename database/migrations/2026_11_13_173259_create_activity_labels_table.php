<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The names a team gives a project's activity groups and activities, shown instead of
 * what the app records.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('activity_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('group', 64);
            // Empty names the group itself, so the unique key holds for it too.
            $table->string('name', 128)->default('');
            $table->string('label', 100);
            $table->timestamps();

            $table->unique(['project_id', 'group', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_labels');
    }
};
