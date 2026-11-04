<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alert rules, owned by either a team (its default) or a project (an override).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->boolean('enabled')->default(true);
            $table->json('environments')->nullable();
            $table->timestamps();
            $table->json('settings')->nullable();
            $table->foreignIdFor(Team::class)->nullable()->constrained()->cascadeOnDelete();

            $table->unique(['project_id', 'type']);
            $table->unique(['team_id', 'type']);
        });

        DB::statement('ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_one_owner CHECK (num_nonnulls(team_id, project_id) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
