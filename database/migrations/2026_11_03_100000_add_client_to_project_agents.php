<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An agent says what it is: the SDK it serves, itself, and the runtime and framework
 * underneath, each with a version. Agents so far were all the Laravel SDK.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('project_agents', function (Blueprint $table) {
            $table->renameColumn('version', 'sdk_version');
        });

        Schema::table('project_agents', function (Blueprint $table) {
            $table->string('sdk', 64)->nullable()->after('name');
            $table->string('agent', 64)->nullable()->after('sdk_version');
            $table->string('agent_version', 32)->nullable()->after('agent');
            $table->string('runtime', 32)->nullable()->after('agent_version');
            $table->string('runtime_version', 32)->nullable()->after('runtime');
            $table->string('framework', 32)->nullable()->after('runtime_version');
            $table->string('framework_version', 32)->nullable()->after('framework');
        });

        DB::table('project_agents')->whereNotNull('sdk_version')->update(['sdk' => 'lumberjack.php.laravel']);
    }

    public function down(): void
    {
        Schema::table('project_agents', function (Blueprint $table) {
            $table->dropColumn(['sdk', 'agent', 'agent_version', 'runtime', 'runtime_version', 'framework', 'framework_version']);
        });

        Schema::table('project_agents', function (Blueprint $table) {
            $table->renameColumn('sdk_version', 'version');
        });
    }
};
