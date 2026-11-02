<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // High volume: no foreign keys, as the other run tables.
        Schema::create('command_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->uuid('run_uuid');
            $table->string('trace_id', 64)->nullable();
            $table->string('environment', 64)->nullable();
            $table->string('host')->nullable();
            $table->string('name');
            $table->json('arguments')->nullable();
            $table->json('options')->nullable();
            $table->string('status', 16);
            $table->smallInteger('exit_code')->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedBigInteger('duration_us')->nullable();
            $table->timestamps(3);

            $table->unique(['project_id', 'run_uuid']);
            $table->index(['project_id', 'name', 'started_at']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_runs');
    }
};
