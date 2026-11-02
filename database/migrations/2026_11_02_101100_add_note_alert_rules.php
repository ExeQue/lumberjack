<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Every project gets a note rule, off, as new projects do.
     */
    public function up(): void
    {
        $now = now();
        $missing = DB::table('projects')
            ->whereNotIn('id', DB::table('alert_rules')->where('type', 'note')->select('project_id'))
            ->pluck('id');

        foreach ($missing->chunk(500) as $chunk) {
            DB::table('alert_rules')->insert($chunk->map(fn (int $id): array => [
                'project_id' => $id, 'type' => 'note', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'note')->delete();
    }
};
