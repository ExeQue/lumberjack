<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('data_source_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('environment', 64);
            $table->string('url', 2000);
            $table->text('pull_token')->nullable();
            $table->text('pull_token_previous')->nullable();
            $table->boolean('uses_previous')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['project_id', 'environment']);
        });

        Schema::table('data_sources', function (Blueprint $table): void {
            $table->string('key', 100)->nullable();
            $table->string('environment', 64)->default('');
            $table->string('shape', 16)->nullable();
            $table->unsignedInteger('cache_seconds')->nullable();
            $table->foreignIdFor(DataSourceConnection::class)->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->unique(['project_id', 'environment', 'key']);
        });

        Schema::create('data_source_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(DataSource::class)->constrained()->cascadeOnDelete();
            $table->string('period', 8);
            $table->json('data')->nullable();
            $table->boolean('has_data')->default(false);
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('next_fetch_at')->nullable()->index();
            $table->unique(['data_source_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_windows');

        Schema::table('data_sources', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'environment', 'key']);
            $table->dropConstrainedForeignIdFor(DataSourceConnection::class);
            $table->dropColumn(['key', 'environment', 'shape', 'cache_seconds', 'archived_at']);
        });

        Schema::dropIfExists('data_source_connections');
    }
};
