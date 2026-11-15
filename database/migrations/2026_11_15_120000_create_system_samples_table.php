<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPU, memory and disk of the server Lumberjack itself runs on, a sample a minute.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('system_samples', function (Blueprint $table) {
            $table->id();
            $table->float('cpu_load_1m')->nullable();
            $table->float('cpu_cores')->nullable();
            $table->unsignedBigInteger('memory_used_bytes')->nullable();
            $table->unsignedBigInteger('memory_total_bytes')->nullable();
            $table->unsignedBigInteger('disk_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();
            $table->timestamp('sampled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_samples');
    }
};
