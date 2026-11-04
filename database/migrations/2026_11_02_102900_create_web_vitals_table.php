<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core Web Vitals reported by browsers. High volume: no foreign keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('web_vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('environment', 64)->nullable();
            $table->string('release', 64)->nullable();
            $table->string('route', 255);
            $table->unsignedBigInteger('lcp_us')->nullable();
            $table->unsignedBigInteger('inp_us')->nullable();
            $table->unsignedBigInteger('ttfb_us')->nullable();
            $table->unsignedBigInteger('fcp_us')->nullable();
            $table->float('cls')->nullable();
            $table->timestamp('occurred_at', 3);
            $table->string('device', 16)->nullable();

            $table->index(['project_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_vitals');
    }
};
