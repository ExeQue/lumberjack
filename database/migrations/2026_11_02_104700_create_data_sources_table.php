<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External URLs Lumberjack fetches on an interval, for screen widgets.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('url', 2000);
            // Encrypted: header values are often secrets.
            $table->text('headers')->nullable();
            $table->unsignedSmallInteger('interval_minutes');
            $table->unsignedSmallInteger('timeout_s');
            $table->timestamp('next_fetch_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->text('last_body')->nullable();
            $table->timestamps();
            $table->string('format', 20)->default('json');

            $table->index('next_fetch_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
