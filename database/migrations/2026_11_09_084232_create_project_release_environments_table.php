<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which environments have run which release, for the new release alert. Existing teams
 * get its rule here; a new team gets it from CreateTeamAlertRulesAction.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('project_release_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('release');
            $table->string('environment', 64)->default('');
            $table->timestamp('first_seen_at');
            $table->unique(['project_id', 'environment', 'release']);
        });

        $now = now();

        DB::table('alert_rules')->insertOrIgnore(DB::table('teams')->pluck('id')->map(fn (int $team): array => [
            'team_id' => $team,
            'type' => 'new_release',
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'new_release')->delete();
        Schema::dropIfExists('project_release_environments');
    }
};
