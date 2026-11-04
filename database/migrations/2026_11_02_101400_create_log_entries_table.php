<?php

declare(strict_types=1);

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log lines, searchable by full text. High volume: no foreign keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->foreignIdFor(LogChannel::class);
            $table->string('sent_channel', 64)->nullable();
            $table->unsignedSmallInteger('level');
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('environment', 64)->nullable();
            $table->string('release', 64)->nullable();
            $table->string('host')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->index(['project_id', 'log_channel_id', 'occurred_at']);
            $table->index(['project_id', 'occurred_at']);
            $table->fullText('message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
    }
};
