<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('issue_notes', function (Blueprint $table) {
            // The status the issue was given with this note, e.g. a comment on resolving.
            $table->string('status', 16)->nullable()->after('body');
        });

        // Every project gets a status-change rule, off, as new projects do.
        $now = now();
        $missing = DB::table('projects')
            ->whereNotIn('id', DB::table('alert_rules')->where('type', 'status_change')->select('project_id'))
            ->pluck('id');

        foreach ($missing->chunk(500) as $chunk) {
            DB::table('alert_rules')->insert($chunk->map(fn (int $id): array => [
                'project_id' => $id, 'type' => 'status_change', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'status_change')->delete();

        Schema::table('issue_notes', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
