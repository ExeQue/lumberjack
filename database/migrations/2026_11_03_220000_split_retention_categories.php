<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every data type gets its own retention, and null days means forever. Rows of the four old
 * categories become rows of the types they covered, with the same days, so nothing changes
 * for what was set. Rows with neither team nor project, the application level, start empty.
 */
return new class () extends Migration {
    /** Old category => [type the row itself becomes, types that get a copy]. */
    private const array SPLITS = [
        'errors' => ['occurrences', []],
        'performance' => ['requests', ['queries', 'spans', 'web_vitals', 'health_results']],
        'jobs' => ['job_runs', ['scheduled_task_runs', 'command_runs']],
        'logs' => ['logs', []],
    ];

    /** Health outages followed errors; their own default is a year, so only a longer errors value carries over. */
    private const int OUTAGES_DEFAULT = 365;

    public function up(): void
    {
        Schema::table('retention_policies', function (Blueprint $table) {
            $table->string('category', 32)->change();
            $table->unsignedSmallInteger('days')->nullable()->change();
        });

        $now = now();

        foreach (self::SPLITS as $old => [$new, $copies]) {
            $rows = DB::table('retention_policies')->where('category', $old)->get();

            foreach ($rows as $row) {
                $extra = $copies;

                if ($old === 'errors' && $row->days > self::OUTAGES_DEFAULT) {
                    $extra[] = 'health_outages';
                }

                // A job class override is about job runs only.
                if ($row->job_class !== null || $row->log_channel_id !== null) {
                    $extra = [];
                }

                foreach ($extra as $category) {
                    DB::table('retention_policies')->insert([
                        'team_id' => $row->team_id,
                        'project_id' => $row->project_id,
                        'category' => $category,
                        'days' => $row->days,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('retention_policies')->where('category', $old)->update(['category' => $new]);
        }
    }

    public function down(): void
    {
        // The old schema has no application level and no forever.
        DB::table('retention_policies')->whereNull('team_id')->whereNull('project_id')->delete();
        DB::table('retention_policies')->whereNull('days')->update(['days' => 3650]);

        foreach (self::SPLITS as $old => [$new, $copies]) {
            if ($copies !== []) {
                // A scope that set only a copied type keeps its longest one as the old category.
                $scopes = DB::table('retention_policies')->whereIn('category', $copies)->whereNull('job_class')->whereNull('log_channel_id')->get()
                    ->groupBy(fn (stdClass $r): string => $r->team_id.'|'.$r->project_id);

                foreach ($scopes as $rows) {
                    $first = $rows->first();
                    $hasMain = DB::table('retention_policies')->where('category', $new)->where('team_id', $first->team_id)->where('project_id', $first->project_id)
                        ->whereNull('job_class')->whereNull('log_channel_id')->exists();

                    if (! $hasMain) {
                        DB::table('retention_policies')->where('id', $rows->sortByDesc('days')->first()->id)->update(['category' => $new]);
                    }
                }
            }

            DB::table('retention_policies')->where('category', $new)->update(['category' => $old]);
        }

        DB::table('retention_policies')->whereNotIn('category', array_keys(self::SPLITS))->delete();

        Schema::table('retention_policies', function (Blueprint $table) {
            $table->string('category', 16)->change();
            $table->unsignedSmallInteger('days')->nullable(false)->change();
        });
    }
};
