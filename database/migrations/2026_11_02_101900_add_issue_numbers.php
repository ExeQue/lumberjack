<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('issue_prefix', 8)->nullable()->after('slug');
            $table->unsignedInteger('last_issue_number')->default(0)->after('issue_prefix');
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('project_id');
        });

        // Existing issues are numbered per project in the order they were first seen.
        foreach (DB::table('projects')->select(['id', 'slug'])->get() as $project) {
            $number = 0;

            foreach (DB::table('issues')->where('project_id', $project->id)->orderBy('first_seen_at')->orderBy('id')->pluck('id') as $id) {
                DB::table('issues')->where('id', $id)->update(['number' => ++$number]);
            }

            DB::table('projects')->where('id', $project->id)->update([
                'issue_prefix' => mb_substr(strtoupper((string) preg_replace('/[^a-z0-9]/i', '', (string) $project->slug)), 0, 8) ?: 'ISSUE',
                'last_issue_number' => $number,
            ]);
        }

        Schema::table('issues', function (Blueprint $table) {
            $table->unique(['project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'number']);
            $table->dropColumn('number');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['issue_prefix', 'last_issue_number']);
        });
    }
};
