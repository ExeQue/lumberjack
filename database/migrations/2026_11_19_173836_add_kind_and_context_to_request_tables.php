<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of request a run or count was (an Inertia partial reload, a Livewire update),
 * and on a run what the SDK knows about it beyond the route.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('request_runs', function (Blueprint $table) {
            // Null for an ordinary request, and for every request of an older SDK.
            $table->string('kind', 32)->nullable();
            $table->jsonb('context')->nullable();
        });

        Schema::table('request_counts', function (Blueprint $table) {
            // Empty, not null, so the upsert key matches counts sent without one.
            $table->string('kind', 32)->default('');

            $table->dropUnique(['project_id', 'environment', 'minute', 'method', 'domain', 'route', 'status_code']);
            $table->unique(['project_id', 'environment', 'minute', 'method', 'domain', 'route', 'kind', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::table('request_counts', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'environment', 'minute', 'method', 'domain', 'route', 'kind', 'status_code']);
            $table->unique(['project_id', 'environment', 'minute', 'method', 'domain', 'route', 'status_code']);
            $table->dropColumn('kind');
        });

        Schema::table('request_runs', function (Blueprint $table) {
            $table->dropColumn(['kind', 'context']);
        });
    }
};
