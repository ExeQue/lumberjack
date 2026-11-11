<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance windows, planned or reported by the app, with the flag on what came in
 * during one, the project's two overrun settings and the team rule that tells of an overrun.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            // Empty covers every environment of the project.
            $table->string('environment', 64)->default('');
            $table->string('source', 16);
            $table->string('message', 500)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('expected_end_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('overrun_alerted_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'starts_at']);
            $table->index(['ended_at', 'expected_end_at']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedSmallInteger('maintenance_expected_minutes')->default(30);
            $table->unsignedSmallInteger('maintenance_repeat_minutes')->default(30);
        });

        Schema::table('occurrences', function (Blueprint $table): void {
            $table->boolean('under_maintenance')->default(false);
        });

        Schema::table('issues', function (Blueprint $table): void {
            $table->boolean('under_maintenance')->default(false);
        });

        $now = now();

        DB::table('alert_rules')->insertOrIgnore(DB::table('teams')->pluck('id')->map(fn (int $team): array => [
            'team_id' => $team,
            'type' => 'maintenance_overrun',
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'maintenance_overrun')->delete();

        Schema::table('issues', function (Blueprint $table): void {
            $table->dropColumn('under_maintenance');
        });

        Schema::table('occurrences', function (Blueprint $table): void {
            $table->dropColumn('under_maintenance');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['maintenance_expected_minutes', 'maintenance_repeat_minutes']);
        });

        Schema::dropIfExists('maintenance_windows');
    }
};
