<?php

declare(strict_types=1);

use App\Domains\Projects\Models\ProjectAgent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPU, memory and disk of the hosts the agents run on.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('host_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProjectAgent::class)->constrained()->cascadeOnDelete();
            $table->float('cpu_load_1m')->nullable();
            $table->float('cpu_cores')->nullable();
            $table->unsignedBigInteger('memory_used_bytes')->nullable();
            $table->unsignedBigInteger('memory_total_bytes')->nullable();
            $table->unsignedBigInteger('disk_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();
            $table->unsignedBigInteger('php_memory_peak_bytes')->nullable();
            $table->timestamp('sampled_at');

            $table->index(['project_agent_id', 'sampled_at']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_samples');
    }
};
