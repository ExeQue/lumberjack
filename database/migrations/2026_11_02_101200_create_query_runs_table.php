<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sampled database queries. High volume: no foreign keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('query_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('environment', 64)->nullable();
            $table->string('connection', 64);
            $table->text('sql');
            $table->char('sql_hash', 64);
            $table->unsignedInteger('duration_us');
            $table->timestamp('occurred_at', 3);
            // The line in the app's code that ran the query, e.g. app/Models/Order.php:42.
            $table->string('location')->nullable();
            $table->float('sample_rate')->default(1);
            $table->boolean('sampled')->default(true);

            $table->index(['project_id', 'occurred_at']);
            $table->index(['project_id', 'sql_hash', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_runs');
    }
};
