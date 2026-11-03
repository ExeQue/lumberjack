<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public stats: an unguessable key that serves the project's aggregates on the metrics
 * API without a token. Null while the project has not opted in.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('stats_key', 64)->nullable()->unique();
            $table->boolean('stats_include_titles')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['stats_key', 'stats_include_titles']);
        });
    }
};
