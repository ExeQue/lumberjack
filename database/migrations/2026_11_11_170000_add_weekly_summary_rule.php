<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly summary rule for every team, off, with when each rule last sent one.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->timestamp('last_sent_at')->nullable();
        });

        $now = now();

        DB::table('alert_rules')->insertOrIgnore(DB::table('teams')->pluck('id')->map(fn (int $team): array => [
            'team_id' => $team,
            'type' => 'weekly_summary',
            'enabled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'weekly_summary')->delete();

        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->dropColumn('last_sent_at');
        });
    }
};
