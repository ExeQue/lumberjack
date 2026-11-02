<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('project_agents', 'name')) {
            Schema::table('project_agents', function (Blueprint $table) {
                // What the agent calls itself (--name), so several on one host are told apart.
                $table->string('name', 64)->default('')->after('host');
            });
        }

        // The new key first: the project foreign key needs an index the whole time.
        Schema::table('project_agents', function (Blueprint $table) {
            $table->unique(['project_id', 'host', 'name']);
        });

        Schema::table('project_agents', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::table('project_agents', function (Blueprint $table) {
            $table->unique(['project_id', 'host']);
        });

        Schema::table('project_agents', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'host', 'name']);
            $table->dropColumn('name');
        });
    }
};
