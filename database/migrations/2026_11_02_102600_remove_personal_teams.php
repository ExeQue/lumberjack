<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lumberjack has no personal teams: people join a team by invitation. A personal team
 * nobody made a project in is removed; one with projects stays, as an ordinary team.
 */
return new class () extends Migration {
    public function up(): void
    {
        $empty = DB::table('teams')->where('personal', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('projects')->whereColumn('projects.team_id', 'teams.id'))
            ->pluck('id');

        // Whoever had one of these as their current team moves to another of theirs.
        foreach (DB::table('users')->whereIn('current_team_id', $empty)->get(['id']) as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'current_team_id' => DB::table('team_user')->where('user_id', $user->id)->whereNotIn('team_id', $empty)->value('team_id'),
            ]);
        }

        DB::table('teams')->whereIn('id', $empty)->delete();

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('personal');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->boolean('personal')->default(false);
        });
    }
};
