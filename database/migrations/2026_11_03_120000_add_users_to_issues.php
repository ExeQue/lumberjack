<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('occurrences', function (Blueprint $table) {
            // The id of the signed-in user the error happened to, from its context.
            $table->string('user_id', 191)->nullable();
            $table->index(['issue_id', 'user_id']);
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->unsignedInteger('users_count')->default(0);
        });

        DB::statement("UPDATE occurrences SET user_id = LEFT(context->'user'->>'id', 191) WHERE context->'user'->>'id' IS NOT NULL");
        DB::statement('UPDATE issues SET users_count = c.n FROM (SELECT issue_id, COUNT(DISTINCT user_id) AS n FROM occurrences WHERE user_id IS NOT NULL GROUP BY issue_id) c WHERE c.issue_id = issues.id');
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('users_count');
        });

        Schema::table('occurrences', function (Blueprint $table) {
            $table->dropIndex(['issue_id', 'user_id']);
            $table->dropColumn('user_id');
        });
    }
};
