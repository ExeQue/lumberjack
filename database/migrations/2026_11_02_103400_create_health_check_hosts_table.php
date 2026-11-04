<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A health check's state per host, with its streaks.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('health_check_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(HealthCheck::class)->constrained()->cascadeOnDelete();
            $table->string('host')->default('');
            $table->string('status', 16)->nullable();
            $table->unsignedInteger('ok_streak')->default(0);
            $table->unsignedInteger('fail_streak')->default(0);
            $table->string('last_status', 16)->nullable();
            $table->string('last_message', 1000)->nullable();
            $table->unsignedBigInteger('last_duration_us')->nullable();
            $table->json('last_metrics')->nullable();
            $table->timestamp('last_result_at')->nullable();
            $table->unsignedInteger('interval_s')->nullable();
            $table->timestamps();
            $table->unsignedInteger('failed_streak')->default(0);
            $table->string('recovering_from', 16)->nullable();
            $table->unsignedSmallInteger('interval_minutes')->nullable();

            $table->unique(['health_check_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_check_hosts');
    }
};
